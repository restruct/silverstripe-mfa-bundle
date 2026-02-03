# Silverstripe MFA Bundle

Portable MFA bundle for Silverstripe 5 with TOTP (Google Authenticator) and WebAuthn (security keys/biometrics) support.

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

### 4. Enable MFA requirement (optional)

In the CMS: **Settings → Access → MFA Required**

Or via config:

```yaml
SilverStripe\MFA\Service\EnforcementManager:
  required_mfa_methods: 1
```

### 5. Disable during development (optional)

Add to `.env`:

```env
BYPASS_MFA=1
```

## Configuration Reference

### Bundle extension settings

| Setting | Default | Description |
|---------|---------|-------------|
| `issuer` | SiteConfig Title | App name shown in authenticator |
| `period` | 30 | Seconds per code |
| `algorithm` | sha1 | Hash algorithm (sha1/sha256/sha512) |

### SilverStripe TOTP settings (set directly on SS classes)

| Setting | Class | Default | Description |
|---------|-------|---------|-------------|
| `code_length` | `Method` | 6 | Number of digits (6-8) |
| `secret_length` | `RegisterHandler` | 16 | Secret key length |
| `user_help_link` | `RegisterHandler` | SS docs | Help link during setup |

### SilverStripe WebAuthn settings (set directly on SS classes)

| Setting | Class | Default | Description |
|---------|-------|---------|-------------|
| `authenticator_attachment` | `RegisterHandler` | `null`* | Allowed authenticator types |
| `user_help_link` | `RegisterHandler` | SS docs | Help link during setup |

*This bundle sets the default to `null` (allow both). SilverStripe's default is `cross-platform` (security keys only).

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

## License

MIT