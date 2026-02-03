<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Extensions;

use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\ORM\DataExtension;

/**
 * Adds summary fields for RegisteredMethod GridField display.
 *
 * @extends DataExtension<RegisteredMethod>
 */
class RegisteredMethodExtension extends DataExtension
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