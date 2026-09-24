<?php

namespace Restruct\MFABundle\Tests;

use Restruct\MFABundle\Controllers\MFAHelpController;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Member;

/**
 * Behavioural tests for the /mfa-help/ pages: routing, rendering through the module's template,
 * the robots header, the per-member locale and the escaping of translated content.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class MFAHelpControllerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    private ?string $originalLocale = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLocale = i18n::get_locale();
    }

    protected function tearDown(): void
    {
        # The controller sets the process-wide locale in init(); do not leak it into later tests.
        i18n::set_locale($this->originalLocale);
        parent::tearDown();
    }

    public function testEachHelpPageRendersWithItsHeadingAndNavigation()
    {
        i18n::set_locale('en_US');
        $pages = [
            'mfa-help/' => 'Two-Factor Authentication (MFA)',
            'mfa-help/totp' => 'Setting Up Authenticator App',
            'mfa-help/webauthn' => 'Setting Up Security Key',
            'mfa-help/backup-codes' => 'Backup Codes',
        ];

        foreach ($pages as $url => $heading) {
            $response = $this->get($url);
            $body = $response->getBody();

            $this->assertSame(200, $response->getStatusCode(), $url);
            $this->assertStringContainsString("<h1>{$heading}</h1>", $body, $url);
            $this->assertStringContainsString("<title>{$heading} - MFA Help</title>", $body, $url);
            # Navigation is rendered unescaped: a real anchor, not &lt;a
            $this->assertStringContainsString('<nav class="mfa-help-nav">', $body, $url);
            $this->assertMatchesRegularExpression('#<a href="[^"]*mfa-help/totp">|<strong>#', $body, $url);
            $this->assertStringNotContainsString('&lt;h1&gt;', $body, $url);
        }
    }

    public function testPagesAreMarkedNoindex()
    {
        $response = $this->get('mfa-help/totp');

        $this->assertSame('noindex, nofollow', $response->getHeader('X-Robots-Tag'));
    }

    public function testLoggedInMembersLocaleSelectsTheTranslation()
    {
        $member = Member::create(['Email' => 'nl@example.com', 'Locale' => 'nl_NL']);
        $member->write();
        $this->logInAs($member);

        $body = $this->get('mfa-help/totp')->getBody();

        $this->assertStringContainsString('<h1>Authenticator App Instellen</h1>', $body);
    }

    public function testLinkIsBuiltFromTheUrlSegment()
    {
        $controller = MFAHelpController::create();

        $this->assertSame('mfa-help/totp', $controller->Link('totp'));
    }

    public function testTranslatedContentIsHtmlEscapedBeforeMarkdown()
    {
        $controller = MFAHelpController::create();
        $method = new \ReflectionMethod($controller, 'parseMarkdown');
        $method->setAccessible(true);

        $html = $method->invoke($controller, "# Title\n\nText <script>alert(1)</script> **bold**");

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
