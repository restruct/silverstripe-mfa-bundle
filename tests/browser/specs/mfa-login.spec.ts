import { test, expect, EDITOR, freshVisitor, submitLogin } from './support';

// MFA is enforced out of the box (SiteConfigMFAExtension sets MFARequired on dev/build) with a
// grace period, and the bundle points the MFA screens' help links at its own /mfa-help pages.

test('a CMS user is asked to set up MFA after the password, and may skip it during the grace period', async ({ browser, baseURL }) => {
    const { page, done } = await freshVisitor(browser, baseURL);
    await submitLogin(page, EDITOR.email, EDITOR.password);

    // The MFA prompt, not the CMS.
    await expect(page).toHaveURL(/\/Security\/login\/default\/mfa/);
    const app = page.locator('#mfa-app');
    await expect(app.getByRole('heading', { name: 'Multi-factor authentication' })).toBeVisible();
    // The bundle's help page instead of the upstream userhelp site (LoginHandler.user_help_link).
    await expect(app.getByRole('link', { name: 'How multi-factor authentication works' })).toHaveAttribute('href', '/mfa-help/');

    // Grace period (grace_period_days, default 180): skipping is allowed, and leads into the CMS.
    await app.getByRole('button', { name: 'Setup later' }).click();
    await expect(page).toHaveURL(/\/admin/);
    await expect(page.locator('.cms-menu')).toBeVisible();
    await done();
});

test('the method selection links each method to its bundle help page', async ({ browser, baseURL }) => {
    const { page, done } = await freshVisitor(browser, baseURL);
    await submitLogin(page, EDITOR.email, EDITOR.password);
    await expect(page).toHaveURL(/\/Security\/login\/default\/mfa/);

    await page.getByRole('button', { name: 'Get started' }).click();
    const tiles = page.locator('.mfa-method-tile');
    // Both bundled methods are offered: authenticator app (TOTP) and security key (WebAuthn).
    const totp = tiles.filter({ hasText: /authenticator app/i });
    const webauthn = tiles.filter({ hasText: /security key/i });
    await expect(totp).toHaveCount(1);
    await expect(webauthn).toHaveCount(1);
    // RegisterHandler.user_help_link of each, set by the bundle.
    await expect(totp.locator('a.mfa-method-tile__support-link')).toHaveAttribute('href', '/mfa-help/totp');
    await expect(webauthn.locator('a.mfa-method-tile__support-link')).toHaveAttribute('href', '/mfa-help/webauthn');
    await done();
});
