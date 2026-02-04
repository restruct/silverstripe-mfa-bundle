<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Extensions;

use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DB;

/**
 * Sets MFA as required by default when the bundle is installed.
 * Admins can still disable it via Settings → Access if needed.
 */
class SiteConfigMFAExtension extends DataExtension
{
    public function requireDefaultRecords(): void
    {
        // Only run once - check if MFARequired has ever been explicitly set
        $siteConfig = $this->owner;

        // Get the raw database value to check if it's been set
        $record = DB::query(
            "SELECT MFARequired FROM SiteConfig WHERE ID = " . (int)$siteConfig->ID
        )->record();

        // If MFARequired is NULL (never set), enable it by default
        if ($record && $record['MFARequired'] === null) {
            DB::query("UPDATE SiteConfig SET MFARequired = 1 WHERE ID = " . (int)$siteConfig->ID);
            DB::alteration_message('MFA enabled by default (can be disabled in Settings → Access)', 'created');
        }
    }
}