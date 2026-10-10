<?php

namespace Restruct\MFABundle\Tests;

use OTPHP\TOTP;
use Restruct\MFABundle\Extensions\TOTPConfigExtension;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\TOTP\RegisterHandler;

/**
 * Behavioural tests for TOTPConfigExtension.
 *
 * The hook is fired through RegisterHandler::extend('updateTotp', ...) - the same call the TOTP
 * module makes in RegisterHandler::start() - so these tests also prove the YAML applies the
 * extension to the handler and that the hook name still matches upstream.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class TOTPConfigExtensionTest extends SapphireTest
{
    /**
     * Environment type before the test, restored in tearDown().
     */
    private ?string $originalEnvironmentType = null;

    /**
     * Environment::$env before the test, restored in tearDown() (setEnv() is process-wide).
     */
    private array $originalEnvVars = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironmentType = Director::get_environment_type();
        $this->originalEnvVars = Environment::getVariables()['env'];

        # The issuer tests below assert the issuer chain as a live site sees it: on live the
        # default environment_label adds nothing. Hosts and CI run the suite as 'dev', which would
        # otherwise append " (DEV)". The label tests set their own environment type.
        $this->setEnvironmentType('live');
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

    /**
     * Build a TOTP object the way the TOTP module does, then run the updateTotp hook on it.
     */
    private function runHook(?string $upstreamIssuer = null): TOTP
    {
        $totp = TOTP::create();
        if ($upstreamIssuer !== null) {
            # RegisterHandler::start() sets SiteConfig::Title as issuer before firing the hook.
            $totp->setIssuer($upstreamIssuer);
        }

        # extend() takes its arguments by reference, so the absent member must be a variable.
        $member = null;
        $handler = Injector::inst()->create(RegisterHandler::class);
        $handler->extend('updateTotp', $totp, $member);

        return $totp;
    }

    public function testExtensionIsAppliedToTheTotpRegisterHandler()
    {
        $handler = Injector::inst()->create(RegisterHandler::class);
        $this->assertTrue($handler->hasExtension(TOTPConfigExtension::class));
    }

    public function testConfiguredIssuerOverridesTheSiteTitle()
    {
        Config::modify()->set(TOTPConfigExtension::class, 'issuer', 'Configured Issuer');

        $this->assertSame('Configured Issuer', $this->runHook('Site Title')->getIssuer());
    }

    public function testSiteTitleIssuerIsKeptWhenNoIssuerIsConfigured()
    {
        Config::modify()->set(LeftAndMain::class, 'application_name', 'App Name');

        $this->assertSame('Site Title', $this->runHook('Site Title')->getIssuer());
    }

    public function testApplicationNameIsTheFallbackWhenThereIsNoIssuer()
    {
        Config::modify()->set(LeftAndMain::class, 'application_name', 'App Name');

        $this->assertSame('App Name', $this->runHook()->getIssuer());
    }

    public function testDefaultsLeaveThePeriodAndDigestAlone()
    {
        $totp = $this->runHook('Site Title');

        $this->assertSame(30, $totp->getPeriod());
        $this->assertSame('sha1', $totp->getDigest());
    }

    public function testPeriodConfigChangesThePeriod()
    {
        Config::modify()->set(TOTPConfigExtension::class, 'period', 60);

        $this->assertSame(60, $this->runHook('Site Title')->getPeriod());
    }

    public function testAlgorithmConfigChangesTheDigest()
    {
        Config::modify()->set(TOTPConfigExtension::class, 'algorithm', 'sha256');

        $this->assertSame('sha256', $this->runHook('Site Title')->getDigest());
    }

    # --- Environment label ---

    public function testDevEnvironmentAddsDevLabelByDefault()
    {
        $this->setEnvironmentType('dev');

        $this->assertSame('Site Title (DEV)', $this->runHook('Site Title')->getIssuer());
    }

    public function testTestEnvironmentAddsTestLabelByDefault()
    {
        $this->setEnvironmentType('test');

        $this->assertSame('Site Title (TEST)', $this->runHook('Site Title')->getIssuer());
    }

    public function testLiveEnvironmentAddsNoLabelByDefault()
    {
        $this->setEnvironmentType('live');

        $this->assertSame('Site Title', $this->runHook('Site Title')->getIssuer());
    }

    public function testLabelIsAddedAfterTheIssuerFallbackChain()
    {
        $this->setEnvironmentType('dev');
        Config::modify()->set(TOTPConfigExtension::class, 'issuer', 'Configured Issuer');

        $this->assertSame('Configured Issuer (DEV)', $this->runHook('Site Title')->getIssuer());

        Config::modify()->set(TOTPConfigExtension::class, 'issuer', null);
        Config::modify()->set(LeftAndMain::class, 'application_name', 'App Name');

        $this->assertSame('App Name (DEV)', $this->runHook()->getIssuer());
    }

    public function testConfiguredLabelStringIsUsedEvenOnLive()
    {
        $this->setEnvironmentType('live');
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label', 'STAGING');

        $this->assertSame('Site Title (STAGING)', $this->runHook('Site Title')->getIssuer());
    }

    public function testConfiguredLabelFalseDisablesTheLabel()
    {
        $this->setEnvironmentType('dev');
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label', false);

        $this->assertSame('Site Title', $this->runHook('Site Title')->getIssuer());
    }

    public function testEnvVarOverridesTheConfig()
    {
        $this->setEnvironmentType('live');
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label', false);
        Environment::setEnv(TOTPConfigExtension::ENV_LABEL_VAR, 'ACCEPT');

        $this->assertSame('Site Title (ACCEPT)', $this->runHook('Site Title')->getIssuer());
    }

    public function testEmptyEnvVarDisablesTheLabel()
    {
        $this->setEnvironmentType('dev');
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label', 'STAGING');
        # As a `SS_MFA_TOTP_ENVIRONMENT_LABEL=` line in .env arrives: set, but empty.
        Environment::setEnv(TOTPConfigExtension::ENV_LABEL_VAR, '');

        $this->assertSame('Site Title', $this->runHook('Site Title')->getIssuer());
    }

    public function testAbsentEnvVarFallsBackToConfig()
    {
        $this->setEnvironmentType('live');
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label', 'STAGING');
        $this->assertFalse(Environment::hasEnv(TOTPConfigExtension::ENV_LABEL_VAR), 'precondition: var unset');

        $this->assertSame('Site Title (STAGING)', $this->runHook('Site Title')->getIssuer());
    }

    public function testLabelFormatIsConfigurable()
    {
        $this->setEnvironmentType('dev');
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label_format', '[%2$s] %1$s');

        $this->assertSame('[DEV] Site Title', $this->runHook('Site Title')->getIssuer());
    }

    public function testBrokenLabelFormatFallsBackToTheDefault()
    {
        $this->setEnvironmentType('dev');
        # Three placeholders, two arguments: sprintf() throws ArgumentCountError on PHP 8.
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label_format', '%s %s %s');

        $this->assertSame('Site Title (DEV)', $this->runHook('Site Title')->getIssuer());
    }

    public function testLabelAloneBecomesTheIssuerWhenThereIsNoIssuer()
    {
        $this->setEnvironmentType('dev');
        Config::modify()->set(LeftAndMain::class, 'application_name', null);

        $this->assertSame('DEV', $this->runHook()->getIssuer());
    }

    public function testLabelIsNotAppendedTwice()
    {
        $this->setEnvironmentType('dev');
        Config::modify()->set(TOTPConfigExtension::class, 'issuer', 'My Site (DEV)');

        $this->assertSame('My Site (DEV)', $this->runHook('Site Title')->getIssuer());
    }

    public function testColonsInLabelOrFormatCannotBreakRegistration()
    {
        $this->setEnvironmentType('live');
        # otphp throws InvalidLabelException for a ':' (or '%3A') in the issuer.
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label', 'env:stage%3a1');
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label_format', '%s: %s');

        $totp = $this->runHook('Site Title');
        $issuer = $totp->getIssuer();

        $this->assertSame('Site Title env stage 1', $issuer);
        $this->assertStringNotContainsString(':', $issuer);

        # The provisioning URI (what the QR code encodes) must still build.
        $totp->setLabel('member@example.com');
        $this->assertStringStartsWith('otpauth://totp/', $totp->getProvisioningUri());
    }

    public function testLabelThatSanitisesToNothingAddsNoLabel()
    {
        $this->setEnvironmentType('live');
        Config::modify()->set(TOTPConfigExtension::class, 'environment_label', ' : ');

        $this->assertSame('Site Title', $this->runHook('Site Title')->getIssuer());
    }
}
