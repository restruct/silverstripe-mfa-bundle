<?php

namespace Restruct\MFABundle\TOTP;

use OTPHP\TOTPInterface;
use Restruct\MFABundle\Extensions\TOTPConfigExtension;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\MFA\State\Result;
use SilverStripe\MFA\Store\StoreInterface;
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
 */
class RegisterHandler extends UpstreamRegisterHandler
{
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
