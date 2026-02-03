<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Extensions;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\MFA\Extension\MemberExtension as BaseMFAMemberExtension;
use SilverStripe\MFA\Model\RegisteredMethod;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * Adds admin interface for managing MFA methods on Member records.
 * Allows admins with MFA_ADMINISTER_REGISTERED_METHODS permission to
 * view and delete MFA methods for any user.
 *
 * @extends DataExtension<Member>
 */
class MemberMFAAdminExtension extends DataExtension
{
    public function updateCMSFields(FieldList $fields): void
    {
        // Only show for existing members
        if (!$this->owner->exists()) {
            return;
        }

        // Check if current user has permission to administer MFA
        if (!Permission::check(BaseMFAMemberExtension::MFA_ADMINISTER_REGISTERED_METHODS)) {
            return;
        }

        // Don't show for current user (they use the regular MFA interface)
        $currentUser = Security::getCurrentUser();
        if ($currentUser && $currentUser->ID === $this->owner->ID) {
            return;
        }

        // Check if user has any registered MFA methods
        $methods = $this->owner->RegisteredMFAMethods();
        if (!$methods->exists()) {
            return;
        }

        // Create GridField for managing MFA methods
        $config = GridFieldConfig_RecordEditor::create();
        // Remove add/edit - admins can only view and delete
        $config->removeComponentsByType(GridFieldAddNewButton::class);
        $config->removeComponentsByType(GridFieldEditButton::class);

        $gridField = GridField::create(
            'AdminMFAMethods',
            _t(__CLASS__ . '.REGISTERED_MFA_METHODS', 'Registered MFA Methods'),
            $methods,
            $config
        );

        $gridField->setDescription(_t(
            __CLASS__ . '.GRIDFIELD_DESCRIPTION',
            'Delete MFA methods to require the user to re-register. The user will need to set up MFA again on their next login.'
        ));

        // Add after the main MFA field if it exists, otherwise at the end
        if ($fields->fieldByName('Root.Main.RegisteredMFAMethodListField')) {
            $fields->insertAfter('RegisteredMFAMethodListField', $gridField);
        } else {
            $fields->addFieldToTab('Root.Main', $gridField);
        }
    }
}