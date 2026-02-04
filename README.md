# Silverstripe MFA Bundle

Portable MFA bundle for Silverstripe 5 with TOTP (Google Authenticator) and WebAuthn (security keys/biometrics) support.

## Quick Start

1. `composer require restruct/silverstripe-mfa-bundle`
2. Add `SS_MFA_SECRET_KEY` to `.env`, run `dev/build`

That's it. MFA is enforced with a 6-month grace period out of the box.

## Features

- Bundles `silverstripe/mfa`, `silverstripe/totp-authenticator`, and `silverstripe/webauthn-authenticator`
- Configurable TOTP settings (issuer name, period, algorithm)
- WebAuthn configured to allow both biometrics (Touch ID) and security keys
- Admin interface to reset/remove user MFA methods
- Sensible defaults: requires at least 1 MFA method

## Requirements

- Silverstripe ^5.0
- PHP ^8.1
- `ext-bcmath` PHP extension (required by WebAuthn)

## Installation

```bash
composer require restruct/silverstripe-mfa-bundle
```

## Configuration

### 1. Set encryption key (required)

Add to your `.env`:

```env
SS_MFA_SECRET_KEY="your-secure-random-key-here"
```

Generate a key:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

### 2. Configure TOTP settings (optional)

Create `app/_config/mfa.yml`:

```yaml
# Settings via this bundle's extension
Restruct\MFABundle\Extensions\TOTPConfigExtension:
  issuer: 'My Company CMS'      # Name shown in authenticator apps
  period: 30                     # Seconds per code (default: 30)
  algorithm: 'sha1'              # sha1, sha256, sha512 (default: sha1)

# Settings on SilverStripe's TOTP classes (set directly)
SilverStripe\TOTP\Method:
  code_length: 6                 # Number of digits (default: 6)

SilverStripe\TOTP\RegisterHandler:
  secret_length: 16              # Length of generated secret (default: 16)
  user_help_link: 'https://example.com/mfa-help'  # Help link during setup
```

### 3. Configure WebAuthn settings (optional)

By default, this bundle allows both platform authenticators (Touch ID, Windows Hello, Face ID) and cross-platform authenticators (USB security keys). You can restrict this:

```yaml
SilverStripe\WebAuthn\RegisterHandler:
  # null = both types (default set by this bundle)
  # 'platform' = biometrics only (Touch ID, Windows Hello)
  # 'cross-platform' = security keys only (SilverStripe default)
  authenticator_attachment: ~

  # Custom help link shown during setup
  user_help_link: 'https://example.com/webauthn-help'
```

**Note:** WebAuthn requires HTTPS and a supported browser (Chrome, Firefox, Safari, Edge).


### 4. MFA requirement (enabled by default)

This bundle automatically enables "MFA Required" on first `dev/build` and hides the SiteConfig MFA settings. Users will be prompted to set up MFA on their next login.

A **grace period of 6 months** is set by default, allowing users to skip MFA setup temporarily. After the grace period expires, MFA becomes mandatory.

```yaml
# Customize grace period (default: 180 days = 6 months)
Restruct\MFABundle\Extensions\SiteConfigMFAExtension:
  grace_period_days: 90   # 3 months
  # grace_period_days: 0  # No grace period - MFA required immediately
```

To show the MFA settings in SiteConfig (for manual control):

```yaml
Restruct\MFABundle\Extensions\SiteConfigMFAExtension:
  show_mfa_settings: true
```

### 5. Disable during development (optional)

Add to `.env`:

```env
BYPASS_MFA=1
```

## Configuration Reference

### Bundle settings

| Class | Setting | Default | Description |
|-------|---------|---------|-------------|
| `SiteConfigMFAExtension` | `grace_period_days` | 180 | Days users can skip MFA (0 = no grace period) |
| `SiteConfigMFAExtension` | `show_mfa_settings` | false | Show MFA fields in SiteConfig |
| `TOTPConfigExtension` | `issuer` | SiteConfig Title | App name shown in authenticator |
| `TOTPConfigExtension` | `period` | 30 | Seconds per code |
| `TOTPConfigExtension` | `algorithm` | sha1 | Hash algorithm (sha1/sha256/sha512) |

### SilverStripe TOTP settings (set directly on SS classes)

