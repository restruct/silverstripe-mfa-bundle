<?php

namespace Restruct\MFABundle\TOTP;

use OTPHP\TOTPInterface;
use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\MFA\Store\StoreInterface;
use SilverStripe\TOTP\VerifyHandler as UpstreamVerifyHandler;

/**
 * TOTP login check that uses the period, algorithm and digits the member registered with.
 *
 * Upstream checks every login code with a fresh TOTP with the library defaults (30 s / SHA1 /
 * 6 digits), whatever TOTPConfigExtension or Method.code_length say (issues #3 and #7). This
 * subclass replaces the upstream class through the Injector (see _config/mfa-bundle.yml) and
 * overrides the one protected method that builds that TOTP.
 *
 * It deliberately uses the settings stored WITH THE REGISTRATION (by RegisterHandler::register()),
 * not the current config: the member's app keeps making codes with the settings it was set up
 * with. A registration without stored settings was made before this fix, when every code was
 * checked - and so every registration made - with 30 s / SHA1, so those keep the library defaults.
 * Applying a configured period to them would lock those members out. The same holds for the
 * digits, stored since 1.9.0: a registration without them (also one from 1.8.0, which stored only
 * period and digest) was made with 6 digits.
 *
 * start() also gives the login field the registration's number of digits: upstream passes the
 * current Method.code_length, which after a config change would ask for a code of the wrong length.
 */
class VerifyHandler extends UpstreamVerifyHandler
{
    /**
     * Copies the stored period/digest/digits into the store, next to the secret upstream puts
     * there, so getTotp() can apply them when the code comes in (a separate request; the store is
     * saved to the session in between).
     */
    public function start(StoreInterface $store, RegisteredMethod $method): array
    {
        $data = parent::start($store, $method);

        $stored = json_decode((string) $method->Data, true);
        if (is_array($stored)) {
            $store->addState(array_intersect_key($stored, ['period' => true, 'digest' => true, 'digits' => true]));
        }

        # The input field expects the registration's code length, not the current config (#7).
        # Without stored digits the registration was made with 6 (see class doc).
        $data['codeLength'] = is_array($stored) && !empty($stored['digits']) ? (int) $stored['digits'] : 6;

        return $data;
    }

    protected function getTotp(StoreInterface $store): TOTPInterface
    {
        $totp = parent::getTotp($store);
        $state = $store->getState();
        # Absent for registrations made before the fix: keep the library defaults (see class doc).
        if (!empty($state['period'])) {
            $totp->setPeriod((int) $state['period']);
        }
        if (!empty($state['digest'])) {
            $totp->setDigest((string) $state['digest']);
        }
        if (!empty($state['digits'])) {
            $totp->setDigits((int) $state['digits']);
        }
        return $totp;
    }
}
