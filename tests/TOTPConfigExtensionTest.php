<?php

namespace Restruct\MFABundle\Tests;

use OTPHP\TOTP;
use Restruct\MFABundle\Extensions\TOTPConfigExtension;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
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
}
