<?php

namespace Restruct\MfaBrowser;

use Restruct\MFABundle\Extensions\TOTPConfigExtension;
use SilverStripe\Control\Cookie;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Config\Config;

/**
 * BROWSER-TEST FIXTURE ONLY - lets one spec register TOTP with the bundle's TOTPConfigExtension
 * configured, without changing what every other spec sees: a request carrying the cookie
 * mfab-browser-variant=issuer gets TOTPConfigExtension.issuer = 'Acme Browser CMS', and =period60
 * gets TOTPConfigExtension.period = 60, for that request only, as if a project had set it in YAML. Registered as a Director middleware by fixtures/_config/variant.yml.
 * See MfaBSeed for why this never loads in a real install.
 */
class MfaBVariantMiddleware implements HTTPMiddleware
{
    public function process(HTTPRequest $request, callable $delegate)
    {
        $variant = Cookie::get('mfab-browser-variant');
        if ($variant === 'issuer') {
            Config::modify()->set(TOTPConfigExtension::class, 'issuer', 'Acme Browser CMS');
        }
        if ($variant === 'period60') {
            Config::modify()->set(TOTPConfigExtension::class, 'period', 60);
        }
        return $delegate($request);
    }
}
