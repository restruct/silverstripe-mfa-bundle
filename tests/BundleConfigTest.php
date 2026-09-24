<?php

namespace Restruct\MFABundle\Tests;

use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\MFA\Authenticator\LoginHandler;
use SilverStripe\MFA\BackupCode\RegisterHandler as BackupCodeRegisterHandler;
use SilverStripe\MFA\Service\EnforcementManager;
use SilverStripe\TOTP\RegisterHandler as TOTPRegisterHandler;
use SilverStripe\WebAuthn\RegisterHandler as WebAuthnRegisterHandler;

/**
 * The bundle's defaults are YAML only (_config/mfa-bundle.yml). These tests pin that each one is
 * actually in effect on top of the upstream modules, including the fragment ordering against the
 * TOTP and WebAuthn modules' own config.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class BundleConfigTest extends SapphireTest
{
    public function testAtLeastOneMfaMethodIsRequired()
    {
        $this->assertSame(1, Config::inst()->get(EnforcementManager::class, 'required_mfa_methods'));
    }

    public function testWebAuthnAllowsBothPlatformAndCrossPlatformAuthenticators()
    {
        # Upstream default is 'cross-platform'; the bundle sets null (both).
        $this->assertNull(Config::inst()->get(WebAuthnRegisterHandler::class, 'authenticator_attachment'));
    }

    public function testHelpLinksPointAtTheBundledHelpPages()
    {
        $this->assertSame('/mfa-help/totp', Config::inst()->get(TOTPRegisterHandler::class, 'user_help_link'));
        $this->assertSame('/mfa-help/webauthn', Config::inst()->get(WebAuthnRegisterHandler::class, 'user_help_link'));
        $this->assertSame('/mfa-help/', Config::inst()->get(LoginHandler::class, 'user_help_link'));
        $this->assertSame(
            '/mfa-help/backup-codes',
            Config::inst()->get(BackupCodeRegisterHandler::class, 'user_help_link')
        );
    }
}
