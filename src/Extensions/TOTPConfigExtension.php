<?php

namespace Restruct\MFABundle\Extensions;

use OTPHP\TOTP;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Member;

/**
 * Configurable TOTP settings for authenticator apps.
 *
 * @extends Extension<\SilverStripe\TOTP\RegisterHandler>
 */
class TOTPConfigExtension extends Extension
{
    use Configurable;

    /**
     * Issuer name shown in authenticator apps (e.g., "My Company CMS")
     * If not set, falls back to SiteConfig::Title
     */
    private static ?string $issuer = null;

    /**
     * Time period in seconds for TOTP code validity (default: 30)
     */
    private static int $period = 30;

    /**
     * Hash algorithm: sha1, sha256, or sha512 (default: sha1)
     * Note: Not all authenticator apps support sha256/sha512
     */
    private static string $algorithm = 'sha1';

    /**
     * Called during TOTP registration to customize the TOTP object
     */
    public function updateTotp(TOTP $totp, ?Member $member): void
    {
        $issuer = $this->config()->get('issuer');
        if ($issuer) {
            $totp->setIssuer($issuer);
        }

        $period = $this->config()->get('period');
        if ($period && $period !== 30) {
            $totp->setPeriod($period);
        }

        $algorithm = $this->config()->get('algorithm');
        if ($algorithm && $algorithm !== 'sha1') {
            $totp->setDigest($algorithm);
        }
    }
}