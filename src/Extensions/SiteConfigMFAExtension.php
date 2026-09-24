<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Extensions;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DB;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Forces MFA to be required by default and hides the SiteConfig fields.
 *
 * Config options:
 *   Restruct\MFABundle\Extensions\SiteConfigMFAExtension:
 *     show_mfa_settings: true   # Show MFA fields in SiteConfig (default: false)
 *     grace_period_days: 180    # Days users can skip MFA setup (default: 180 = 6 months)
 */
class SiteConfigMFAExtension extends Extension
{
    use Configurable;
    private static bool $show_mfa_settings = false;

    private static int $grace_period_days = 180;

    /**
     * Silverstripe 5 name of the dev/build hook (DataObject::requireDefaultRecords() calls
     * extend('requireDefaultRecords')). Framework 6 renamed it, see onRequireDefaultRecords().
     */
    public function requireDefaultRecords(): void
    {
        $this->enforceMFARequirement();
    }

    /**
     * Silverstripe 6 name of the same hook: framework 6 calls extend('onRequireDefaultRecords')
     * and never calls the old name, so without this method nothing is enforced on 6.
     * Each major fires exactly one of the two names, so the work runs once per build.
     */
    public function onRequireDefaultRecords(): void
    {
        $this->enforceMFARequirement();
    }

    /**
     * Force MFARequired on and set the grace period, on every dev/build.
     */
    protected function enforceMFARequirement(): void
    {
        # The hook fires from DataObject::requireDefaultRecords(), which SiteConfig calls as
        # parent:: BEFORE it creates its own default record. On a fresh database there is no
        # SiteConfig row yet, so the UPDATEs below would match nothing and MFA would stay off
        # until the second dev/build. Create the row first; SiteConfig then finds it and skips.
        if (!DB::query("SELECT COUNT(*) FROM SiteConfig")->value()) {
            SiteConfig::make_site_config();
            DB::alteration_message('Added default site config', 'created');
        }

        // Always ensure MFA is enabled
        DB::query("UPDATE SiteConfig SET MFARequired = 1");

        // Set grace period if not already set
        $record = DB::query("SELECT MFAGracePeriodExpires FROM SiteConfig LIMIT 1")->record();

        if (!$record || empty($record['MFAGracePeriodExpires'])) {
            $days = (int) $this->config()->get('grace_period_days');
            if ($days > 0) {
                $expires = date('Y-m-d', strtotime("+{$days} days"));
                DB::query("UPDATE SiteConfig SET MFAGracePeriodExpires = '{$expires}'");
                DB::alteration_message("MFA grace period set to {$expires} ({$days} days)", 'created');
            }
        }

        DB::alteration_message('MFA requirement enforced', 'changed');
    }

    public function updateCMSFields(FieldList $fields): void
    {
        // Hide MFA settings unless config allows showing them
        if (!$this->config()->get('show_mfa_settings')) {
            $names = [
                'MFARequired',
                'MFAGracePeriodExpires',
            ];

            # Upstream wraps both fields in an unnamed CompositeField whose title is the
            # "Multi-factor authentication (MFA)" heading. Removing only the two fields left that
            # heading behind over an empty group, so remove the group itself (and with it the fields).
            $required = $fields->dataFieldByName('MFARequired');
            $group = $required ? $required->getContainerFieldList()?->getContainerField() : null;
            if ($group instanceof CompositeField) {
                # An unnamed CompositeField derives its name from its children, so that name
                # changes as they are removed. Pin one, and remove the group FIRST.
                $group->setName('MFASettingsGroup');
                array_unshift($names, 'MFASettingsGroup');
            }

            $fields->removeByName($names);
        }
    }
}