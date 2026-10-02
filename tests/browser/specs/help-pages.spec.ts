import { test, expect } from './support';

// /mfa-help: the bundle's own help pages (MFAHelpController), linked from the MFA screens.

const PAGES = [
    { path: '/mfa-help/', nav: 'Overview' },
    { path: '/mfa-help/totp', nav: 'Authenticator App' },
    { path: '/mfa-help/webauthn', nav: 'Security Key' },
    { path: '/mfa-help/backup-codes', nav: 'Backup Codes' },
];

test('every help page renders, styled, marks itself in the navigation and is kept out of search engines', async ({ page }) => {
    for (const p of PAGES) {
        const css = page.waitForResponse((r) => /silverstripe-mfa-bundle\/client\/dist\/css\/mfa-help\.css/.test(r.url()));
        const response = await page.goto(p.path);
        expect(response?.status(), p.path).toBe(200);
        expect(response?.headers()['x-robots-tag'], `${p.path} X-Robots-Tag`).toBe('noindex, nofollow');
        expect((await css).status(), `${p.path} stylesheet`).toBe(200);

        const help = page.locator('.mfa-help');
        await expect(help.locator('h1').first()).toBeVisible();
        // The stylesheet is applied: .mfa-help is a centred card of at most 800px.
        expect(await help.evaluate((e) => getComputedStyle(e).maxWidth), `${p.path} styled`).toBe('800px');

        // The current page is plain bold text in the navigation, the other three are links.
        const nav = help.locator('nav.mfa-help-nav');
        await expect(nav.locator('strong')).toHaveText(p.nav);
        await expect(nav.locator('a')).toHaveCount(PAGES.length - 1);
    }
});

test('the navigation links lead to each other page', async ({ page }) => {
    await page.goto('/mfa-help/');
    for (const p of PAGES.slice(1)) {
        await page.locator('nav.mfa-help-nav a', { hasText: p.nav }).click();
        await expect(page).toHaveURL(new RegExp(`${p.path.replace(/\//g, '\\/')}$`));
        await expect(page.locator('nav.mfa-help-nav strong')).toHaveText(p.nav);
    }
});
