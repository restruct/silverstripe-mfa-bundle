# Changelog

## Unreleased

### Added

- **Environment label on the TOTP issuer.** Tokens registered outside live now show the
  environment in authenticator apps, e.g. `My Site (DEV)` or `My Site (TEST)`, so they are not
  mistaken for the live token. New `TOTPConfigExtension` config: `environment_label` (null =
  automatic, a string = always, also on live, false = never) and `environment_label_format`
  (default `'%s (%s)'`). The `SS_MFA_TOTP_ENVIRONMENT_LABEL` environment variable overrides the
  config; empty switches the label off. Colons are stripped so a label can never make registration
  fail. Existing tokens keep the issuer they were registered with. **Behaviour change on dev and
  test sites:** newly registered tokens get the label by default; set `environment_label: false` to
  keep the old issuer.

## 1.6.0 (2026-10-06)

### Added

- **Opt-in `SingleCheckLoginHandler`** (#4). `silverstripe/mfa` checks the password twice on a
  login submit that does not continue to MFA (a wrong password, or a member without MFA), so each
  try records two `LoginAttempt`s and an account locks after 5 wrong passwords instead of 10
  (upstream silverstripe/silverstripe-mfa#421). The new handler checks once. Off by default; enable
  it with an Injector replacement, see README section 6.

## 1.5.0 (2026-09-25)

Silverstripe 6 support, alongside Silverstripe 5, from the same `main` line. Three defects that
affected Silverstripe 5 installs are fixed as well, so updating is worthwhile on 5 too.

### Added

- **Silverstripe 6 support.** `silverstripe/mfa`, `silverstripe/totp-authenticator` and
  `silverstripe/webauthn-authenticator` are now required at `^5 || ^6`, and
  `silverstripe/framework` at `^5.4 || ^6`. PHP 8.1 is the declared floor (Silverstripe 6 itself
  needs 8.3).
- `silverstripe/admin` (`^2 || ^3`) and `silverstripe/siteconfig` (`^5.4 || ^6`) are now required
  directly. The bundle uses both (`LeftAndMain.application_name` for the TOTP issuer, the
  SiteConfig extension) but had them only through `silverstripe/mfa`. Every install already has
  both. A site that pins `silverstripe/siteconfig` below 5.4 will see it move to 5.4.
- A behavioural test suite (41 tests) and a CI workflow that runs it on Silverstripe 5 and 6.

### Fixed

- **The first `dev/build` on an empty database did not enable MFA.** The enforcement ran before
  SiteConfig had created its row, so it updated nothing while printing "MFA requirement enforced";
  MFA only took effect on the second build. It now creates the row first.
- **The SiteConfig MFA settings were not hidden.** The bundle's extension ran before the upstream
  MFA module added the fields, so it had nothing to remove and "Multi-factor authentication (MFA)"
  stayed on Settings > Access despite `show_mfa_settings: false`. The bundle's config now loads after
  the upstream MFA extensions. The two fields are removed, then their group (with its heading)
  if that left it empty; a field other code added to the group stays.
- **The admin "Registered MFA Methods" grid was placed above the member's MFA settings**, instead
  of directly below them as intended: it ran before the upstream MFA module had added that field. The lookup also used the field's class name rather than its name (`MFASettings`);
  that is corrected too, though with the ordering fixed the grid lands in the same place either way,
  because the upstream field is currently the last one on the tab.

### Changed

- **With `show_mfa_settings: true`, `dev/build` no longer overrides the administrator's "MFA
  Required" choice.** It switches MFA on only in the build that creates the SiteConfig record (a
  fresh database). Before, every build forced it back on, so an administrator who made MFA optional
  in Settings > Access found it required again after the next deploy. The grace period follows
  the same rule: in that mode a build fills in an empty "MFA grace period expires" date only on a
  fresh database, so an administrator who clears it (to make MFA mandatory at once) no longer gets
  a new 180-day skip window back on the next deploy. With the default `show_mfa_settings: false`
  nothing changes: every build still enforces MFA and fills in a missing grace date.

- `MemberMFAAdminExtension` and `RegisteredMethodExtension` extend `SilverStripe\Core\Extension`
  instead of `SilverStripe\ORM\DataExtension` (deprecated in framework 5.3, removed in 6). This only
  matters to code that checks `instanceof DataExtension` on them.
- `SiteConfigMFAExtension` implements the dev/build hook under both names:
  `requireDefaultRecords()` (Silverstripe 5) and `onRequireDefaultRecords()` (Silverstripe 6, which
  renamed it). Each major calls one of them.

### Upgrading

1.5.0 is a minor release, so a site constrained to `~1.0`, `^1.3` or `^1.4` picks it up on its next
`composer update`, together with the two visible CMS changes described below.

- **`show_mfa_settings: true` sites:** "MFA Required" now stays as the administrator set it across
  builds, and so does a cleared grace-period date. A site that relied on `dev/build` to switch MFA
  back on, or to set the grace date, must set it in Settings > Access. Adding the bundle to an
  existing site with this setting no longer switches MFA on or sets a grace date either.
- **Silverstripe 5 before 5.4 is no longer supported.** The framework floor on the 5 side is now
  `^5.4` (5.4 is the only Silverstripe 5 minor this release was tested on). A site on 5.0 to 5.3
  stays on 1.4.x until it updates the framework.

Otherwise no code or config changes are needed. After updating, run `dev/build` once. On
Silverstripe 5 you will notice two visible differences, both the documented behaviour that was
not happening before: the MFA fields are gone from Settings > Access (set `show_mfa_settings: true`
to keep them), and the admin MFA grid on a member moves to sit below that member's MFA settings.

## 1.4.0 and earlier

Silverstripe 5 only. See the git history and tags.
