<?php

declare(strict_types=1);

namespace Restruct\MFABundle\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;

/**
 * Simple controller to serve MFA help pages.
 * Access via /mfa-help/totp, /mfa-help/webauthn, etc.
 *
 * Content is translatable via lang files using markdown syntax.
 */
class MFAHelpController extends Controller
{
    private static string $url_segment = 'mfa-help';

    private static array $allowed_actions = [
        'index',
        'totp',
        'webauthn',
        'backupcodes',
    ];

    private static array $url_handlers = [
        '' => 'index',
        'totp' => 'totp',
        'webauthn' => 'webauthn',
        'backup-codes' => 'backupcodes',
    ];

    protected function init(): void
    {
        parent::init();

        // Use logged-in user's locale, fall back to site default
        $member = Security::getCurrentUser();
        if ($member && $member->Locale) {
            i18n::set_locale($member->Locale);
        } else {
            i18n::set_locale(i18n::config()->get('default_locale'));
        }

        // Prevent indexing of help pages
        $this->getResponse()->addHeader('X-Robots-Tag', 'noindex, nofollow');

        // Help page styling
        Requirements::css('restruct/silverstripe-mfa-bundle: client/dist/css/mfa-help.css');
    }

    public function index(HTTPRequest $request): HTTPResponse|array
    {
        return $this->renderHelp('INDEX');
    }

    public function totp(HTTPRequest $request): HTTPResponse|array
    {
        return $this->renderHelp('TOTP');
    }

    public function webauthn(HTTPRequest $request): HTTPResponse|array
    {
        return $this->renderHelp('WEBAUTHN');
    }

    public function backupcodes(HTTPRequest $request): HTTPResponse|array
    {
        return $this->renderHelp('BACKUPCODES');
    }

