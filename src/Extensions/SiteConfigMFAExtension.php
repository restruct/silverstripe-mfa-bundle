<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DB;

/**
 * Forces MFA to be required by default and hides the SiteConfig fields.
 *
 * To allow admins to disable MFA via CMS, set in your config:
 *   Restruct\MFABundle\Extensions\SiteConfigMFAExtension:
 *     show_mfa_settings: true
 */
class SiteConfigMFAExtension extends Extension
{
    private static bool $show_mfa_settings = false;

    public function requireDefaultRecords(): void
    {
        // Always ensure MFA is enabled
        DB::query("UPDATE SiteConfig SET MFARequired = 1");
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