import { expect, test } from '@playwright/test';
import { AiConfigurationSection } from '../support/AiConfigurationSection';

/**
 * Saving has to change something.
 *
 * The section answers "You saved the configuration" whether or not the field was written, so a
 * success message is not evidence of a save. The only evidence is the section after a reload, and
 * without asking for it a form that silently discards every edit still looks like it works: adding
 * a provider, renaming one and deleting one all appear to succeed until the page comes back.
 *
 * That is not hypothetical. When mageos_ai/services/configuration is present in the "system"
 * section of app/etc/env.php, which is where `bin/magento app:config:dump` puts it, deployment
 * configuration wins over the database and Magento\Config\Model\Config skips the field on save
 * without reporting anything. These specs fail there, which is the point.
 */
test.describe('Configuration persistence', () => {
    test.beforeEach(async ({ page }) => {
        await new AiConfigurationSection(page).reset();
    });

    test('a provider added to the section is still there after a save and a reload', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.save();

        await section.open();

        await expect(section.rows()).toHaveCount(1);
        await expect(section.heading(0)).toContainText('Anthropic');
    });

    test('an edit to a saved row is still there after a save and a reload', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.save();

        await (await section.revealNameField(0)).fill('Product copy');
        await section.save();

        await section.open();

        await expect(await section.revealNameField(0)).toHaveValue('Product copy');
    });

    test('a second row of the same provider is kept alongside the first', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.save();

        await section.addProvider('anthropic');
        await section.save();

        await section.open();

        await expect(section.rows()).toHaveCount(2);
    });

    /**
     * Removing the last row posts no rows at all, which is the one save that cannot be confirmed by
     * finding something afterwards. A form that discards its input passes every other spec here and
     * fails this one.
     */
    test('a deleted row is gone after a save and a reload', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.save();

        page.once('dialog', (dialog) => dialog.accept());
        await section.row(0).locator('.action-delete').click();
        await expect(section.rows()).toHaveCount(0);
        await section.save();

        await section.open();

        await expect(section.rows()).toHaveCount(0);
        await expect(page.locator('.ai-services-empty-state')).toBeVisible();
    });
});

/**
 * What the form does when it cannot save.
 *
 * A field held in deployment configuration is read-only to the whole admin, and the section has to
 * say so: it builds every control itself, so nothing else on the page reflects Magento's own
 * read-only marking, and without it an administrator edits a form that reports success and stores
 * nothing. There is no way to put an install into that state from a browser, so this spec asserts
 * the two halves against whichever state the target install is in.
 */
test.describe('Deployment-configured services', () => {
    test('a locked section says so, and an editable one offers providers', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();

        if (!(await section.isLocked())) {
            await expect(page.locator('.ai-services-locked-notice')).toHaveCount(0);
            await expect(page.locator('.add-ai-service').first()).toBeVisible();

            return;
        }

        await expect(page.locator('.ai-services-locked-notice')).toBeVisible();
        await expect(page.locator('.ai-services-locked-notice')).toContainText('app/etc/env.php');

        await expect(page.locator('.add-ai-service')).toHaveCount(0);
        await expect(page.locator('.action-delete')).toHaveCount(0);
        await expect(page.locator('.ai-service-rename')).toHaveCount(0);
        await expect(
            page.locator('.ai-service-row-fields input:not([disabled]), .ai-service-row-fields select:not([disabled])')
        ).toHaveCount(0);
    });
});
