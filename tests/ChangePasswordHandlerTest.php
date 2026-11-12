<?php

namespace Restruct\MFABundle\Tests;

use OTPHP\TOTP;
use ReflectionClass;
use Restruct\MFABundle\Authenticator\ChangePasswordHandler;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\MFA\Authenticator\ChangePasswordHandler as MFAChangePasswordHandler;
use SilverStripe\MFA\Authenticator\MemberAuthenticator;
use SilverStripe\MFA\Service\EncryptionAdapterInterface;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\ChangePasswordHandler as FrameworkChangePasswordHandler;

/**
 * Regression tests for CVE-2026-55186: the password reset flow of silverstripe/mfa 5 let a member
 * with MFA set up change their password and get logged in without completing MFA, by posting the
 * change password form directly.
 *
 * Each test walks the real reset flow: the reset link (?m=&t=) puts the member's AutoLoginHash in
 * the session, then the form is posted to the handler's doChangePassword() action. Only the
 * completed MFA check is simulated, by setting the session values verifyMFACheck() would set.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class ChangePasswordHandlerTest extends FunctionalTest
{
    protected static $fixture_file = 'ChangePasswordHandlerTest.yml';

    private const NEW_PASSWORD = 'Changed-Passw0rd-1234';

    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private const MFA_KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private ?string $previousKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        # Decrypting a TOTP secret needs a key; set a test key for this test only and restore
        # whatever was there afterwards.
        $this->previousKey = Environment::getEnv('SS_MFA_SECRET_KEY') ?: null;
        Environment::setEnv('SS_MFA_SECRET_KEY', self::MFA_KEY);
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SS_MFA_SECRET_KEY', (string) $this->previousKey);
        parent::tearDown();
    }

    public function testHandlerIsReplacedThroughTheInjector()
    {
        $handler = Injector::inst()->create(
            MFAChangePasswordHandler::class,
            'Security/changepassword',
            new MemberAuthenticator()
        );
        $this->assertInstanceOf(ChangePasswordHandler::class, $handler);
    }

    public function testPostWithoutMfaCheckDoesNotChangePasswordOrLogIn()
    {
        $member = $this->objFromFixture(Member::class, 'alice');
        $passwordBefore = $member->Password;

        $this->openResetLink($member);
        $response = $this->postNewPassword();

        # Sent back to changepassword(), which shows the MFA check (FunctionalTest follows the redirect)
        $this->assertStringContainsString('id="mfa-app"', $response->getBody(), 'MFA screen should be displayed');
        $this->assertSame($passwordBefore, Member::get()->byID($member->ID)->Password, 'password must not change');
        $this->assertEmpty($this->session()->get('loggedInAs'), 'member must not be logged in');
    }

    public function testPostAfterMfaCheckForThisMemberChangesPasswordAndLogsIn()
    {
        $member = $this->objFromFixture(Member::class, 'alice');
        $passwordBefore = $member->Password;

        $this->openResetLink($member);
        $this->markMfaVerifiedFor($member);
        $this->postNewPassword();

        $this->assertNotSame($passwordBefore, Member::get()->byID($member->ID)->Password, 'password must change');
        $this->assertEquals($member->ID, $this->session()->get('loggedInAs'));
    }

    public function testMfaCheckCompletedForAnotherMemberDoesNotCount()
    {
        $alice = $this->objFromFixture(Member::class, 'alice');
        $bob = $this->objFromFixture(Member::class, 'bob');
        $passwordBefore = $bob->Password;

        # MFA completed for alice's reset, then bob's reset link opened in the same session
        $this->openResetLink($alice);
        $this->markMfaVerifiedFor($alice);
        $this->openResetLink($bob);
        $response = $this->postNewPassword();

        $this->assertStringContainsString('id="mfa-app"', $response->getBody(), 'MFA screen should be displayed');
        $this->assertSame($passwordBefore, Member::get()->byID($bob->ID)->Password, 'password must not change');
        $this->assertEmpty($this->session()->get('loggedInAs'), 'member must not be logged in');
        $this->assertEmpty(
            $this->session()->get(MFAChangePasswordHandler::MFA_VERIFIED_ON_CHANGE_PASSWORD),
            'the check made for another member is forgotten, so upstream asks again'
        );
    }

    public function testMfaCheckCompletedForAnotherMemberIsAskedAgainOnTheForm()
    {
        $alice = $this->objFromFixture(Member::class, 'alice');
        $bob = $this->objFromFixture(Member::class, 'bob');

        $this->openResetLink($alice);
        $this->markMfaVerifiedFor($alice);
        $this->openResetLink($bob);
        $body = $this->get('Security/changepassword')->getBody();

        $this->assertStringContainsString('id="mfa-app"', $body, 'MFA screen should be displayed');
        $this->assertStringNotContainsString('NewPassword1', $body, 'password form should not be shown');
    }

    public function testRealMfaCheckThroughTheEndpointsAllowsTheReset()
    {
        $member = $this->objFromFixture(Member::class, 'alice');
        $passwordBefore = $member->Password;
        $this->giveTotpSecret($member);

        $this->openResetLink($member);
        # The MFA app's own calls: start the TOTP check, then send a code from the authenticator
        $this->assertSame(200, $this->get('Security/changepassword/mfa/login/totp')->getStatusCode());
        $verify = $this->post(
            'Security/changepassword/mfa/login/totp',
            [],
            ['Content-Type' => 'application/json'],
            null,
            json_encode(['code' => $this->totpCode()])
        );
        $this->assertSame(200, $verify->getStatusCode(), $verify->getBody());
        $this->assertEquals(
            $member->ID,
            $this->session()->get(ChangePasswordHandler::MFA_VERIFIED_MEMBER_ID),
            'the completed check is recorded for this member'
        );

        $this->postNewPassword();

        $this->assertNotSame($passwordBefore, Member::get()->byID($member->ID)->Password, 'password must change');
        $this->assertEquals($member->ID, $this->session()->get('loggedInAs'));
    }

    public function testMemberWithoutMfaCanStillResetTheirPassword()
    {
        $member = $this->objFromFixture(Member::class, 'carol');
        $passwordBefore = $member->Password;

        $this->openResetLink($member);
        $this->postNewPassword();

        $this->assertNotSame($passwordBefore, Member::get()->byID($member->ID)->Password, 'password must change');
        $this->assertEquals($member->ID, $this->session()->get('loggedInAs'));
    }

    /**
     * Follow a reset link the way the email does, so the framework stores the AutoLoginHash itself.
     */
    private function openResetLink(Member $member): void
    {
        # Silverstripe 6 keeps per-request flags in statics that survive between the simulated
        # requests of one test process; reset them as framework 6's own ChangePasswordHandlerTest does.
        # Silverstripe 5 has no such statics.
        $handler = new ReflectionClass(FrameworkChangePasswordHandler::class);
        foreach (['tempHashAlreadyGenerated', 'tempHashAlreadyProcessed'] as $flag) {
            if ($handler->hasProperty($flag)) {
                $handler->setStaticPropertyValue($flag, false);
            }
        }
        $token = $member->generateAutologinTokenAndStoreHash();
        $this->get("Security/changepassword?m={$member->ID}&t={$token}");
        $this->assertNotEmpty($this->session()->get('AutoLoginHash'), 'reset link should put the hash in the session');
    }

    /**
     * The session state verifyMFACheck() leaves after a successful check for this member.
     */
    private function markMfaVerifiedFor(Member $member): void
    {
        $this->session()->set(MFAChangePasswordHandler::MFA_VERIFIED_ON_CHANGE_PASSWORD, true);
        $this->session()->set(ChangePasswordHandler::MFA_VERIFIED_MEMBER_ID, $member->ID);
    }

    /**
     * Store a known TOTP secret on the member's registration, encrypted as upstream stores it.
     */
    private function giveTotpSecret(Member $member): void
    {
        $method = $member->RegisteredMFAMethods()->first();
        $secret = Injector::inst()->get(EncryptionAdapterInterface::class)->encrypt(self::TOTP_SECRET, self::MFA_KEY);
        $method->Data = json_encode(['secret' => $secret]);
        $method->write();
    }

    /**
     * The code an authenticator app shows right now. Waits out the last second of a time step, so
     * the code cannot expire between making and checking it.
     */
    private function totpCode(): string
    {
        if (time() % 30 >= 29) {
            sleep(2);
        }
        return TOTP::create(self::TOTP_SECRET)->now();
    }

    private function postNewPassword(): HTTPResponse
    {
        return $this->post('Security/changepassword/ChangePasswordForm', [
            # Silverstripe 5 field names
            'NewPassword1' => self::NEW_PASSWORD,
            'NewPassword2' => self::NEW_PASSWORD,
            # Silverstripe 6 (one ConfirmedPasswordField)
            'Password' => ['_Password' => self::NEW_PASSWORD, '_ConfirmPassword' => self::NEW_PASSWORD],
            'action_doChangePassword' => 1,
        ]);
    }
}
