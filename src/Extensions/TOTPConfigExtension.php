<?php

namespace Restruct\MFABundle\Extensions;

use OTPHP\TOTP;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
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
     * Fallback chain: explicit issuer config -> SiteConfig::Title -> LeftAndMain.application_name
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
     * Environment label added to the issuer, so a token registered on a dev or test site can be
     * told apart from the live one in an authenticator app (the issuer is the title most apps show).
     *
     * - null (default): automatic. Environment type 'dev' gives 'DEV', 'test' gives 'TEST'. Any
     *   other value ('live', or an unexpected SS_ENVIRONMENT_TYPE such as 'Live') gets no label.
     * - a string: always use this label, also on live (e.g. 'STAGING').
     * - false: never add a label.
     *
     * The SS_MFA_TOTP_ENVIRONMENT_LABEL environment variable, when set, wins over this setting;
     * set but empty (`VAR=`, `VAR=""`) or `VAR=false` it disables the label.
     *
     * The label is baked into the token when it is registered: changing it later does not rename
     * tokens that already exist.
     */
    private static string|false|null $environment_label = null;

    /**
     * sprintf() format combining the issuer (first argument) and the environment label (second),
     * e.g. '%s (%s)' gives "My Site (DEV)". Positional arguments work too: '[%2$s] %1$s'.
     */
    private static string $environment_label_format = '%s (%s)';

    /**
     * Environment variable that overrides the environment_label config.
     */
    public const ENV_LABEL_VAR = 'SS_MFA_TOTP_ENVIRONMENT_LABEL';

    /**
     * Format used when the configured one cannot be applied (throws, or yields an empty issuer).
     */
    private const DEFAULT_LABEL_FORMAT = '%s (%s)';

    /**
     * Environment types that get an automatic label. Compared strictly: Director returns the raw
     * SS_ENVIRONMENT_TYPE value when the kernel has none set, so 'Live' or 'staging' can occur,
     * and labelling those would put e.g. "(LIVE)" on production tokens.
     */
    private const AUTO_LABEL_ENVIRONMENTS = ['dev', 'test'];

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

        # Runs after the issuer fallback chain above, so the label is added to whichever issuer won.
        $this->applyEnvironmentLabel($totp);

        $period = $this->config()->get('period');
        if ($period && $period !== 30) {
            $totp->setPeriod($period);
        }

        $algorithm = $this->config()->get('algorithm');
        if ($algorithm && $algorithm !== 'sha1') {
            $totp->setDigest($algorithm);
        }
    }

    /**
     * The environment label to add to the issuer, or null for none.
     *
     * The env var is checked with hasEnv() rather than a test on getEnv()'s return value: getEnv()
     * returns false both for "never set" and for an explicit false, so only hasEnv() can tell an
     * override that switches the label off from an absent one (= use config). Identical on
     * framework 5 and 6.
     *
     * What an "off" override looks like depends on how it arrived. EnvironmentLoader parses .env
     * with m1/env (2.2.0), which turns `VAR=` into null, `VAR=false` into bool false and `VAR=""`
     * into '', and stores those with setEnv(); a real (server) environment variable set to empty
     * arrives as ''. All of them mean "off", never "fall back to config".
     */
    public function getEnvironmentLabel(): ?string
    {
        $label = null;
        $useConfig = true;

        if (Environment::hasEnv(self::ENV_LABEL_VAR)) {
            $envValue = Environment::getEnv(self::ENV_LABEL_VAR);
            $useConfig = false;
            # null / false / blank: the variable is set but switches the label off (see above).
            if ($envValue === null || $envValue === false || trim((string) $envValue) === '') {
                return null;
            }
            # m1/env may also yield true or a number (`VAR=true`, `VAR=2`); use them as text.
            $label = is_bool($envValue) ? ($envValue ? 'TRUE' : '') : (string) $envValue;
        }

        if ($useConfig) {
            $configured = $this->config()->get('environment_label');
            if ($configured === false) {
                return null;
            }
            if (is_string($configured)) {
                $label = $configured;
            } else {
                # Automatic: only the known non-production types get a label.
                $envType = (string) Director::get_environment_type();
                $label = in_array($envType, self::AUTO_LABEL_ENVIRONMENTS, true) ? strtoupper($envType) : null;
            }
        }

        if ($label === null) {
            return null;
        }

        $label = $this->sanitiseIssuer($label);

        return $label !== '' ? $label : null;
    }

    /**
     * Add the environment label to the TOTP issuer.
     */
    protected function applyEnvironmentLabel(TOTP $totp): void
    {
        $label = $this->getEnvironmentLabel();
        if ($label === null) {
            return;
        }

        $issuer = (string) $totp->getIssuer();
        if ($issuer === '') {
            # No issuer at all: the label alone is still better than an anonymous token.
            # Reachable only through the hook itself (and the tests): upstream RegisterHandler
            # calls setIssuer(SiteConfig Title) before firing it, and an empty or null title
            # already fails there. Kept so the hook is safe whatever calls it.
            $totp->setIssuer($label);
            return;
        }

        $format = (string) $this->config()->get('environment_label_format');
        $labelled = $this->formatIssuer($format, $issuer, $label);
        # A format that renders but leaves the label out ('%s', '%1$s') would silently disable the
        # feature; treat it like a broken one.
        if ($labelled === null || $labelled === '' || $labelled === $this->sanitiseIssuer($issuer)) {
            # A broken custom format must never break registration: fall back to the default.
            $format = self::DEFAULT_LABEL_FORMAT;
            $labelled = $this->formatIssuer($format, $issuer, $label);
        }

        # Do not label twice, e.g. when the configured issuer already reads "My Site (DEV)".
        # The affix is the format rendered with an empty issuer, trimmed, so it matches both a
        # suffix format ('%s (%s)' -> '(DEV)') and a prefix format ('[%2$s] %1$s' -> '[DEV]').
        $affix = trim((string) $this->formatIssuer($format, '', $label));
        if ($affix !== '' && (str_ends_with($issuer, $affix) || str_starts_with($issuer, $affix))) {
            return;
        }

        if ($labelled !== null && $labelled !== '') {
            $totp->setIssuer($labelled);
        }
    }

    /**
     * Render the label format, sanitised for use as an issuer. Null when the format is unusable.
     */
    private function formatIssuer(string $format, string $issuer, string $label): ?string
    {
        if ($format === '') {
            return null;
        }
        try {
            $result = sprintf($format, $issuer, $label);
        } catch (\ValueError|\ArgumentCountError $e) {
            # e.g. '%s %s %s' (too few arguments) or a malformed conversion spec.
            return null;
        }

        return $this->sanitiseIssuer($result);
    }

    /**
     * Make a string safe to use as an otpauth issuer.
     *
     * The issuer ends up both in the otpauth label ("issuer:account") and in the issuer parameter.
     * spomky-labs/otphp throws InvalidLabelException from setIssuer() for an empty issuer or one
     * containing ':' (also its encoded forms '%3A'/'%3a'), so a colon in a label or format would
     * make TOTP registration fail outright. Strip those, then tidy the whitespace left behind.
     */
    private function sanitiseIssuer(string $value): string
    {
        $value = str_ireplace(['%3A', ':'], ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
