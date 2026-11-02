# README screenshots

`readme.shot.ts` writes the PNGs in `docs/images/` that the module README shows. It is not a test
suite: it runs with its own config, `playwright.screenshots.config.ts`, which the browser tests and
CI never load, so a normal test run never rewrites the images. Never edit the PNGs by hand;
regenerate them.

What it needs:

- a Silverstripe 6 host with this module installed and the browser fixtures from
  `tests/browser/fixtures/` copied into its `app/src/` (and `fixtures/_config/` into
  `app/_config/browser-fixtures/`), then the database built. `MfaBTotpReset` provides the
  throwaway member `mfab-totp@example.com` the screenshots log in as;
- `SS_MFA_SECRET_KEY` set on the host (the test value in `targets.sh`) and the default admin
  `admin`/`admin`;
- `BROWSER_TARGET_URLS` pointing at that host (only the `ss6` target is used, else the first).

The shots run in order and share state: the authenticator-app registration made in one is what the
admin screenshot shows next. Everything on screen belongs to the scratch host: the member, and a
TOTP secret the host generated for that run only. Never point this at a real site.

The shared runner in the maintainers' workspace does all of that:

```bash
BROWSER_ONLY=ss6 tools/browser/run.sh silverstripe-mfa-bundle --config=playwright.screenshots.config.ts
```

Against a host you started yourself, from the `tests/browser/` directory:

```bash
npm ci
BROWSER_TARGET_URLS="ss6=http://127.0.0.1:8892" npx playwright test --config=playwright.screenshots.config.ts
```

The host runs on plain HTTP, where upstream marks the security key (WebAuthn) method as
unsupported, so there is deliberately no screenshot of the method choice.
