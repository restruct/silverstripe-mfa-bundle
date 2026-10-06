<?php

namespace Restruct\MFABundle\Tests;

use Restruct\MFABundle\Authenticator\SingleCheckLoginHandler;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\MFA\Authenticator\LoginHandler;
use SilverStripe\MFA\Service\EnforcementManager;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * SingleCheckLoginHandler: one login POST checks the credentials once, so it records one
 * LoginAttempt and an account locks after Member.lock_out_after_incorrect_logins wrong passwords,
 * not after half of them (silverstripe/silverstripe-mfa#421).
 *
 * Every test posts to the real login form, so the request goes through MFA's MemberAuthenticator,
 * the Injector replacement, MFA's doLogin() and the framework's doLogin().
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class SingleCheckLoginHandlerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    private const EMAIL = 'single-check@example.com';
    private const PASSWORD = 'Correct-Horse-9';

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        # The opt-in a project makes in YAML (see the README), applied to this test's nested Injector.
        Injector::inst()->load([
            LoginHandler::class => ['class' => SingleCheckLoginHandler::class],
        ]);

        SecurityToken::disable();
        # Pin the framework defaults the assertions count against.
        Config::modify()->set(Member::class, 'lock_out_after_incorrect_logins', 10);
        Config::modify()->set(Member::class, 'lock_out_delay_mins', 15);

        $this->member = Member::create(['Email' => self::EMAIL, 'FirstName' => 'Single']);
        $this->member->Password = self::PASSWORD;
        $this->member->write();
    }

    public function testInjectorReplacementIsWhatTheAuthenticatorBuilds()
    {
        # Control: without this the tests below would exercise MFA's own handler.
        $handler = Security::singleton()->getAuthenticators()['default']->getLoginHandler('Security/login/default');
        $this->assertInstanceOf(SingleCheckLoginHandler::class, $handler);
    }

    public function testWrongPasswordRecordsOneFailedAttempt()
    {
        $this->postLogin('wrong-password');

        $this->assertSame(1, $this->attempts(LoginAttempt::FAILURE));
        $this->assertSame(0, $this->attempts(LoginAttempt::SUCCESS));
    }

    public function testAccountLocksAfterTheConfiguredNumberOfWrongPasswords()
    {
        # With the double check the account locked after 5 POSTs; it must take the full 10.
        for ($i = 1; $i <= 9; $i++) {
            $this->postLogin('wrong-password');
        }
        $this->assertSame(9, $this->attempts(LoginAttempt::FAILURE));
        $this->assertFalse($this->freshMember()->isLockedOut(), 'locked out before the 10th wrong password');

        $this->postLogin('wrong-password');
        $this->assertTrue($this->freshMember()->isLockedOut(), 'not locked out after the 10th wrong password');
    }

    public function testValidLoginWithoutMfaRecordsOneSuccessAndLogsIn()
    {
        # MFA off: MFA's doLogin() hands the whole login to the framework's doLogin().
        Config::modify()->set(EnforcementManager::class, 'enabled', false);

        $this->postLogin(self::PASSWORD);

        $this->assertSame(1, $this->attempts(LoginAttempt::SUCCESS));
        $this->assertSame(0, $this->attempts(LoginAttempt::FAILURE));
        $this->assertSame((int)$this->member->ID, (int)$this->session()->get('loggedInAs'));
    }

    public function testValidLoginWithMfaStillGoesToTheMfaStep()
    {
        # MFA on and the member has not skipped registration: MFA's own flow, which only ever
        # checked once; it must still run and must not log the member in yet.
        Config::modify()->set(EnforcementManager::class, 'enabled', true);
        # MFA applies only to members with CMS access by default; this member has none.
        Config::modify()->set(EnforcementManager::class, 'requires_admin_access', false);

        $this->autoFollowRedirection = false;
        $response = $this->postLogin(self::PASSWORD);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/mfa', (string)$response->getHeader('Location'));
        $this->assertSame(1, $this->attempts(LoginAttempt::SUCCESS));
        $this->assertEmpty($this->session()->get('loggedInAs'));
    }

    public function testHandlerChecksAgainOnTheNextLogin()
    {
        # The stored result is per doLogin() call: a second POST must be checked on its own.
        $this->postLogin('wrong-password');
        $this->postLogin('wrong-password');

        $this->assertSame(2, $this->attempts(LoginAttempt::FAILURE));
    }

    private function postLogin(string $password)
    {
        return $this->post('Security/login/default/LoginForm', [
            'Email' => self::EMAIL,
            'Password' => $password,
            'AuthenticationMethod' => 'SilverStripe\\MFA\\Authenticator\\MemberAuthenticator',
            'action_doLogin' => 'Log in',
        ]);
    }

    private function attempts(string $status): int
    {
        return LoginAttempt::get()->filter('Status', $status)->count();
    }

    private function freshMember(): Member
    {
        return Member::get()->byID($this->member->ID);
    }
}
