<?php

namespace Restruct\MfaBrowser;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;

/**
 * BROWSER-TEST FIXTURE ONLY - GET /admin/mfab-reset/totp makes sure the member
 * mfab-totp@example.com exists with CMS access and NO registered MFA method, so the TOTP spec
 * starts from the registration prompt on every run and every repeat. Answers {"email": ...}.
 *
 * A LeftAndMain because the admin routes those by url_segment with no YAML; LeftAndMain's own
 * access check applies, so only the logged-in admin can call it. See MfaBSeed for why this never
 * loads in a real install.
 */
class MfaBTotpReset extends LeftAndMain
{
    private static $url_segment = 'mfab-reset';

    private static $menu_title = 'MFA browser reset';

    private static $allowed_actions = ['totp'];

    public const EMAIL = 'mfab-totp@example.com';

    public function totp(HTTPRequest $request): HTTPResponse
    {
        $member = Member::get()->filter('Email', self::EMAIL)->first();
        if (!$member) {
            $member = Member::create(['FirstName' => 'TOTP', 'Surname' => 'User', 'Email' => self::EMAIL]);
            $member->write();
            $member->changePassword(MfaBSeed::PASSWORD);
            # The editors group MfaBSeed made: CMS access, so MFA applies.
            Group::get()->filter('Code', 'mfab-editors')->first()->Members()->add($member);
        }
        foreach ($member->RegisteredMFAMethods() as $method) {
            $method->delete();
        }
        $member->DefaultRegisteredMethodID = 0;
        $member->write();

        return HTTPResponse::create(json_encode(['email' => self::EMAIL]))->addHeader('Content-Type', 'application/json');
    }
}
