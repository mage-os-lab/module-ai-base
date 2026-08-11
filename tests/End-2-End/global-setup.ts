import { chromium, type FullConfig } from '@playwright/test';
import fs from 'fs';
import path from 'path';

/**
 * Sign in to the admin once and hand every spec the session.
 *
 * Magento's admin login is rate limited and its form key rotates, so logging in per test is both
 * slow and a source of failures that have nothing to do with what is being tested.
 */
async function globalSetup(config: FullConfig): Promise<void> {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

    // This suite is destructive. Every spec starts by deleting all configured AI services and
    // saving, which discards their credentials for good: they are encrypted at rest and the admin
    // form only ever shows a placeholder, so nothing on the page can put one back. Pointing it at
    // an install whose configuration matters is a data loss, not a failed test, so it has to be
    // said out loud before the first browser opens.
    if (process.env.E2E_DISPOSABLE_ENVIRONMENT !== '1') {
        throw new Error(
            'Refusing to run: this suite deletes every configured AI service on the target install, '
            + 'including credentials that cannot be read back. Set E2E_DISPOSABLE_ENVIRONMENT=1 to '
            + 'confirm the target is disposable.'
        );
    }

    const baseURL = config.projects[0].use.baseURL as string;
    const adminPath = process.env.ADMIN_PATH || 'admin';
    const username = process.env.ADMIN_USER || 'e2e_admin';
    const password = process.env.ADMIN_PASSWORD || 'E2eAdminPassword1';
    const statePath = path.resolve(__dirname, './test-results/admin-session.json');

    fs.mkdirSync(path.dirname(statePath), { recursive: true });

    const browser = await chromium.launch();
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    await page.goto(new URL(adminPath, baseURL).toString(), { waitUntil: 'networkidle' });
    await page.fill('#username', username);
    await page.fill('#login', password);
    await page.click('.action-login');
    await page.waitForLoadState('networkidle');

    if (await page.locator('#username').count()) {
        throw new Error(`Admin login failed for "${username}". The suite cannot run without it.`);
    }

    await context.storageState({ path: statePath });
    await browser.close();
}

export default globalSetup;
