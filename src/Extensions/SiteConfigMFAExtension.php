<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Extensions;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DB;

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

    public function requireDefaultRecords(): void
    {
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
            $fields->removeByName([
                'MFARequired',
                'MFAGracePeriodExpires',
            ]);
        }
    }
}