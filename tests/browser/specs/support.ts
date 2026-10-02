import { test as base, expect, type Browser, type Page } from '@playwright/test';

// Shared fixtures and helpers for the mfa-bundle specs.
//
// The fixture MfaBSeed (tests/browser/fixtures/) seeds, on every dev/build, an editor with CMS
// access and no MFA method, and five "keyholder" members that each have one registered method.

/** The seeded editor (see fixtures/MfaBSeed.php). */
export const EDITOR = { email: 'mfab-editor@example.com', password: 'Browser-Test-Pass-123!' };

/** The seeded members with a registered MFA method, in order. */
export const KEYHOLDERS = [1, 2, 3, 4, 5].map((i) => ({ email: `mfab-keyholder-${i}@example.com`, name: `Keyholder ${i}` }));

/**
 * test, extended with an automatic console guard: every spec fails if the page logs a console
 * error or throws an uncaught exception at any point. Warnings do not count.
 */
export const test = base.extend<{ consoleGuard: void }>({
    consoleGuard: [
        async ({ page }, use, testInfo) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(`console.error: ${msg.text()} (${msg.location().url})`);
                }
            });
            page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));

            await use();

            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(errors, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

/**
 * A page in a fresh, logged-out browser context, with its own console guard (the test's `page`
 * fixture carries the admin session; this one is a different visitor). Returns the page and a
 * check to call at the end.
 */
export async function freshVisitor(browser: Browser, baseURL: string | undefined): Promise<{ page: Page; done: () => Promise<void> }> {
    // An explicit empty storageState: a context created in a spec otherwise picks up the
    // project's saved admin login.
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('console', (m) => m.type() === 'error' && errors.push(`console.error: ${m.text()}`));
    page.on('pageerror', (e) => errors.push(`uncaught: ${e.message}`));
    return {
        page,
        done: async () => {
            expect(errors, 'no console errors in the visitor page').toEqual([]);
            await context.close();
        },
    };
}

/** Log in through the real form with email and password (stops wherever the login lands). */
export async function submitLogin(page: Page, email: string, password: string): Promise<void> {
    await page.goto('/Security/login?BackURL=/admin');
    await page.locator('input[name="Email"]').fill(email);
    await page.locator('input[name="Password"]').fill(password);
    await page.locator('[name="action_doLogin"]').click();
}

/**
 * Security admin sections that hold members or groups are read-only until the user re-enters
 * their password ("sudo mode": on SS6, and on SS5 from framework 5.3). Activate it through the
 * real notice when it is shown; a no-op on a version without it.
 */
export async function ensureSudoMode(page: Page): Promise<void> {
    await page.goto('/admin/security');
    await expect(page.locator('#Form_EditForm_users')).toBeVisible();
    const notice = page.locator('.sudo-mode-password-field__notice-button');
    if ((await notice.count()) === 0) {
        return;
    }
    await notice.click();
    await page.locator('input#SudoModePassword').fill('admin');
    await page.locator('.sudo-mode-password-field__verify-button').click();
    await expect(page.locator('.sudo-mode-password-field')).toHaveCount(0);
}
