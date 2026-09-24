<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Extensions;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
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
     *
     * With show_mfa_settings: true the admin can switch MFARequired in Settings > Access, so it is
     * forced on only by the build that creates the SiteConfig row (a fresh database); later builds
     * leave the admin's choice alone. The grace period follows the same rule: an empty date is
     * filled in only when MFARequired is enforced, so an admin who clears it keeps it cleared.
     */
    protected function enforceMFARequirement(): void
    {
        # The hook fires from DataObject::requireDefaultRecords(), which SiteConfig calls as
        # parent:: BEFORE it creates its own default record. On a fresh database there is no
        # SiteConfig row yet, so the UPDATEs below would match nothing and MFA would stay off
        # until the second dev/build. Create the row first; SiteConfig then finds it and skips.
        $createdRecord = false;
        if (!DB::query("SELECT COUNT(*) FROM SiteConfig")->value()) {
            SiteConfig::make_site_config();
            DB::alteration_message('Added default site config', 'created');
            $createdRecord = true;
        }

        # With the fields hidden (the default) nobody can change MFARequired in the CMS, so forcing
        # it on every build only restores the intended state. With show_mfa_settings: true it is
        # the admin's setting: forcing it on every build would silently undo an admin's "optional"
        # on the next deploy. Then only the build that just created the row (a fresh database)
        # turns it on, as the starting value.
        $enforce = $createdRecord || !$this->config()->get('show_mfa_settings');

        // Always ensure MFA is enabled
        if ($enforce) {
            DB::query("UPDATE SiteConfig SET MFARequired = 1");
        }

        // Set grace period if not already set
        # Only under the same $enforce gate as MFARequired. With show_mfa_settings: true the grace
        # date is the admin's setting too: clearing it makes MFA mandatory at once, and refilling
        # it on every build would silently hand every user a new skip window on the next deploy.
        $record = $enforce
            ? DB::query("SELECT MFAGracePeriodExpires FROM SiteConfig LIMIT 1")->record()
            : null;

        if ($enforce && (!$record || empty($record['MFAGracePeriodExpires']))) {
            $days = (int) $this->config()->get('grace_period_days');
            if ($days > 0) {
                $expires = date('Y-m-d', strtotime("+{$days} days"));
                DB::query("UPDATE SiteConfig SET MFAGracePeriodExpires = '{$expires}'");
                DB::alteration_message("MFA grace period set to {$expires} ({$days} days)", 'created');
            }
        }

        if ($enforce) {
            DB::alteration_message('MFA requirement enforced', 'changed');
        } else {
            DB::alteration_message('MFA requirement left as set in Settings > Access (show_mfa_settings: true)', 'notice');
        }
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
            // if ($group instanceof CompositeField) {
            //     # An unnamed CompositeField derives its name from its children, so that name
            //     # changes as they are removed. Pin one, and remove the group FIRST.
            //     $group->setName('MFASettingsGroup');
            //     array_unshift($names, 'MFASettingsGroup');
            // }

            # Remove the two fields first, then the group only if that left it empty. Removing the
            # group outright would also drop any field other code added to it, and a Tab is a
            # CompositeField too: if upstream ever flattens the group, the container would be the
            # Access tab itself, and removing it would take every other access setting with it.
            $fields->removeByName($names);

            if (
                $group instanceof CompositeField
                && !$group instanceof Tab
                && !$group instanceof TabSet
                && $group->getChildren()->count() === 0
            ) {
                # An unnamed CompositeField derives its name from its children, which are gone now,
                # so pin a name to remove it by.
                $group->setName('MFASettingsGroup');
                $fields->removeByName('MFASettingsGroup');
            }
        }
    }
}