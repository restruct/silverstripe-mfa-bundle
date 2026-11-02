<?php

namespace Restruct\MFABundle\Tests;

use OTPHP\Factory;
use OTPHP\TOTP;
use Restruct\MFABundle\Tests\Stub\ForeignVerifyHandler;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\MFA\Service\EncryptionAdapterInterface;
use SilverStripe\MFA\Store\SessionStore;
use SilverStripe\Security\Member;
use SilverStripe\TOTP\Method;
use SilverStripe\TOTP\RegisterHandler;
use SilverStripe\TOTP\VerifyHandler;

/**
 * Regression tests for issue #7: SilverStripe\TOTP\Method.code_length only reached the input field.
 * The QR code and the server check stayed 6 digits, so with code_length 8 no code was accepted.
 *
 * The digit count is stored with the registration like the period and algorithm (#3), so a later
 * change of code_length does not lock out members whose app makes codes of the old length.
 *
 * The handlers are created through the Injector under their UPSTREAM class names, the way
 * SilverStripe\TOTP\Method creates them.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class TOTPCodeLengthTest extends SapphireTest
{
    /**
     * RegisterHandler::start() reads SiteConfig::current_site_config() for the issuer.
     */
    protected $usesDatabase = true;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private ?string $previousKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        # register() refuses to store a secret without an encryption key; set a test key for
        # this test only and restore whatever was there afterwards.
        $this->previousKey = Environment::getEnv('SS_MFA_SECRET_KEY') ?: null;
        Environment::setEnv('SS_MFA_SECRET_KEY', self::KEY);
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SS_MFA_SECRET_KEY', (string) $this->previousKey);
        parent::tearDown();
    }

    /**
     * The code an authenticator app makes right now with 30 s / SHA1 and the given number of
     * digits. Waits out the last second of a time step, so the code cannot expire before the check.
     */
    private function codeFor(int $digits): string
    {
        if (time() % 30 >= 29) {
            sleep(2);
        }
        $totp = TOTP::create(self::SECRET);
        $totp->setDigits($digits);
        return $totp->now();
    }

    private function request(string $code): HTTPRequest
    {
        return new HTTPRequest('POST', '/', [], [], json_encode(['code' => $code]));
    }

    /**
     * A registration store as RegisterHandler::start() leaves it, with a known secret.
     */
    private function registrationStore(): SessionStore
    {
        $store = new SessionStore(Member::create());
        $store->setState(['secret' => self::SECRET]);
        return $store;
    }

    /**
     * A RegisteredMethod as RegisteredMethodManager stores it: the register() context as JSON.
     */
    private function registeredMethod(array $extraData = []): RegisteredMethod
    {
        $secret = Injector::inst()->get(EncryptionAdapterInterface::class)->encrypt(self::SECRET, self::KEY);
        return RegisteredMethod::create([
            'MethodClassName' => Method::class,
            'Data' => json_encode(['secret' => $secret] + $extraData),
        ]);
    }

    public function testQrCodeCarriesTheConfiguredDigits()
    {
        Config::modify()->set(Method::class, 'code_length', 8);
        $handler = Injector::inst()->create(RegisterHandler::class);
        $store = new SessionStore(Member::create(['Email' => 'member@example.com']));

        $data = $handler->start($store);

        $this->assertSame(8, $data['codeLength'], 'the input field expects 8 digits');
        $this->assertStringContainsString('digits=8', $data['uri'], 'the otpauth URI tells the app to make 8 digits');
        $app = Factory::loadFromProvisioningUri($data['uri']);
        $this->assertSame(8, $app->getDigits());
    }

    public function testRegistrationAcceptsAnEightDigitCodeAndStoresTheDigits()
    {
        Config::modify()->set(Method::class, 'code_length', 8);
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(8)), $this->registrationStore());

        $this->assertTrue($result->isSuccessful(), 'an 8-digit code is accepted when code_length is 8');
        $this->assertSame(8, $result->getContext()['digits'] ?? null, 'the digit count is stored with the registration');
    }

    public function testRegistrationRefusesASixDigitCodeWhenCodeLengthIsEight()
    {
        Config::modify()->set(Method::class, 'code_length', 8);
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(6)), $this->registrationStore());

        $this->assertFalse($result->isSuccessful());
    }

    public function testLoginChecksTheDigitsOfTheRegistration()
    {
        # Registered with 8 digits; code_length has since gone back to 6. The app still makes
        # 8-digit codes, so the login field and the check must both follow the registration.
        $method = $this->registeredMethod(['period' => 30, 'digest' => 'sha1', 'digits' => 8]);
        $handler = Injector::inst()->create(VerifyHandler::class);
        $store = new SessionStore(Member::create());
        $data = $handler->start($store, $method);

        $this->assertSame(8, $data['codeLength'], 'the login field expects the registered 8 digits');
        $result = $handler->verify($this->request($this->codeFor(8)), $store, $method);
        $this->assertTrue($result->isSuccessful(), 'an 8-digit code is accepted for an 8-digit registration');
    }

    public function testASixDigitRegistrationStillLogsInAfterCodeLengthChangesToEight()
    {
        # Registrations made before this fix store no digits (1.8.0 stored period and digest, older
        # ones nothing); every one of them was checked, and therefore made, with 6 digits.
        Config::modify()->set(Method::class, 'code_length', 8);
        foreach ([[], ['period' => 30, 'digest' => 'sha1']] as $storedData) {
            $method = $this->registeredMethod($storedData);
            $handler = Injector::inst()->create(VerifyHandler::class);
            $store = new SessionStore(Member::create());
            $data = $handler->start($store, $method);

            $this->assertSame(6, $data['codeLength'], 'the login field keeps 6 digits for an old registration');
            $result = $handler->verify($this->request($this->codeFor(6)), $store, $method);
            $this->assertTrue($result->isSuccessful(), 'a 6-digit code is accepted for an old registration');
        }
    }

    public function testRegistrationIsRefusedWhenOnlyTheVerifyHandlerIsReplaced()
    {
        # A VerifyHandler that does not extend the bundle's checks logins with 6 digits, so an
        # 8-digit registration would lock the member out at the next login.
        Injector::inst()->load([VerifyHandler::class => ['class' => ForeignVerifyHandler::class]]);
        Config::modify()->set(Method::class, 'code_length', 8);
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(8)), $this->registrationStore());

        $this->assertFalse($result->isSuccessful(), 'a registration the login check cannot honour is refused');
        $this->assertStringContainsString('contact an administrator', (string) $result->getMessage());
    }
}
