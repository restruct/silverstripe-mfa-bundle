<?php

namespace Restruct\MFABundle\Tests;

use OTPHP\TOTP;
use Restruct\MFABundle\Extensions\TOTPConfigExtension;
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
 * Regression tests for issue #3: TOTPConfigExtension.period / .algorithm changed the QR code
 * (RegisterHandler::start()) but not the check of the code (RegisterHandler::register() and
 * VerifyHandler::verify()), so registration with a non-default period or algorithm always failed.
 *
 * The handlers are created through the Injector under their UPSTREAM class names, the way
 * SilverStripe\TOTP\Method creates them, so these tests also prove the Injector replacement in
 * _config/mfa-bundle.yml is in effect.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class TOTPCodeCheckTest extends SapphireTest
{
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
     * The code an authenticator app makes right now for the given settings. Waits out the last
     * second of a time step, so the code cannot expire between making and checking it.
     */
    private function codeFor(int $period, string $digest): string
    {
        if (time() % $period >= $period - 1) {
            sleep(2);
        }
        $totp = TOTP::create(self::SECRET);
        $totp->setPeriod($period);
        $totp->setDigest($digest);
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

    public function testRegistrationAcceptsACodeFromTheConfiguredPeriod()
    {
        Config::modify()->set(TOTPConfigExtension::class, 'period', 60);
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(60, 'sha1')), $this->registrationStore());

        $this->assertTrue($result->isSuccessful(), 'a 60-second code is accepted when period is 60');
    }

    public function testRegistrationAcceptsACodeFromTheConfiguredAlgorithm()
    {
        Config::modify()->set(TOTPConfigExtension::class, 'algorithm', 'sha256');
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(30, 'sha256')), $this->registrationStore());

        $this->assertTrue($result->isSuccessful(), 'a SHA256 code is accepted when algorithm is sha256');
    }

    public function testRegistrationWithDefaultsStillAcceptsADefaultCode()
    {
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(30, 'sha1')), $this->registrationStore());

        $this->assertTrue($result->isSuccessful());
    }

    public function testRegistrationStoresThePeriodAndAlgorithmItWasMadeWith()
    {
        Config::modify()->set(TOTPConfigExtension::class, 'period', 60);
        Config::modify()->set(TOTPConfigExtension::class, 'algorithm', 'sha256');
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(60, 'sha256')), $this->registrationStore());

        $this->assertTrue($result->isSuccessful());
        $context = $result->getContext();
        $this->assertArrayHasKey('secret', $context, 'the encrypted secret is still stored');
        $this->assertSame(60, $context['period'] ?? null);
        $this->assertSame('sha256', $context['digest'] ?? null);
    }

    public function testRegistrationIsRefusedWhenOnlyTheVerifyHandlerIsReplaced()
    {
        # A project replaced only SilverStripe\TOTP\VerifyHandler, with a subclass of upstream:
        # logins would be checked with 30 s / SHA1, so a 60-second registration would lock the
        # member out at the next login. It must be refused rather than stored.
        Injector::inst()->load([VerifyHandler::class => ['class' => ForeignVerifyHandler::class]]);
        Config::modify()->set(TOTPConfigExtension::class, 'period', 60);
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(60, 'sha1')), $this->registrationStore());

        $this->assertFalse($result->isSuccessful(), 'a registration the login check cannot honour is refused');
        $this->assertStringContainsString('contact an administrator', (string) $result->getMessage());
    }

    public function testAReplacedVerifyHandlerDoesNotAffectRegistrationWithDefaults()
    {
        # With 30 s / SHA1 every VerifyHandler checks the same way, so nothing is refused.
        Injector::inst()->load([VerifyHandler::class => ['class' => ForeignVerifyHandler::class]]);
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->register($this->request($this->codeFor(30, 'sha1')), $this->registrationStore());

        $this->assertTrue($result->isSuccessful());
    }

    public function testLoginChecksTheCodeWithThePeriodAndAlgorithmOfTheRegistration()
    {
        # The registration was made with 60 s / SHA256. The config has since moved back to the
        # defaults: the app still makes 60-second SHA256 codes, so that is what must be checked.
        $method = $this->registeredMethod(['period' => 60, 'digest' => 'sha256']);
        $handler = Injector::inst()->create(VerifyHandler::class);
        $store = new SessionStore(Member::create());
        $handler->start($store, $method);

        $result = $handler->verify($this->request($this->codeFor(60, 'sha256')), $store, $method);

        $this->assertTrue($result->isSuccessful(), 'a code from the registered settings is accepted');
    }

    public function testLoginKeepsTheLibraryDefaultsForRegistrationsMadeBeforeTheFix()
    {
        # Before the fix every registration was checked - and therefore made - with 30 s / SHA1,
        # whatever the config said, and its Data holds no period or digest. Applying a configured
        # period to it would lock that member out.
        Config::modify()->set(TOTPConfigExtension::class, 'period', 60);
        Config::modify()->set(TOTPConfigExtension::class, 'algorithm', 'sha256');
        $method = $this->registeredMethod();
        $handler = Injector::inst()->create(VerifyHandler::class);
        $store = new SessionStore(Member::create());
        $handler->start($store, $method);

        $result = $handler->verify($this->request($this->codeFor(30, 'sha1')), $store, $method);

        $this->assertTrue($result->isSuccessful(), 'a 30-second SHA1 code is accepted for a legacy registration');
    }
}
