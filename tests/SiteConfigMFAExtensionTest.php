<?php

namespace Restruct\MFABundle\Tests;

use Restruct\MFABundle\Extensions\SiteConfigMFAExtension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\CompositeField;
use SilverStripe\ORM\DB;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Behavioural tests for SiteConfigMFAExtension: the dev/build enforcement and the hidden fields.
 *
 * The enforcement is driven through SiteConfig::requireDefaultRecords() - the same call dev/build
 * makes - never by calling the extension method directly. That is deliberate: framework 6 renamed
 * the extension hook (requireDefaultRecords -> onRequireDefaultRecords), and only a test that goes
 * through the real call site can see a hook that silently stopped firing.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6). Keep
 * it free of doc-comment metadata (@test, @dataProvider) and of assertions removed after PHPUnit 9.
 */
class SiteConfigMFAExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        # dev/build prints alteration messages; keep the test output clean.
        DB::quiet(true);
    }

    protected function tearDown(): void
    {
        DB::quiet(false);
        parent::tearDown();
    }

    /**
     * Run what dev/build runs for SiteConfig, and return the SiteConfig rows as plain arrays.
     */
    private function buildAndReadRows(): array
    {
        SiteConfig::singleton()->requireDefaultRecords();

        $rows = [];
        foreach (DB::query('SELECT "MFARequired", "MFAGracePeriodExpires" FROM "SiteConfig"') as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function expectedExpiry(int $days): string
    {
        return date('Y-m-d', strtotime("+{$days} days"));
    }

    // ---------------------------------------------------------------- wiring

    public function testExtensionIsAppliedToSiteConfig()
    {
        $this->assertTrue(SiteConfig::singleton()->hasExtension(SiteConfigMFAExtension::class));
    }

    // ---------------------------------------------------------------- dev/build enforcement

    /**
     * Regression: on a fresh database the hook fired before SiteConfig created its own row, so the
     * UPDATEs matched nothing and the first dev/build left MFA off (MFARequired = 0, no grace
     * period) while printing that it had been enforced. Measured on Silverstripe 5 before the fix.
     */
    public function testFirstBuildOnEmptyDatabaseEnforcesMfa()
    {
        DB::query('DELETE FROM "SiteConfig"');

        $rows = $this->buildAndReadRows();

        $this->assertCount(1, $rows, 'Exactly one SiteConfig row: created once, not duplicated');
        $this->assertSame(1, (int) $rows[0]['MFARequired']);
        $this->assertSame($this->expectedExpiry(180), $rows[0]['MFAGracePeriodExpires']);
    }

    public function testBuildReEnforcesMfaWhenSwitchedOff()
    {
        $config = SiteConfig::current_site_config();
        $config->MFARequired = false;
        $config->write();

        $rows = $this->buildAndReadRows();

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['MFARequired']);
    }

    public function testGracePeriodDaysConfigChangesTheExpiry()
    {
        DB::query('DELETE FROM "SiteConfig"');
        Config::modify()->set(SiteConfigMFAExtension::class, 'grace_period_days', 30);

        $rows = $this->buildAndReadRows();

        $this->assertSame($this->expectedExpiry(30), $rows[0]['MFAGracePeriodExpires']);
    }

    public function testZeroGracePeriodLeavesExpiryEmpty()
    {
        DB::query('DELETE FROM "SiteConfig"');
        Config::modify()->set(SiteConfigMFAExtension::class, 'grace_period_days', 0);

        $rows = $this->buildAndReadRows();

        $this->assertSame(1, (int) $rows[0]['MFARequired']);
        $this->assertEmpty($rows[0]['MFAGracePeriodExpires']);
    }

    public function testExistingGracePeriodIsNotOverwritten()
    {
        $config = SiteConfig::current_site_config();
        $config->MFAGracePeriodExpires = '2030-01-01';
        $config->write();

        $rows = $this->buildAndReadRows();

        $this->assertSame('2030-01-01', $rows[0]['MFAGracePeriodExpires']);
    }

    // ---------------------------------------------------------------- CMS fields

    private function mfaFieldNames(): array
    {
        $fields = SiteConfig::current_site_config()->getCMSFields();
        $names = [];
        foreach (['MFARequired', 'MFAGracePeriodExpires'] as $name) {
            if ($fields->dataFieldByName($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Titles of every field on the Access tab, recursing into groups.
     */
    private function accessTabTitles(): array
    {
        $titles = [];
        $walk = function ($fields) use (&$walk, &$titles) {
            foreach ($fields as $field) {
                $titles[] = strip_tags((string) $field->Title());
                if ($field instanceof CompositeField) {
                    $walk($field->getChildren());
                }
            }
        };
        $walk(SiteConfig::current_site_config()->getCMSFields()->findTab('Root.Access')->Fields());

        return $titles;
    }

    /**
     * Regression: the bundle's extension was registered BEFORE the upstream MFA module's
     * SiteConfig extension, so it ran first, removed nothing, and the fields were then added.
     */
    public function testMfaFieldsAreHiddenByDefault()
    {
        $this->logInWithPermission('ADMIN');
        $this->assertSame([], $this->mfaFieldNames());
    }

    /**
     * Regression: removing only the two fields left the upstream group's
     * "Multi-factor authentication (MFA)" heading on the Access tab over an empty group.
     */
    public function testNoEmptyMfaHeadingIsLeftBehind()
    {
        $this->logInWithPermission('ADMIN');

        $mfaTitles = array_filter($this->accessTabTitles(), function ($title) {
            return stripos($title, 'Multi-factor authentication') !== false;
        });
        $this->assertSame([], array_values($mfaTitles));
    }

    public function testShowMfaSettingsConfigKeepsTheFields()
    {
        $this->logInWithPermission('ADMIN');
        Config::modify()->set(SiteConfigMFAExtension::class, 'show_mfa_settings', true);

        # Control for the tests above: the upstream MFA module does add both fields and its
        # heading, so their absence there means this extension removed them.
        $this->assertSame(['MFARequired', 'MFAGracePeriodExpires'], $this->mfaFieldNames());
        $mfaTitles = array_filter($this->accessTabTitles(), function ($title) {
            return stripos($title, 'Multi-factor authentication') !== false;
        });
        $this->assertCount(1, $mfaTitles);
    }
}
