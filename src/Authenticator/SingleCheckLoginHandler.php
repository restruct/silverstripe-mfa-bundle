<?php

namespace Restruct\MFABundle\Authenticator;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\MFA\Authenticator\LoginHandler;
use SilverStripe\Security\MemberAuthenticator\MemberLoginForm;

/**
 * Opt-in replacement for silverstripe/mfa's LoginHandler that checks the submitted credentials
 * once per login POST instead of twice (silverstripe/silverstripe-mfa#421).
 *
 * MFA's doLogin() runs checkLogin() and, when there is no member (wrong password) or MFA is not
 * required, hands over to the framework's doLogin(), which runs checkLogin() again. Every check
 * records a LoginAttempt and registers a failed or successful login, so a wrong password counts
 * twice and an account locks after half of Member.lock_out_after_incorrect_logins.
 *
 * This class keeps the result of the first check for the rest of that doLogin() call and hands it
 * to the second checkLogin(). All of MFA's and the framework's own doLogin() code still runs, in the
 * same order and with the same extension hooks, so there is no copied login logic to drift.
 *
 * Enable it per project with an Injector replacement (see the README):
 *
 *     SilverStripe\Core\Injector\Injector:
 *       SilverStripe\MFA\Authenticator\LoginHandler:
 *         class: Restruct\MFABundle\Authenticator\SingleCheckLoginHandler
 */
class SingleCheckLoginHandler extends LoginHandler
{
    /**
     * [member or null, ValidationResult] from the one real check, set only while doLogin() runs.
     */
    private ?array $checkedLogin = null;

    public function doLogin($data, MemberLoginForm $form, HTTPRequest $request)
    {
        # The one real check: $checkedLogin is still null here, so this reaches the authenticator.
        $member = $this->checkLogin($data, $request, $result);
        $this->checkedLogin = [$member, $result];

        try {
            # MFA's doLogin() and, through it, the framework's doLogin() now get the stored result.
            return parent::doLogin($data, $form, $request);
        } finally {
            # A later login on the same handler (tests, long-running processes) must check again.
            $this->checkedLogin = null;
        }
    }

    /**
     * $result is left untyped on purpose: the parent types it as SilverStripe\ORM\ValidationResult
     * on Silverstripe 5 and SilverStripe\Core\Validation\ValidationResult on 6. An untyped parameter
     * is a valid override of both.
     */
    public function checkLogin($data, HTTPRequest $request, &$result = null)
    {
        if ($this->checkedLogin !== null) {
            [$member, $result] = $this->checkedLogin;
            return $member;
        }

        return parent::checkLogin($data, $request, $result);
    }
}
