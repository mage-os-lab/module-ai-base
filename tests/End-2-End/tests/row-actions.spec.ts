import { expect, test } from '@playwright/test';
import { AiConfigurationSection } from '../support/AiConfigurationSection';

/**
 * The row actions, and the one bug worth a browser to prove: both used to address a service code,
 * so with two rows of one provider the second row's buttons acted on the first row's credentials.
 */
test.describe('Row actions', () => {
    test.beforeEach(async ({ page }) => {
        await new AiConfigurationSection(page).reset();
    });

    test('Test Connection posts the id of the row it sits in, not the first row of that code', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();

        await section.addProvider('anthropic');
        await section.fieldByLabel(0, 'API Key').fill('sk-first-row');
        await section.addProvider('anthropic');
        await section.fieldByLabel(1, 'API Key').fill('sk-second-row');
        await section.save();

        const secondRowId = await section.row(1).getAttribute('id');

        const request = page.waitForRequest((candidate) => candidate.url().includes('/service/test'));
        await section.row(1).locator('.action-test-connection').click();
        const posted = new URLSearchParams((await request).postData() ?? '');

        expect(posted.get('service_id')).toBe(secondRowId);
    });

    test('Refresh Models posts the id of its own row', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();

        await section.addProvider('anthropic');
        await section.addProvider('anthropic');
        await section.save();

        const secondRowId = await section.row(1).getAttribute('id');

        const request = page.waitForRequest((candidate) => candidate.url().includes('/service/refreshmodels'));
        await section.row(1).locator('.ai-refresh-models').click();
        const posted = new URLSearchParams((await request).postData() ?? '');

        expect(posted.get('service_id')).toBe(secondRowId);
    });

    test('refreshing the model list is a control on the model field, not a button in the action column', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.save();

        await expect(section.row(0).locator('.ai-field-control .ai-refresh-models')).toBeVisible();
        await expect(section.row(0).locator('.col-actions .ai-refresh-models')).toHaveCount(0);
        await expect(section.row(0).locator('.ai-refresh-models')).toHaveAttribute('title', 'Refresh Models');
    });

    test('a result is reported under the row it belongs to, not squeezed into the action column', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.fieldByLabel(0, 'API Key').fill('sk-not-a-real-key');
        await section.save();

        await section.row(0).locator('.action-test-connection').click();

        const result = section.row(0).locator('.ai-test-result');
        await expect(result).toBeVisible({ timeout: 60_000 });
        await expect(result).not.toHaveText('');

        const width = await result.evaluate((el) => Math.round(el.getBoundingClientRect().width));
        expect(width).toBeGreaterThan(300);
    });

    test('removing a row asks first, and keeps the row when the answer is no', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        let asked = '';
        page.once('dialog', async (dialog) => {
            asked = dialog.message();
            await dialog.dismiss();
        });
        await section.row(0).locator('.action-delete').click();

        expect(asked).toContain('Remove this service?');
        await expect(section.rows()).toHaveCount(1);
    });

    test('confirming the question removes the row', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        page.once('dialog', (dialog) => dialog.accept());
        await section.row(0).locator('.action-delete').click();

        await expect(section.rows()).toHaveCount(0);
    });
});
