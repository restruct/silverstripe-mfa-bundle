import { createHmac } from 'node:crypto';
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
 * check to call at the end. `expected` lists console errors the spec provokes itself.
 */
export async function freshVisitor(
    browser: Browser,
    baseURL: string | undefined,
    // Console errors this spec causes on purpose (e.g. the 401 of a deliberately wrong code).
    expected: RegExp[] = [],
): Promise<{ page: Page; done: () => Promise<void> }> {
    // An explicit empty storageState: a context created in a spec otherwise picks up the
    // project's saved admin login.
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('console', (m) => m.type() === 'error' && !expected.some((re) => re.test(m.text())) && errors.push(`console.error: ${m.text()}`));
    page.on('pageerror', (e) => isKnownUpstreamError(e) || errors.push(`uncaught: ${e.message}`));
    return {
        page,
        done: async () => {
            expect(errors, 'no console errors in the visitor page').toEqual([]);
            await context.close();
        },
    };
}

/**
 * The one uncaught error that is not counted, and only where it comes from: silverstripe/mfa's own
 * recovery-codes screen (client/dist/js/bundle.js, BackupCodes register component). When "Finish"
 * stores the codes it re-renders once with the codes already gone, and getFormattedCodes() calls
 * .map() on undefined. The flow carries on to "Multi-factor authentication is now set up"
 * regardless. Upstream code, seen on Silverstripe 5 and 6 (mfa 5 and 6, 2026-10-02); any other
 * uncaught error still fails the spec.
 */
export function isKnownUpstreamError(err: Error): boolean {
    return (
        err.message === "Cannot read properties of undefined (reading 'map')" &&
        /\bgetFormattedCodes\b/.test(err.stack ?? '') &&
        /\/silverstripe\/mfa\/client\/dist\/js\/bundle\.js/.test(err.stack ?? '')
    );
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

/**
 * The current TOTP code (RFC 6238: HMAC-SHA1, 30-second steps, 6 digits - totp-authenticator's
 * defaults) for a base32 secret as the registration screen shows it (spaces allowed). `offset`
 * shifts by whole steps; `period` is the step length in seconds.
 */
export function totp(secret: string, offset = 0, now = Date.now(), period = 30): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/[\s=]/g, '').toUpperCase()) {
        const v = alphabet.indexOf(c);
        if (v < 0) throw new Error(`not base32: ${c}`);
        bits += v.toString(2).padStart(5, '0');
    }
    const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(now / 1000 / period) + offset));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const o = hmac[hmac.length - 1] & 0xf;
    const code = (hmac.readUInt32BE(o) & 0x7fffffff) % 1_000_000;
    return String(code).padStart(6, '0');
}

/**
 * A TOTP code that stays valid for at least 5 more seconds: waits for the next 30-second step
 * when the current one is about to end, so a code is never typed in one step and checked in the next.
 */
export async function freshTotp(secret: string): Promise<string> {
    const left = 30_000 - (Date.now() % 30_000);
    if (left < 5_000) {
        await new Promise((resolve) => setTimeout(resolve, left + 250));
    }
    return totp(secret);
}
