<?php

namespace Restruct\MFABundle\Tests;

use OTPHP\Factory;
use OTPHP\TOTP;
use Restruct\MFABundle\Extensions\TOTPConfigExtension;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\MFA\Store\SessionStore;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\TOTP\RegisterHandler;

/**
 * Regression tests for a SiteConfig title that is not a valid TOTP issuer (upstream
 * silverstripe-totp-authenticator#106 and #64).
 *
 * Upstream RegisterHandler::start() passes the title to setIssuer() before the updateTotp hook:
 * an empty title is a TypeError (null under strict_types) or "Issuer must not be empty", and a
 * title with a colon throws "Issuer must not contain a colon". Either way registration is a 500.
 * Restruct\MFABundle\TOTP\RegisterHandler builds the issuer safely instead.
 *
 * The handler is created through the Injector under its UPSTREAM class name, the way
 * SilverStripe\TOTP\Method creates it, so these tests also prove the Injector replacement.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class TOTPIssuerTest extends SapphireTest
{
    /**
     * SiteConfig::current_site_config() reads the database.
     */
    protected $usesDatabase = true;

    private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private ?string $originalEnvironmentType = null;

    private array $originalEnvVars = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironmentType = Director::get_environment_type();
        $this->originalEnvVars = Environment::getVariables()['env'];

        # register() refuses to store a secret without an encryption key. The env label is off on
        # live, so the issuer tests see the issuer itself; the label tests set 'dev'.
        Environment::setEnv('SS_MFA_SECRET_KEY', self::KEY);
        $this->setEnvironmentType('live');
        LeftAndMain::config()->set('application_name', 'App Name');
    }

    protected function tearDown(): void
    {
        $this->setEnvironmentType($this->originalEnvironmentType);
        # Restores only the 'env' slot; setVariables() skips the superglobals when given just that.
        Environment::setVariables(['env' => $this->originalEnvVars]);

        parent::tearDown();
    }

    private function setEnvironmentType(?string $type): void
    {
        Injector::inst()->get(Kernel::class)->setEnvironment($type);
    }

    private function setSiteTitle(?string $title): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->Title = $title;
        $siteConfig->write();
    }

    /**
     * Run the registration the way the MFA app does: start() for the QR code, then register()
     * with the code an authenticator app would make from it. Returns the start() data and whether
     * the registration was accepted.
     */
    private function registerApp(): array
    {
        $handler = Injector::inst()->create(RegisterHandler::class);
        $store = new SessionStore(Member::create(['Email' => 'member@example.com']));

        $data = $handler->start($store);

        $app = Factory::loadFromProvisioningUri($data['uri']);
        $this->assertInstanceOf(TOTP::class, $app);
        if (time() % $app->getPeriod() >= $app->getPeriod() - 1) {
            # Wait out the last second of a time step, so the code cannot expire before the check.
            sleep(2);
        }
        $request = new HTTPRequest('POST', '/', [], [], json_encode(['code' => $app->now()]));
        $result = $handler->register($request, $store);

        return [$data, $app, $result->isSuccessful()];
    }

    public function testEmptySiteTitleRegistersWithTheApplicationNameAsIssuer()
    {
        $this->setSiteTitle('');

        [, $app, $registered] = $this->registerApp();

        $this->assertSame('App Name', $app->getIssuer());
        $this->assertTrue($registered, 'registration succeeds with an empty site title');
    }

    public function testColonsAreRemovedFromTheSiteTitleIssuer()
    {
        $this->setSiteTitle('Foo: Bar');

        [, $app, $registered] = $this->registerApp();

        $this->assertSame('Foo Bar', $app->getIssuer());
        $this->assertTrue($registered, 'registration succeeds with a colon in the site title');
    }

    public function testEncodedColonsAreRemovedFromTheSiteTitleIssuer()
    {
        $this->setSiteTitle('Foo%3aBar');

        [, $app, $registered] = $this->registerApp();

        $this->assertSame('Foo Bar', $app->getIssuer());
        $this->assertTrue($registered);
    }

    public function testATitleOfOnlyColonsFallsBackToTheApplicationName()
    {
        $this->setSiteTitle(' : ');

        [, $app] = $this->registerApp();

        $this->assertSame('App Name', $app->getIssuer());
    }

    public function testNoIssuerAtAllStillRegisters()
    {
        # Neither the title nor application_name gives an issuer: setIssuer() is skipped (the
        # otpauth URI allows a label without issuer) instead of failing the registration.
        $this->setSiteTitle('');
        LeftAndMain::config()->set('application_name', ':');

        [$data, $app, $registered] = $this->registerApp();

        $this->assertNull($app->getIssuer());
        $this->assertStringContainsString('member%40example.com', $data['uri']);
        $this->assertTrue($registered);
    }

    public function testColonsAreRemovedFromTheConfiguredIssuer()
    {
        # TOTPConfigExtension.issuer replaces the title in the updateTotp hook; it gets the same
        # treatment, or a colon there fails registration the same way.
        $this->setSiteTitle('My Site');
        TOTPConfigExtension::config()->set('issuer', 'My Co: CMS');

        [, $app, $registered] = $this->registerApp();

        $this->assertSame('My Co CMS', $app->getIssuer());
        $this->assertTrue($registered);
    }

    public function testAValidSiteTitleIsStillTheIssuer()
    {
        # The normal case runs upstream start() unchanged.
        $this->setSiteTitle('My Site');

        [, $app, $registered] = $this->registerApp();

        $this->assertSame('My Site', $app->getIssuer());
        $this->assertTrue($registered);
    }

    public function testEnvironmentLabelStillAppliesToTheSafeIssuer()
    {
        $this->setEnvironmentType('dev');

        $this->setSiteTitle('');
        [, $app] = $this->registerApp();
        $this->assertSame('App Name (DEV)', $app->getIssuer(), 'empty title');

        $this->setSiteTitle('Foo: Bar');
        [, $app] = $this->registerApp();
        $this->assertSame('Foo Bar (DEV)', $app->getIssuer(), 'title with a colon');

        $this->setSiteTitle('My Site');
        [, $app] = $this->registerApp();
        $this->assertSame('My Site (DEV)', $app->getIssuer(), 'valid title, upstream start()');
    }
}
