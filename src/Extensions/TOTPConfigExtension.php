<?php

namespace Restruct\MFABundle\Extensions;

use OTPHP\TOTP;
use SilverStripe\Admin\LeftAndMain;
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
     * Fallback chain: explicit issuer config → SiteConfig::Title → LeftAndMain.application_name
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
        } elseif (!$totp->getIssuer()) {
            # RegisterHandler sets SiteConfig::Title as issuer, but that may be empty
            # (e.g. projects without SiteTree/CMS). Fall back to LeftAndMain.application_name.
            $appName = LeftAndMain::config()->get('application_name');
            if ($appName) {
                $totp->setIssuer($appName);
            }
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