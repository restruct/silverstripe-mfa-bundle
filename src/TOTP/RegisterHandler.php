<?php

namespace Restruct\MFABundle\TOTP;

use OTPHP\TOTPInterface;
use Restruct\MFABundle\Extensions\TOTPConfigExtension;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\MFA\State\Result;
use SilverStripe\MFA\Store\StoreInterface;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\TOTP\Method;
use SilverStripe\TOTP\RegisterHandler as UpstreamRegisterHandler;

/**
 * TOTP registration that checks the code with the configured period and algorithm.
 *
 * Upstream applies TOTPConfigExtension (the updateTotp hook) only to the TOTP object behind the
 * QR code in start(). register() builds a fresh TOTP through TOTPAware::getTotp() with the library
 * defaults (30 s / SHA1), so with any other period or algorithm the code the app makes from the QR
 * code was always refused (issue #3). There is no hook in getTotp(), so this subclass overrides
 * that one protected method; it replaces the upstream class through the Injector (see
 * _config/mfa-bundle.yml), which is how SilverStripe\TOTP\Method creates its handlers.
 *
 * The settings the code was checked with are stored with the registration, so a later change of
 * the config does not lock out members whose app was set up with the old settings
 * (see VerifyHandler).
 *
 * start() is overridden as well, for a SiteConfig title that is not a valid issuer (upstream
 * silverstripe-totp-authenticator#106 and #64): see start().
 */
class RegisterHandler extends UpstreamRegisterHandler
{
    /**
     * Upstream start() passes the SiteConfig title to setIssuer() BEFORE the updateTotp hook, so
     * no extension can repair it: an empty title is null under strict_types (TypeError), '' throws
     * "Issuer must not be empty" on otphp 11.5, and a title with a colon throws "Issuer must not
     * contain a colon". Each is a 500 on registration, so members cannot set up an app.
     *
     * When the title is a valid issuer, upstream start() runs unchanged, so any later upstream
     * change to start() still reaches every normal site. Only for an unusable title does this
     * class build the registration itself (startWithSafeIssuer()).
     */
    public function start(StoreInterface $store): array
    {
        $title = SiteConfig::current_site_config()->Title;
        if (static::isValidIssuer($title)) {
            return parent::start($store);
        }

        return $this->startWithSafeIssuer($store, $title);
    }

    /**
     * Whether upstream start() can pass this title to setIssuer() without an exception: the
     * same rule otphp applies (a non-empty string, no ':' and no '%3A' in either case).
     */
    protected static function isValidIssuer(mixed $title): bool
    {
        return is_string($title)
            && $title !== ''
            && TOTPConfigExtension::sanitiseIssuer($title) !== ''
            && stripos($title, ':') === false
            && stripos($title, '%3A') === false;
    }

    /**
     * Upstream RegisterHandler::start() (identical in totp-authenticator 5.3 and 6.1), with only
     * the setIssuer() line changed. Keep in step with upstream: compare this method with
     * parent::start() when updating silverstripe/totp-authenticator, and drop it once upstream #106
     * is fixed.
     */
    protected function startWithSafeIssuer(StoreInterface $store, mixed $title): array
    {
        $store->setState([
            'secret' => $this->generateSecret(),
        ]);

        $totp = $this->getTotp($store);

        $member = $store->getMember() ?: Security::getCurrentUser();
        if ($member) {
            $uniqueIdentifier = (string) Member::config()->get('unique_identifier_field');
            $totp->setLabel($member->{$uniqueIdentifier});
        }
        # The one change from upstream: the title with colons removed, and no issuer at all
        # rather than an empty one. TOTPConfigExtension::updateTotp() below then fills an empty
        # issuer from LeftAndMain.application_name (or its `issuer` config), so that fallback
        # lives in one place, and adds the environment label.
        $issuer = TOTPConfigExtension::sanitiseIssuer((string) $title);
        if ($issuer !== '') {
            $totp->setIssuer($issuer);
        }

        # Same hook as upstream, so TOTPConfigExtension (issuer config, environment label, period,
        # algorithm) and any project extension still apply.
        $this->extend('updateTotp', $totp, $member);

        return [
            'enabled' => !empty(Environment::getEnv('SS_MFA_SECRET_KEY')),
            'uri' => $totp->getProvisioningUri(),
            'code' => $totp->getSecret(),
            'codeLength' => Injector::inst()->create(Method::class)->getCodeLength(),
        ];
    }

    /**
     * Used for the QR code in start() AND for checking the code in register(), so both agree.
     */
    protected function getTotp(StoreInterface $store): TOTPInterface
    {
        $totp = parent::getTotp($store);
        $totp->setPeriod(TOTPConfigExtension::getConfiguredPeriod());
        $totp->setDigest(TOTPConfigExtension::getConfiguredDigest());
        return $totp;
    }

    /**
     * Adds the period and digest to the context, which RegisteredMethodManager stores as the
     * RegisteredMethod's Data next to the encrypted secret.
     */
    public function register(HTTPRequest $request, StoreInterface $store): Result
    {
        $result = parent::register($request, $store);
        if (!$result->isSuccessful()) {
            return $result;
        }

        return $result->setContext(array_merge($result->getContext(), [
            'period' => TOTPConfigExtension::getConfiguredPeriod(),
            'digest' => TOTPConfigExtension::getConfiguredDigest(),
        ]));
    }
}
