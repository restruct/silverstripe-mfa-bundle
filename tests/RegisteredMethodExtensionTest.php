<?php

namespace Restruct\MFABundle\Tests;

use Restruct\MFABundle\Extensions\RegisteredMethodExtension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\TOTP\Method as TOTPMethod;
use SilverStripe\WebAuthn\Method as WebAuthnMethod;

/**
 * Behavioural tests for RegisteredMethodExtension, which feeds the admin GridField's columns.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class RegisteredMethodExtensionTest extends SapphireTest
{
    public function testExtensionIsAppliedToRegisteredMethod()
    {
        $this->assertTrue(RegisteredMethod::singleton()->hasExtension(RegisteredMethodExtension::class));
    }

    public function testSummaryFieldsComeFromTheExtension()
    {
        $summary = RegisteredMethod::singleton()->summaryFields();

        $this->assertArrayHasKey('MethodName', $summary);
        $this->assertArrayHasKey('Created.Nice', $summary);
    }

    public function testMethodNameIsTheHumanReadableNameOfTheMethod()
    {
        $totp = RegisteredMethod::create(['MethodClassName' => TOTPMethod::class]);
        $webAuthn = RegisteredMethod::create(['MethodClassName' => WebAuthnMethod::class]);

        $this->assertSame(Injector::inst()->create(TOTPMethod::class)->getName(), $totp->getMethodName());
        $this->assertSame(Injector::inst()->create(WebAuthnMethod::class)->getName(), $webAuthn->getMethodName());
        # Guard against both sides being empty.
        $this->assertNotSame('', $totp->getMethodName());
    }

    public function testMethodNameFallsBackToTheClassNameWhenTheClassIsGone()
    {
        $method = RegisteredMethod::create(['MethodClassName' => 'Gone\\Uninstalled\\Method']);

        $this->assertSame('Gone\\Uninstalled\\Method', $method->getMethodName());
    }
}
