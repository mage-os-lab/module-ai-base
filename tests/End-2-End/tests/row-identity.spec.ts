import { expect, test } from '@playwright/test';
import { AiConfigurationSection } from '../support/AiConfigurationSection';

/**
 * A row has to say which provider it configures. Two rows of the same backend are a supported
 * setup (two accounts, two billing owners) and were previously identical down to the pixel.
 */
test.describe('Row identity', () => {
    test.beforeEach(async ({ page }) => {
        await new AiConfigurationSection(page).reset();
    });

    test('a row is headed by the provider it configures', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        await expect(section.heading(0)).toContainText('Anthropic');
    });

    test('the heading carries the model, which is what tells two rows of one provider apart', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        const model = section.fieldByLabel(0, 'Model');
        await model.selectOption({ index: 1 });
        const chosen = await model.inputValue();

        await expect(section.heading(0)).toContainText(chosen);
    });

    test('an administrator can name a row and the provider stays visible behind the name', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        await (await section.revealNameField(0)).fill('Chat AI');

        await expect(section.heading(0)).toContainText('Chat AI');
        await expect(section.heading(0)).toContainText('Anthropic');
    });

    test('a name survives a save', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await (await section.revealNameField(0)).fill('Summaries');
        await section.save();

        await expect(await section.revealNameField(0)).toHaveValue('Summaries');
        await expect(section.heading(0)).toContainText('Summaries');
    });

    test('the name field suggests the provider name rather than inventing one', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        const field = await section.revealNameField(0);
        await expect(field).toHaveAttribute('placeholder', 'Anthropic');
        await expect(field).toHaveValue('');
    });

    /**
     * Naming a row is the exception, so the field is not in the way until it is asked for. The
     * heading still shows the name, so nothing is hidden that cannot be seen.
     */
    test('the name field is out of the way until the pencil asks for it', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        await expect(section.nameField(0)).toBeHidden();
        await expect(section.renameButton(0)).toBeVisible();
        await expect(section.renameButton(0)).toHaveAttribute('aria-expanded', 'false');

        await section.renameButton(0).click();

        await expect(section.nameField(0)).toBeVisible();
        await expect(section.renameButton(0)).toHaveAttribute('aria-expanded', 'true');
    });

    test('the pencil puts the caret in the field it revealed', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.renameButton(0).click();

        const focusedName = await page.evaluate(
            () => (document.activeElement as HTMLElement | null)?.getAttribute('data-row-label') !== null
        );

        expect(focusedName).toBe(true);
    });

    test('pressing the pencil again puts the field away', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        await section.renameButton(0).click();
        await expect(section.nameField(0)).toBeVisible();

        await section.renameButton(0).click();
        await expect(section.nameField(0)).toBeHidden();
    });

    test('an empty section says so instead of rendering an empty table', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();

        await expect(page.locator('.ai-services-empty-state')).toBeVisible();

        await section.addProvider('anthropic');
        await expect(page.locator('.ai-services-empty-state')).toBeHidden();
    });

    /**
     * Asserting only that focus landed somewhere in the row is too weak: the heading carries the
     * enable toggle, which is the row's first focusable control, and focus went there once. That
     * is worse than no focus at all, because the next Space switches the new row off.
     */
    test('adding a provider puts the caret in the first field, not on the row controls', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        const focused = await page.evaluate(() => {
            const active = document.activeElement as HTMLElement | null;
            if (!active) return null;
            return {
                rowId: active.closest('tr[id]')?.id ?? null,
                name: active.getAttribute('name'),
                insideFields: !!active.closest('.ai-service-row-fields'),
                type: (active as HTMLInputElement).type ?? null,
            };
        });
        const rowId = await section.row(0).getAttribute('id');

        expect(focused?.rowId).toBe(rowId);
        expect(focused?.insideFields).toBe(true);
        expect(focused?.name).toContain('[api_key]');
        expect(focused?.type).not.toBe('checkbox');
    });
});