| Setting | Class | Default | Description |
|---------|-------|---------|-------------|
| `code_length` | `Method` | 6 | Number of digits (6-8) |
| `secret_length` | `RegisterHandler` | 16 | Secret key length |

### SilverStripe WebAuthn settings (set directly on SS classes)

| Setting | Class | Default | Description |
|---------|-------|---------|-------------|
| `authenticator_attachment` | `RegisterHandler` | `null`* | Allowed authenticator types |

*This bundle sets the default to `null` (allow both). SilverStripe's default is `cross-platform` (security keys only).

### Help link settings (set by this bundle)

| Class | Setting | Default |
|-------|---------|---------|
| `SilverStripe\TOTP\RegisterHandler` | `user_help_link` | `/mfa-help/totp` |
| `SilverStripe\WebAuthn\RegisterHandler` | `user_help_link` | `/mfa-help/webauthn` |
| `SilverStripe\MFA\Authenticator\LoginHandler` | `user_help_link` | `/mfa-help/` |
| `SilverStripe\MFA\BackupCode\RegisterHandler` | `user_help_link` | `/mfa-help/backup-codes` |

Override these if you use a custom URL segment or external help pages.

**Authenticator attachment options:**
- `~` or `null`: Both platform and cross-platform (recommended)
- `'platform'`: Only biometrics (Touch ID, Windows Hello, Face ID)
- `'cross-platform'`: Only USB/NFC security keys

### MFA enforcement

| Setting | Class | Default | Description |
|---------|-------|---------|-------------|
| `required_mfa_methods` | `EnforcementManager` | 1 | Minimum methods required |

## How it works

### TOTP (Authenticator App)
1. A secret is generated and encrypted with `SS_MFA_SECRET_KEY`
2. The QR code shows your configured issuer name
3. User scans with Google Authenticator, Authy, 1Password, etc.
4. On login, user enters the 6-digit code from their app

### WebAuthn (Security Key / Biometrics)
1. User registers their authenticator (USB key, Touch ID, etc.)
2. A credential is stored, tied to your domain
3. On login, user taps their key or uses biometrics

**Note:** WebAuthn credentials are domain-specific. Not recommended with `silverstripe/subsites` as each subsite domain would need separate credentials.

## Admin Management

### Resetting user MFA

Admins with the `MFA_ADMINISTER_REGISTERED_METHODS` permission can manage MFA for other users:

1. Go to **Security → Users** and edit a user
2. Find the **Registered MFA Methods** GridField (only shown for users with MFA configured)
3. Delete any MFA methods to force the user to re-register

When all MFA methods are removed, the user will be prompted to set up MFA again on their next login.

**Note:** This GridField only appears when viewing other users' accounts, not your own (use the standard MFA interface for self-management).

### Alternative: Account Reset Email

SilverStripe MFA also includes a built-in "Send account reset email" button that sends the user a link to reset both their password and MFA settings. This is useful when you want the user to verify their identity via email.

## Troubleshooting

### "This method has not been configured yet"

The `SS_MFA_SECRET_KEY` environment variable is not set.

### WebAuthn "Security key" option not showing

- Ensure you're using HTTPS (required by WebAuthn)
- Check browser support (Chrome, Firefox, Safari, Edge)
- Verify `authenticator_attachment` isn't set to `'cross-platform'` if you want biometrics

### WebAuthn not working with Touch ID / Windows Hello

The default SilverStripe setting only allows USB security keys. This bundle changes it to allow both, but if you've overridden `authenticator_attachment`, ensure it's set to `~` (null) or `'platform'`.

### Testing locally

Use `BYPASS_MFA=1` in `.env` to skip MFA during development.

**Note:** WebAuthn passkeys require a trusted HTTPS certificate. Self-signed certificates may cause registration to fail with Chrome's native passkey storage.

## Understanding WebAuthn Authenticator Types

WebAuthn supports different authenticator types with different trade-offs:

### Platform vs Cross-Platform

| Type | Examples | Pros | Cons |
|------|----------|------|------|
| **Platform** | Touch ID, Face ID, Windows Hello | Free, built-in, convenient | Tied to single device |
| **Cross-platform** | YubiKey, USB/NFC security keys | Portable, works anywhere | Requires purchasing hardware |

