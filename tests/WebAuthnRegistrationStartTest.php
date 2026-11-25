<?php

namespace Restruct\MFABundle\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\MFA\Store\SessionStore;
use SilverStripe\Security\Member;
use SilverStripe\WebAuthn\RegisterHandler;

/**
 * Starting a security-key registration works under the bundle's own config.
 *
 * The bundle sets RegisterHandler.authenticator_attachment to null (both platform and
 * cross-platform keys). BundleConfigTest only pins that config value; this runs the handler with it.
 * A webauthn-lib 5 port that cast that null to '' threw "Invalid authenticator attachment" here, so
 * every registration on a bundle site failed, while the authenticator's own suite (default
 * 'cross-platform') stayed green.
 *
 * Works against upstream silverstripe/webauthn-authenticator (webauthn-lib 4, keyData is an options
 * object) and the restruct fork (webauthn-lib 5, keyData is an array): both are compared as the JSON
 * the browser receives. Runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class WebAuthnRegistrationStartTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testRegistrationStartsWithTheBundleConfig()
    {
        $member = Member::get()->byID($this->logInWithPermission());
        $handler = Injector::inst()->create(RegisterHandler::class);

        $result = $handler->start(new SessionStore($member));

        $this->assertArrayHasKey('keyData', $result);
        # the JSON the browser gets, the same for both webauthn-lib majors
        $options = json_decode(json_encode($result['keyData']), true);
        $this->assertIsArray($options);
        $this->assertNotEmpty($options['challenge'] ?? null, 'options carry a challenge');

        # null attachment = no preference: the browser must not be sent a platform restriction
        $attachment = $options['authenticatorSelection']['authenticatorAttachment'] ?? null;
        $this->assertNull($attachment, 'no authenticatorAttachment restriction is sent');
    }
}
