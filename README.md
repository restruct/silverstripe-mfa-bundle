# Silverstripe MFA Bundle

Portable MFA bundle for Silverstripe 5 with TOTP (Google Authenticator) and WebAuthn (security keys/biometrics) support.

## Features

- Bundles `silverstripe/mfa`, `silverstripe/totp-authenticator`, and `silverstripe/webauthn-authenticator`
- Configurable TOTP settings (issuer name, period, algorithm)
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

### MFA enforcement

| Setting | Class | Default | Description |
|---------|-------|---------|-------------|
| `required_mfa_methods` | `EnforcementManager` | 1 | Minimum methods required |

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