### Passkeys (Synced Credentials)

Modern browsers support **passkeys** - WebAuthn credentials that sync across devices:

- **iCloud Keychain**: Syncs across Apple devices (Mac, iPhone, iPad)
- **Google Password Manager**: Syncs across Chrome browsers logged into same Google account
- **Password managers**: 1Password, Bitwarden, Proton Pass can store passkeys

When a user registers WebAuthn with `authenticator_attachment: ~` (both), the browser offers choices:
1. **This device** (platform) - Touch ID/Face ID, may sync via iCloud/Google
2. **Security key** (cross-platform) - USB/NFC hardware key
3. **Phone/tablet** - Use another device via QR code

Users can register multiple authenticators for redundancy.

### Recommendation

This bundle defaults to `authenticator_attachment: ~` (allow both) for maximum flexibility. Users can choose based on their needs:
- Office workers with one machine → Touch ID
- Mobile workers → Synced passkey or hardware key
- High-security environments → Hardware keys only (`'cross-platform'`)

## Help Pages

This bundle includes translatable help pages served at `/mfa-help/`:

| URL | Content |
|-----|---------|
| `/mfa-help/` | Overview of MFA |
| `/mfa-help/totp` | Authenticator app setup |
| `/mfa-help/webauthn` | Security key / biometrics setup |
| `/mfa-help/backup-codes` | Backup codes explanation |

Help pages automatically display in the logged-in user's CMS locale (from `Member.Locale`), falling back to the site's default locale. Translations are included for: English, Dutch, German, French, and Spanish.

### Disabling Help Pages

To disable the built-in help pages entirely, set `DISABLE_MFA_HELP` as an environment variable or PHP constant:

```env
# .env
DISABLE_MFA_HELP=1
```

Or in `app/_config.php`:

```php
define('DISABLE_MFA_HELP', true);
```

### Changing the URL Segment

To serve help pages at a different URL (e.g., `/hulp/`):

1. Disable the default route via `DISABLE_MFA_HELP`
2. Configure your custom URL segment and Director rule:

```yaml
# app/_config/mfa-help.yml
Restruct\MFABundle\Controllers\MFAHelpController:
  url_segment: 'hulp'

SilverStripe\Control\Director:
  rules:
    'hulp': 'Restruct\MFABundle\Controllers\MFAHelpController'
```

3. Update the help URLs to match your custom segment:

```yaml
SilverStripe\TOTP\RegisterHandler:
  user_help_link: '/hulp/totp'

SilverStripe\WebAuthn\RegisterHandler:
  user_help_link: '/hulp/webauthn'

SilverStripe\MFA\Authenticator\LoginHandler:
  user_help_link: '/hulp/'

SilverStripe\MFA\BackupCode\RegisterHandler:
  user_help_link: '/hulp/backup-codes'
```

### Customizing Help Content

Override the translations via lang files in your project, or point to your own URLs:

```yaml
SilverStripe\TOTP\RegisterHandler:
  user_help_link: 'https://your-site.com/help/totp'

SilverStripe\WebAuthn\RegisterHandler:
  user_help_link: 'https://your-site.com/help/security-keys'

SilverStripe\MFA\Authenticator\LoginHandler:
  user_help_link: 'https://your-site.com/help/mfa'

SilverStripe\MFA\BackupCode\RegisterHandler:
  user_help_link: 'https://your-site.com/help/backup-codes'
```

To disable help links entirely, set them to empty strings.

## Further Reading

### SilverStripe Documentation
- [MFA Module](https://github.com/silverstripe/silverstripe-mfa) - Core MFA framework
- [TOTP Authenticator](https://github.com/silverstripe/silverstripe-totp-authenticator/blob/6/docs/en/index.md) - Authenticator app configuration
- [WebAuthn Authenticator](https://github.com/silverstripe/silverstripe-webauthn-authenticator/blob/master/docs/en/readme.md) - Security keys configuration

### WebAuthn Specifications
- [WebAuthn Guide](https://webauthn.guide/) - Introduction to WebAuthn
- [Authenticator Selection Criteria](https://github.com/web-auth/webauthn-framework/blob/1.2.x/doc/webauthn/PublicKeyCredentialCreation.md#authenticator-selection-criteria) - Technical details on authenticator filtering

## License

MIT