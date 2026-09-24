<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Extensions;

use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\Core\Extension;

/**
 * Adds summary fields for RegisteredMethod GridField display.
 *
 * Extends Extension rather than DataExtension: DataExtension is deprecated since framework 5.3
 * and removed in 6, while Extension carries every DataObject hook on both majors.
 *
 * @extends Extension<RegisteredMethod>
 */
class RegisteredMethodExtension extends Extension
{
    private static array $summary_fields = [
        'MethodName' => 'Method',
        'Created.Nice' => 'Registered',
    ];

    /**
     * Get human-readable method name for GridField display.
     */
    public function getMethodName(): string
    {
        try {
            return $this->owner->getMethod()->getName();
        } catch (\Exception $e) {
            // Method class might not exist anymore
            return $this->owner->MethodClassName ?? 'Unknown';
        }
    }
}