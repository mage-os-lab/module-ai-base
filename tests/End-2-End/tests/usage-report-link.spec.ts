import { expect, test } from '@playwright/test';
import { AiConfigurationSection } from '../support/AiConfigurationSection';

/**
 * The Usage Tracking settings link to the report they configure. What this proves that the unit
 * test cannot is the URL itself: an admin route carries a per-session secret key, so only a link
 * built by the running application actually lands on the report rather than on the dashboard
 * with a security notice.
 */
test.describe('Usage report link in configuration', () => {
    test('it takes the administrator from the usage settings to the usage report', async ({ page }) => {
        await new AiConfigurationSection(page).open();

        // Config groups start collapsed; the link lives in the Usage Tracking one.
        const usageGroupHeading = page.locator('#mageos_ai_usage-head');
        if (!(await usageGroupHeading.evaluate((element) => element.classList.contains('open')))) {
            await usageGroupHeading.click();
        }

        const link = page.locator('.mageos-ai-usage-report-link');
        await expect(link).toBeVisible();

        await link.click();
        await page.waitForLoadState('networkidle');

        expect(page.url()).toContain('mageos_ai/usage/index');
        await expect(page).toHaveTitle(/AI Token Usage/);
        await expect(page.locator('.mageos-ai-usage-dashboard')).toBeVisible();
    });
});
