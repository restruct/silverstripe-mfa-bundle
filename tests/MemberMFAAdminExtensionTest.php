<?php

namespace Restruct\MFABundle\Tests;

use Restruct\MFABundle\Extensions\MemberMFAAdminExtension;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Behavioural tests for MemberMFAAdminExtension: the "Registered MFA Methods" GridField an MFA
 * administrator sees on another member's record.
 *
 * Compatibility note: runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6).
 */
class MemberMFAAdminExtensionTest extends SapphireTest
{
    protected static $fixture_file = 'MemberMFAAdminExtensionTest.yml';

    private function adminGridFor(Member $member): ?GridField
    {
        $field = $member->getCMSFields()->dataFieldByName('AdminMFAMethods');

        return $field instanceof GridField ? $field : null;
    }

    public function testExtensionIsAppliedToMember()
    {
        $this->assertTrue(Member::singleton()->hasExtension(MemberMFAAdminExtension::class));
    }

    public function testAdministratorSeesAViewAndDeleteGridOfTheMembersMethods()
    {
        $this->logInWithPermission('ADMIN');
        $member = $this->objFromFixture(Member::class, 'withMethods');

        $grid = $this->adminGridFor($member);

        $this->assertNotNull($grid, 'GridField shown for another member who has MFA methods');
        $this->assertSame(RegisteredMethod::class, $grid->getModelClass());
        $this->assertSame(1, $grid->getList()->count());
        $config = $grid->getConfig();
        $this->assertNull($config->getComponentByType(GridFieldAddNewButton::class), 'no add button');
        $this->assertNull($config->getComponentByType(GridFieldEditButton::class), 'no edit button');
        $this->assertNotNull($config->getComponentByType(GridFieldDeleteAction::class), 'delete kept');
    }

    public function testGridIsPlacedDirectlyAfterTheUpstreamMfaSettingsField()
    {
        $this->logInWithPermission('ADMIN');
        $member = $this->objFromFixture(Member::class, 'withMethods');

        $names = [];
        foreach ($member->getCMSFields()->findTab('Root.Main')->Fields() as $field) {
            $names[] = $field->getName();
        }

        $upstream = array_search('MFASettings', $names, true);
        $this->assertNotFalse($upstream, 'the upstream MFA module adds its MFASettings field');
        $this->assertSame('AdminMFAMethods', $names[$upstream + 1] ?? null);
    }

    public function testNoGridWithoutTheAdministerPermission()
    {
        $this->logInWithPermission('CMS_ACCESS_SecurityAdmin');
        $member = $this->objFromFixture(Member::class, 'withMethods');

        $this->assertNull($this->adminGridFor($member));
    }

    public function testNoGridForAMemberWithoutMethods()
    {
        $this->logInWithPermission('ADMIN');
        $member = $this->objFromFixture(Member::class, 'withoutMethods');

        $this->assertNull($this->adminGridFor($member));
    }

    public function testNoGridOnYourOwnRecord()
    {
        # An administrator, so only the "own record" rule can hide the grid.
        $this->logInWithPermission('ADMIN');
        $admin = Security::getCurrentUser();
        RegisteredMethod::create([
            'MethodClassName' => 'SilverStripe\\TOTP\\Method',
            'Data' => '{}',
            'MemberID' => $admin->ID,
        ])->write();

        # Re-read from the database, as the CMS would, rather than reuse the session's object.
        $ownRecord = Member::get()->byID($admin->ID);
        $this->assertSame(1, $ownRecord->RegisteredMFAMethods()->count(), 'control: has a method');

        $this->assertNull($this->adminGridFor($ownRecord));
    }
}
