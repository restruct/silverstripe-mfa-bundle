<?php

namespace Restruct\MFABundle\Authenticator;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\MFA\Authenticator\ChangePasswordHandler as MFAChangePasswordHandler;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Closes the multi-factor bypass in the password reset flow of silverstripe/mfa 5
 * (CVE-2026-55186, disclosed without an upstream fix for CMS 5).
 *
 * silverstripe/mfa asks for the second factor in changepassword(), which only handles the GET that
 * shows the form. On CMS 5 the reset link stores the member's AutoLoginHash in the session before
 * that check runs, so a POST straight to the form's doChangePassword() action changed the password
 * and logged the member in without ever reaching it. This class refuses that POST until the member
 * the reset link belongs to has completed the multi-factor check, and sends them back to it.
 *
 * It also binds that check to the member: upstream records a successful check as a plain session
 * flag, so a check completed for one account counted for any other reset link opened later in the
 * same session. The flag is now honoured only for the member it was set for.
 *
 * The advisory states CMS 6 is not affected. The class is applied on both majors all the same: the
 * POST check only refuses what upstream's own GET check would refuse, and the member binding
 * applies to both.
 *
 * Replaces the upstream class through the Injector (see _config/mfa-bundle.yml); upstream's
 * MemberAuthenticator::getChangePasswordHandler() creates it with ::create().
 */
class ChangePasswordHandler extends MFAChangePasswordHandler
{
    /**
     * Session key holding the ID of the member the multi-factor check was completed for.
     */
    public const MFA_VERIFIED_MEMBER_ID = 'MultiFactorAuthenticatedMemberID';

    /**
     * Actions overridden here must be declared again: allowed_actions is checked, uninherited, on
     * the class that declares the method, so the parent's entries do not cover these overrides.
     * doChangePassword() is a form action and is allowed through the form's own actions.
     */
    private static $allowed_actions = [
        'changepassword',
        'verifyMFACheck',
    ];

    public function changepassword()
    {
        $this->dropVerificationForOtherMember();
        return parent::changepassword();
    }

    public function verifyMFACheck(HTTPRequest $request): HTTPResponse
    {
        # The store is cleared by the parent once the check completes, so read the member first.
        $store = $this->getStore();
        $member = $store ? $store->getMember() : null;

        $response = parent::verifyMFACheck($request);

        $session = $request->getSession();
        if ($response->getStatusCode() === 200
            && $member
            && $session->get(MFAChangePasswordHandler::MFA_VERIFIED_ON_CHANGE_PASSWORD)
        ) {
            $session->set(static::MFA_VERIFIED_MEMBER_ID, (int) $member->ID);
        }

        return $response;
    }

    public function doChangePassword(array $data, $form)
    {
        $this->dropVerificationForOtherMember();

        # A logged-in member changing their own password has already passed the multi-factor check
        # at login, and the parent asks for their current password; only the reset flow is gated.
        $member = $this->getResetMember();
        if (!Security::getCurrentUser()
            && $member
            && $member->RegisteredMFAMethods()->exists()
            && !$this->isVerifiedFor($member)
        ) {
            # Back to changepassword(), which starts the multi-factor check for this member.
            return $this->redirect($this->Link());
        }

        return parent::doChangePassword($data, $form);
    }

    /**
     * The member the reset link in this session belongs to, if any.
     */
    protected function getResetMember(): ?Member
    {
        $hash = $this->getRequest()->getSession()->get('AutoLoginHash');
        if (!$hash) {
            return null;
        }
        return Member::member_from_autologinhash($hash);
    }

    /**
     * Whether the multi-factor check of this session was completed for the given member.
     */
    protected function isVerifiedFor(Member $member): bool
    {
        $session = $this->getRequest()->getSession();
        return (bool) $session->get(MFAChangePasswordHandler::MFA_VERIFIED_ON_CHANGE_PASSWORD)
            && (int) $session->get(static::MFA_VERIFIED_MEMBER_ID) === (int) $member->ID;
    }

    /**
     * Forget a completed multi-factor check that was not made for the member of the current reset
     * link, so upstream's own check (which reads only the flag) asks again.
     */
    protected function dropVerificationForOtherMember(): void
    {
        $session = $this->getRequest()->getSession();
        if (!$session->get(MFAChangePasswordHandler::MFA_VERIFIED_ON_CHANGE_PASSWORD)) {
            return;
        }
        $member = $this->getResetMember();
        if ($member && !$this->isVerifiedFor($member)) {
            $session->clear(MFAChangePasswordHandler::MFA_VERIFIED_ON_CHANGE_PASSWORD);
            $session->clear(static::MFA_VERIFIED_MEMBER_ID);
        }
    }
}
