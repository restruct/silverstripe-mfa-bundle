# Changelog

## 1.5.0 (unreleased)

Silverstripe 6 support, alongside Silverstripe 5, from the same `main` line. Three defects that
affected Silverstripe 5 installs are fixed as well, so updating is worthwhile on 5 too.

### Added

- **Silverstripe 6 support.** `silverstripe/framework`, `silverstripe/mfa`,
  `silverstripe/totp-authenticator` and `silverstripe/webauthn-authenticator` are now required at
  `^5 || ^6`. PHP 8.1 is the declared floor (Silverstripe 6 itself needs 8.3).
- A behavioural test suite (34 tests) and a CI workflow that runs it on Silverstripe 5 and 6.

### Fixed

- **The first `dev/build` on an empty database did not enable MFA.** The enforcement ran before
  SiteConfig had created its row, so it updated nothing while printing "MFA requirement enforced";
  MFA only took effect on the second build. It now creates the row first.
- **The SiteConfig MFA settings were not hidden.** The bundle's extension ran before the upstream
  MFA module added the fields, so it had nothing to remove and "Multi-factor authentication (MFA)"
  stayed on Settings → Access despite `show_mfa_settings: false`. The bundle's config now loads after
  the upstream MFA extensions, and the whole group, heading included, is removed.
- **The admin "Registered MFA Methods" grid was placed above the member's MFA settings**, instead
  of directly below them as intended: it ran before the upstream MFA module had added that field. The lookup also used the field's class name rather than its name (`MFASettings`);
  that is corrected too, though with the ordering fixed the grid lands in the same place either way,
  because the upstream field is currently the last one on the tab.

### Changed

- `MemberMFAAdminExtension` and `RegisteredMethodExtension` extend `SilverStripe\Core\Extension`
  instead of `SilverStripe\ORM\DataExtension` (deprecated in framework 5.3, removed in 6). This only
  matters to code that checks `instanceof DataExtension` on them.
- `SiteConfigMFAExtension` implements the dev/build hook under both names:
  `requireDefaultRecords()` (Silverstripe 5) and `onRequireDefaultRecords()` (Silverstripe 6, which
  renamed it). Each major calls one of them.

### Upgrading

No code or config changes are needed. After updating, run `dev/build` once. On Silverstripe 5 you
will notice two visible differences, both the documented behaviour that was not happening before:
the MFA fields are gone from Settings → Access (set `show_mfa_settings: true` to keep them), and the
admin MFA grid on a member moves to sit below that member's MFA settings.

## 1.4.0 and earlier

Silverstripe 5 only. See the git history and tags.
