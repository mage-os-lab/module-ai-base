import { expect, test } from '@playwright/test';
import { AiConfigurationSection } from '../support/AiConfigurationSection';

/**
 * The parts of the form that are easy to regress silently because nothing looks broken when they
 * do: field labelling, credential handling, and the install hint being copyable.
 */
test.describe('Form quality', () => {
    test.beforeEach(async ({ page }) => {
        await new AiConfigurationSection(page).reset();
    });

    test('every field is labelled, so the form is usable without seeing it', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        const unlabelled = await page.evaluate(() => {
            const controls = Array.from(
                document.querySelectorAll('.ai-services-configurator tbody input:not([type=hidden]), .ai-services-configurator tbody select')
            );
            // Either form of labelling counts: a label pointing at an id, or a label the control
            // sits inside. Both give the control an accessible name, which is the actual rule.
            return controls
                .filter((el) => !(el.id && document.querySelector(`label[for="${el.id}"]`))
                    && !el.closest('label'))
                .map((el) => el.getAttribute('name'));
        });

        expect(unlabelled).toEqual([]);
    });

    test('a credential is never offered to a password manager as a login', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        await expect(section.fieldByLabel(0, 'API Key')).toHaveAttribute('autocomplete', 'off');
        await expect(section.fieldByLabel(0, 'API Key')).toHaveAttribute('type', 'password');
    });

    test('a saved credential is shown as a placeholder and survives an unrelated edit', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.fieldByLabel(0, 'API Key').fill('sk-e2e-secret-value');
        await section.save();

        await expect(section.fieldByLabel(0, 'API Key')).toHaveValue('******');

        await (await section.revealNameField(0)).fill('Renamed after saving');
        await section.save();

        await expect(await section.revealNameField(0)).toHaveValue('Renamed after saving');
        await expect(section.fieldByLabel(0, 'API Key')).toHaveValue('******');
    });

    test('the install hint can be copied in one click @developer-mode', async ({ page, context }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);

        const section = new AiConfigurationSection(page);
        await section.open();

        const copyButton = page.locator('.ai-service-copy-command');
        await expect(copyButton).toBeVisible();
        await copyButton.click();

        const clipboard = await page.evaluate(() => navigator.clipboard.readText());
        expect(clipboard).toContain('composer require symfony/ai-');
        await expect(copyButton).toHaveText('Copied');
    });

    test('the command is readable rather than clipped at the edge of its box @developer-mode', async ({ page }) => {
        await new AiConfigurationSection(page).open();

        const overflow = await page.locator('.ai-service-install-hint').evaluate(
            (el) => el.scrollWidth - el.clientWidth
        );

        expect(overflow).toBeLessThanOrEqual(1);
    });
});