    protected function renderHelp(string $key): array
    {
        $markdown = _t(__CLASS__ . '.' . $key, $this->getDefaultContent($key));
        $html = $this->parseMarkdown($markdown);

        // Extract title from first h1
        $title = '';
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $matches)) {
            $title = strip_tags($matches[1]);
        }

        return [
            'Title' => $title ?: 'MFA Help',
            'Content' => $html,
            'Navigation' => $this->getNavigation($key),
        ];
    }

    /**
     * Simple markdown to HTML parser for basic formatting.
     */
    protected function parseMarkdown(string $markdown): string
    {
        $html = htmlspecialchars($markdown, ENT_NOQUOTES, 'UTF-8');

        // Headers (must be at start of line)
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Bold and italic
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

        // Unordered lists
        $html = preg_replace_callback('/(?:^- .+$\n?)+/m', function ($matches) {
            $items = preg_replace('/^- (.+)$/m', '<li>$1</li>', trim($matches[0]));
            return "<ul>\n{$items}\n</ul>\n";
        }, $html);

        // Ordered lists
        $html = preg_replace_callback('/(?:^\d+\. .+$\n?)+/m', function ($matches) {
            $items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', trim($matches[0]));
            return "<ol>\n{$items}\n</ol>\n";
        }, $html);

        // Paragraphs (text blocks separated by blank lines, not already wrapped)
        $html = preg_replace('/(?<![>\n])(\n\n)(?![<\n])/', '</p>$1<p>', $html);

        // Wrap content in paragraphs if it starts with text
        if (!preg_match('/^<[holu]/', trim($html))) {
            $html = '<p>' . $html;
        }
        if (!preg_match('/<\/[holu].*>$/', trim($html))) {
            $html .= '</p>';
        }

        // Clean up empty paragraphs and fix spacing
        $html = preg_replace('/<p>\s*<\/p>/', '', $html);
        $html = preg_replace('/<p>\s*<([holu])/','<$1', $html);
        $html = preg_replace('/<\/([holu][l1-3]?)>\s*<\/p>/','</$1>', $html);

        return trim($html);
    }

    /**
     * Default English content (fallback).
     */
    protected function getDefaultContent(string $key): string
    {
        $content = [
            'INDEX' => <<<'MD'
# Two-Factor Authentication (MFA)

Two-factor authentication (MFA) adds an extra layer of security to your account. In addition to your password, you need a second factor to log in.

## Available Methods

- **Authenticator app** - Use an app like Google Authenticator or 1Password
- **Security key** - Use Touch ID, Face ID, or a USB key

## Backup Codes

When setting up MFA, you receive backup codes. Store these safely - you can use them if you don't have access to your normal verification method.
MD,
            'TOTP' => <<<'MD'
# Setting Up Authenticator App

## What Do You Need?

An authenticator app on your phone, for example:

- Google Authenticator (free)
- Microsoft Authenticator (free)
- 1Password, Bitwarden, or Authy

## Setup

1. Open the authenticator app on your phone
2. Choose "Add account" or the + icon
3. Scan the QR code that appears on screen
4. Enter the 6-digit code shown in the app

## Logging In

When logging in, open the app and enter the current 6-digit code. The code changes every 30 seconds.

## Problems?

- **Code not working?** Check if your phone's time is set correctly
- **Lost phone?** Use your backup codes or contact the administrator
MD,
            'WEBAUTHN' => <<<'MD'
# Setting Up Security Key

## What Is a Security Key?

A security key is a secure way to log in using:

- **Touch ID / Face ID** - Built into your Mac, iPhone or iPad
- **Windows Hello** - Fingerprint or facial recognition on Windows
- **USB key** - A physical key like YubiKey

## Setup

1. Click "Add security key"
2. Your browser asks which type of key you want to use
3. Choose "This device" for Touch ID/Face ID, or connect your USB key
4. Confirm with your fingerprint, face, or by touching the key

## Passkeys (Synced Keys)

Modern browsers can sync your key via iCloud or Google:

- **iCloud Keychain** - Works on all your Apple devices
- **Google Password Manager** - Works in Chrome on all devices

This way you can use the same key on multiple devices.

## Multiple Devices

You can register multiple security keys. Useful if you log in from different devices or want a backup.
MD,
            'BACKUPCODES' => <<<'MD'
# Backup Codes

## What Are Backup Codes?

Backup codes are one-time codes you can use if you don't have access to your normal verification method (phone lost, broken, etc.).

## Important

- Each code only works once
- Store them in a safe place (not on your phone!)
- Print them or save them in a password manager

## Lost Codes?

If you've used all your backup codes or lost them, you can create new ones in your profile settings. The old codes will then become invalid.

## Completely Locked Out?

Contact the administrator. They can reset your MFA settings so you can set up again.
MD,
        ];

        return $content[$key] ?? '';
    }

    protected function getNavigation(string $currentKey): string
    {
        $pages = [
            'INDEX' => ['url' => '', 'label' => _t(__CLASS__ . '.NAV_OVERVIEW', 'Overview')],
            'TOTP' => ['url' => 'totp', 'label' => _t(__CLASS__ . '.NAV_TOTP', 'Authenticator App')],
            'WEBAUTHN' => ['url' => 'webauthn', 'label' => _t(__CLASS__ . '.NAV_WEBAUTHN', 'Security Key')],
            'BACKUPCODES' => ['url' => 'backup-codes', 'label' => _t(__CLASS__ . '.NAV_BACKUPCODES', 'Backup Codes')],
        ];

        $links = [];
        foreach ($pages as $key => $page) {
            $url = $this->Link($page['url']);
            if ($key === $currentKey) {
                $links[] = "<strong>{$page['label']}</strong>";
            } else {
                $links[] = "<a href=\"{$url}\">{$page['label']}</a>";
            }
        }

        return implode("\n", $links);
    }

    public function Link($action = null): string
    {
        return Controller::join_links(
            $this->config()->get('url_segment'),
            $action
        );
    }
}