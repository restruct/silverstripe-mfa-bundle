# Silverstripe MFA Bundle

Portable MFA bundle for Silverstripe 5 with TOTP (Google Authenticator) and WebAuthn (security keys/biometrics) support.

## Features

- Bundles `silverstripe/mfa`, `silverstripe/totp-authenticator`, and `silverstripe/webauthn-authenticator`
- Configurable TOTP settings (issuer name, period, digits, algorithm)
- Sensible defaults: requires at least 1 MFA method

## Requirements

- Silverstripe ^5.0
- PHP ^8.1

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
Restruct\MFABundle\Extensions\TOTPConfigExtension:
  # Name shown in authenticator apps (defaults to SiteConfig Title)
  issuer: 'My Company CMS'

  # Time period in seconds (default: 30)
  period: 30

  # Number of digits (default: 6)
  digits: 6

  # Algorithm: sha1, sha256, sha512 (default: sha1)
  # Note: Not all apps support sha256/sha512
  algorithm: 'sha1'
```

### 3. Enable MFA requirement (optional)

In the CMS: **Settings → Access → MFA Required**

Or via config:

```yaml
SilverStripe\MFA\Service\EnforcementManager:
  required_mfa_methods: 1
```

### 4. Disable during development (optional)

Add to `.env`:

```env
BYPASS_MFA=1
```

## Configuration Reference

| Setting | Default | Description |
|---------|---------|-------------|
| `issuer` | SiteConfig Title | App name shown in authenticator |
| `period` | 30 | Seconds per code |
| `digits` | 6 | Code length (6-8) |
| `algorithm` | sha1 | Hash algorithm (sha1/sha256/sha512) |
| `required_mfa_methods` | 1 | Minimum MFA methods required |

## How it works

When a user registers TOTP:
1. A secret is generated and encrypted with `SS_MFA_SECRET_KEY`
2. The QR code shows your configured issuer name
3. User scans with Google Authenticator, Authy, 1Password, etc.

When a user logs in:
1. They enter username/password as normal
2. They're prompted for their TOTP code (or security key)

## Troubleshooting

### "This method has not been configured yet"

The `SS_MFA_SECRET_KEY` environment variable is not set.

### Testing locally

Use `BYPASS_MFA=1` in `.env` to skip MFA during development.

## License

MIT