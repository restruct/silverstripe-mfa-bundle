import { test, expect, KEYHOLDERS, ensureSudoMode } from './support';

// The CMS screens the bundle changes: Settings > Access (MFA settings hidden by default) and a
// member's edit form (admins see and can delete the member's registered MFA methods).

test('Settings > Access does not show the MFA settings', async ({ page }) => {
    await page.goto('/admin/settings');
    await page.getByRole('tab', { name: 'Access', exact: true }).click();
    const access = page.locator('#Root_Access');
    // The tab itself is intact ...
    await expect(access.locator('[name="CanViewType"]').first()).toBeAttached();
    // ... but the MFA group (heading and both fields) is gone; show_mfa_settings defaults to false.
    await expect(access.locator('[name="MFARequired"]')).toHaveCount(0);
    await expect(access.locator('[name="MFAGracePeriodExpires"]')).toHaveCount(0);
    await expect(access).not.toContainText(/Multi-factor authentication/i);
});

test("an admin sees a member's registered MFA methods and can delete one", async ({ page }) => {
    await ensureSudoMode(page);
    const users = page.locator('#Form_EditForm_users');

    // The first seeded keyholder that still has a method (a delete in an earlier repeat of this
    // spec removed the first ones; every dev/build re-seeds them).
    let grid = null;
    for (const k of KEYHOLDERS) {
        await page.goto('/admin/security');
        await users.locator('tr.ss-gridfield-item', { hasText: k.email }).click();
        await expect(page.locator('#Form_ItemEditForm')).toBeVisible();
        const candidate = page.locator('#Form_ItemEditForm_AdminMFAMethods');
        if (await candidate.count()) {
            grid = candidate;
            break;
        }
    }
    expect(grid, 'a keyholder with a registered method').not.toBeNull();

    // View and delete only: no Add button.
    // (An empty GridField renders one tr.ss-gridfield-item too, the "No items found" row.)
    const rows = grid!.locator('tr.ss-gridfield-item:not(.ss-gridfield-no-items)');
    await expect(rows).toHaveCount(1);
    await expect(grid!.locator('.new-link, .grid-field__add-new')).toHaveCount(0);
    // RegisteredMethodExtension adds readable summary columns: the method name and when it was
    // registered.
    await expect(rows.first().locator('td.col-MethodName')).toHaveText('Recovery codes');

    // Delete it: an AJAX GridField action, after the browser's confirm dialog.
    page.once('dialog', (d) => d.accept());
    const deleted = page.waitForResponse((r) => r.request().method() === 'POST' && /\/field\/AdminMFAMethods/.test(r.url()));
    const actions = rows.first();
    const direct = actions.locator('.action--delete, .gridfield-button-delete');
    if (await direct.count()) {
        await direct.first().click();
    } else {
        // SS5/6 put row actions in a "..." menu.
        await actions.locator('.action-menu__toggle').click();
        await actions.locator('.dropdown-item', { hasText: /delete/i }).click();
    }
    const res = await deleted;
    expect(res.status(), 'delete accepted').toBe(200);
    expect(['xhr', 'fetch']).toContain(res.request().resourceType());
    await expect(rows).toHaveCount(0);
    await expect(grid!.locator('tr.ss-gridfield-no-items')).toBeVisible();
});
