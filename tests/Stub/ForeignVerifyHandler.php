<?php

namespace Restruct\MFABundle\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\TOTP\VerifyHandler;

/**
 * A project's own replacement of the upstream TOTP VerifyHandler that does NOT extend the bundle's
 * one, so it checks every login with the library defaults (see TOTPCodeCheckTest).
 */
class ForeignVerifyHandler extends VerifyHandler implements TestOnly
{
}
