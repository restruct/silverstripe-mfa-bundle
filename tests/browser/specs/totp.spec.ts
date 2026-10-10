import { test, expect, freshTotp, freshVisitor, submitLogin, totp } from './support';

// The authenticator-app (TOTP) method end to end, as a CMS user meets it: register with a code
// computed from the secret the screen shows (with the bundle's issuer setting in the QR code),
// keep the recovery codes, land in the CMS; then log in again and pass the TOTP step. TOTP stores its secret encrypted with SS_MFA_SECRET_KEY, which the
// hosts get from BROWSER_ENV in targets.sh. The member is reset before every run and repeat
// (fixtures/MfaBTotpReset.php), so it always starts at the registration prompt.

const EMAIL = 'mfab-totp@example.com';
const PASSWORD = 'Browser-Test-Pass-123!';

test('register the authenticator app with a computed code, then log in with a TOTP code', async ({ page, browser, baseURL }) => {
    test.setTimeout(60_000);
    expect((await page.request.get('/admin/mfab-reset/totp')).status(), 'TOTP member reset').toBe(200);

    // --- Registration ---
    const first = await freshVisitor(browser, baseURL);
    const v = first.page;
    // With TOTPConfigExtension.issuer configured (fixture middleware cookie), the app shows it.
    await v.context().addCookies([{ name: 'mfab-browser-variant', value: 'issuer', url: baseURL! }]);
    await submitLogin(v, EMAIL, PASSWORD);
    await expect(v).toHaveURL(/\/Security\/login\/default\/mfa/);
    await v.getByRole('button', { name: 'Get started' }).click();
    await v.locator('.mfa-method-tile').filter({ hasText: /authenticator app/i }).click();
    const started = v.waitForResponse((r) => r.request().method() === 'GET' && /\/mfa\/register\/totp/.test(r.url()));
    await v.getByRole('button', { name: 'Next' }).click();
    // The provisioning URI behind the QR code: the bundle's issuer instead of the site title.
    // The hosts run as SS_ENVIRONMENT_TYPE=dev, so the default environment_label (added in 1.7.0
    // on purpose, CHANGELOG "Unreleased") appends " (DEV)" to it.
    const uri = new URL((await (await started).json()).uri);
    // expect(uri.searchParams.get('issuer'), `issuer in ${uri}`).toBe('Acme Browser CMS');
    expect(uri.searchParams.get('issuer'), `issuer in ${uri}`).toBe('Acme Browser CMS (DEV)');

    // The QR code and, for typing in by hand, the same secret as base32 groups.
    const app = v.locator('#mfa-app');
    await expect(app.locator('.mfa-totp__scan-code svg')).toBeVisible();
    const secret = (await app.getByText(/^[A-Z2-7]{4}( [A-Z2-7]{4})+$/).innerText()).trim();
    await app.getByRole('button', { name: 'Next' }).click();

    await v.locator('#totp-code').fill(await freshTotp(secret));
    const registered = v.waitForResponse((r) => r.request().method() === 'POST' && /\/mfa\/register\/totp/.test(r.url()));
    await app.getByRole('button', { name: 'Next' }).click();
    expect((await registered).status(), 'TOTP registration accepted').toBe(201);

    // First method registered: recovery codes come next (the bundle keeps backup codes on).
    await expect(app.getByRole('heading', { name: 'Register with recovery codes' })).toBeVisible();
    const codes = v.waitForResponse((r) => r.request().method() === 'POST' && /\/mfa\/register\/backup-codes/.test(r.url()));
    await app.getByRole('button', { name: 'Finish' }).click();
    expect((await codes).status(), 'recovery codes stored').toBe(201);
    await expect(v.getByRole('heading', { name: 'Multi-factor authentication is now set up' })).toBeVisible();
    await v.getByRole('button', { name: 'Continue' }).click();
    await expect(v).toHaveURL(/\/admin/);
    await expect(v.locator('.cms-menu')).toBeVisible();
    await first.done();

    // --- Verification on the next login ---
    // The wrong code below answers 401, which the browser logs as a failed resource; that one is expected.
    const second = await freshVisitor(browser, baseURL, [/status of 401 \(Unauthorized\)/]);
    const w = second.page;
    await submitLogin(w, EMAIL, PASSWORD);
    await expect(w).toHaveURL(/\/Security\/login\/default\/mfa/);
    // A wrong code is refused first...
    const wrong = String((Number(totp(secret)) + 500_000) % 1_000_000).padStart(6, '0');
    await w.locator('#totp-code').fill(wrong);
    const refused = w.waitForResponse((r) => r.request().method() === 'POST' && /\/mfa\/verify\/totp/.test(r.url()));
    await w.locator('#mfa-app').getByRole('button', { name: /Next|Verify/ }).first().click();
    expect((await refused).status(), 'a wrong code is refused').not.toBe(200);
    await expect(w).toHaveURL(/\/Security\/login\/default\/mfa/);

    // ...the current one lets the member in.
    await w.locator('#totp-code').fill(await freshTotp(secret));
    await w.locator('#mfa-app').getByRole('button', { name: /Next|Verify/ }).first().click();
    await expect(w).toHaveURL(/\/admin/);
    await expect(w.locator('.cms-menu')).toBeVisible();
    await second.done();
});

// https://github.com/restruct/silverstripe-mfa-bundle/issues/3 - updateTotp() only reaches the TOTP
// object behind the QR code; register()/verify() check with a fresh 30-second SHA1 TOTP. Measured
// red on SS5 and SS6 (2026-10-02): the URI says period=60, the 60-second code gets a 400.
test.fixme('a configured TOTP period is used for the QR code AND for checking the code', async ({ page, browser, baseURL }) => {
    // TOTPConfigExtension.period = 60 (switched on by the fixture middleware's cookie). An
    // authenticator app set up from the QR code then makes 60-second codes; registration must
    // accept them.
    test.setTimeout(60_000);
    expect((await page.request.get('/admin/mfab-reset/totp')).status(), 'TOTP member reset').toBe(200);
    const first = await freshVisitor(browser, baseURL, [/status of 4\d\d/]);
    const v = first.page;
    await v.context().addCookies([{ name: 'mfab-browser-variant', value: 'period60', url: baseURL! }]);
    await submitLogin(v, EMAIL, PASSWORD);
    await v.getByRole('button', { name: 'Get started' }).click();
    await v.locator('.mfa-method-tile').filter({ hasText: /authenticator app/i }).click();
    const started = v.waitForResponse((r) => r.request().method() === 'GET' && /\/mfa\/register\/totp/.test(r.url()));
    await v.getByRole('button', { name: 'Next' }).click();
    // The provisioning URI behind the QR code carries the configured period.
    const uri: string = (await (await started).json()).uri;
    expect(new URL(uri).searchParams.get('period'), `period in ${uri}`).toBe('60');
    const secret = new URL(uri).searchParams.get('secret')!;
    await v.locator('#mfa-app').getByRole('button', { name: 'Next' }).click();

    // The code the app makes: 60-second steps. Avoid a step boundary.
    if (60_000 - (Date.now() % 60_000) < 5_000) await new Promise((r) => setTimeout(r, 6_000));
    await v.locator('#totp-code').fill(totp(secret, 0, Date.now(), 60));
    const registered = v.waitForResponse((r) => r.request().method() === 'POST' && /\/mfa\/register\/totp/.test(r.url()));
    await v.locator('#mfa-app').getByRole('button', { name: 'Next' }).click();
    expect((await registered).status(), 'a code from the configured 60-second period is accepted').toBe(201);
    await first.done();
});
