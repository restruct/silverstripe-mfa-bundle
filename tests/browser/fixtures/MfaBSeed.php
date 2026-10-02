<?php

namespace Restruct\MfaBrowser;

use SilverStripe\MFA\BackupCode\Method as BackupCodeMethod;
use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * BROWSER-TEST FIXTURE ONLY - seeds the members the specs need, on every dev/build:
 *  - an editor (CMS access, no MFA method yet) who logs in through the real form and meets the
 *    MFA prompt;
 *  - a few members with a registered MFA method, for the admin's "Registered MFA Methods" grid
 *    (one per spec repeat that deletes one).
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it into a scratch host's app/ before dev/build.
 * A DataObject only because requireDefaultRecords() is the dev/build hook; its own table stays
 * empty.
 */
class MfaBSeed extends DataObject
{
    # Short table name: no namespaced default.
    private static $table_name = 'MfaBSeed';

    public const EDITOR_EMAIL = 'mfab-editor@example.com';

    public const PASSWORD = 'Browser-Test-Pass-123!';

    # Members with a registered method, by email; the specs take the first one that still has it.
    public const WITH_METHOD = [
        'mfab-keyholder-1@example.com',
        'mfab-keyholder-2@example.com',
        'mfab-keyholder-3@example.com',
        'mfab-keyholder-4@example.com',
        'mfab-keyholder-5@example.com',
    ];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        # Start every run from the same state: drop the seeded members (and their methods).
        foreach (Member::get()->filter('Email:StartsWith', 'mfab-') as $old) {
            foreach ($old->RegisteredMFAMethods() as $method) {
                $method->delete();
            }
            $old->delete();
        }

        # The editor gets CMS access (MFA is only enforced for members who can reach the CMS)
        # through a group of its own: the default groups may not exist yet when this runs.
        $group = Group::get()->filter('Code', 'mfab-editors')->first();
        if (!$group) {
            $group = Group::create(['Title' => 'MFA browser editors', 'Code' => 'mfab-editors']);
            $group->write();
            Permission::grant($group->ID, 'CMS_ACCESS_CMSMain');
        }
        $editor = Member::create([
            'FirstName' => 'MFA',
            'Surname' => 'Editor',
            'Email' => self::EDITOR_EMAIL,
        ]);
        $editor->write();
        $editor->changePassword(self::PASSWORD);
        $group->Members()->add($editor);

        foreach (self::WITH_METHOD as $i => $email) {
            $member = Member::create([
                'FirstName' => 'Keyholder',
                'Surname' => (string) ($i + 1),
                'Email' => $email,
            ]);
            $member->write();
            # A backup-code method: the one method that needs neither a secret key nor a browser
            # credential to exist. Data is irrelevant to the admin grid.
            $method = RegisteredMethod::create([
                'MethodClassName' => BackupCodeMethod::class,
                'Data' => '[]',
                'MemberID' => $member->ID,
            ]);
            $method->write();
        }
    }
}
