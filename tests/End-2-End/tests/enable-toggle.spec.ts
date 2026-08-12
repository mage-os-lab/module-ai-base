import { expect, test } from '@playwright/test';
import { AiConfigurationSection } from '../support/AiConfigurationSection';

/**
 * Turning a service off keeps its configuration and stops anything using it. The form half of that
 * is here; that the selector actually withholds a disabled row is covered by the integration test,
 * because no page shows what a consumer module was handed.
 */
test.describe('Enabling and disabling a service', () => {
    test.beforeEach(async ({ page }) => {
        await new AiConfigurationSection(page).reset();
    });

    test('a new service is on, because adding one is asking to use it', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        await expect(section.enableToggle(0)).toBeChecked();
        await expect(section.row(0)).not.toHaveClass(/ai-service-row-disabled/);
    });

    test('turning a service off marks the row and survives a save', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.fieldByLabel(0, 'API Key').fill('sk-still-here');
        await section.enableToggle(0).uncheck();

        await expect(section.row(0)).toHaveClass(/ai-service-row-disabled/);

        await section.save();

        await expect(section.enableToggle(0)).not.toBeChecked();
        await expect(section.row(0)).toHaveClass(/ai-service-row-disabled/);
    });

    /**
     * The point of disabling rather than deleting: the credential is still there to come back to.
     */
    test('a disabled service keeps its credentials and can be turned back on', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.fieldByLabel(0, 'API Key').fill('sk-keep-me');
        await section.enableToggle(0).uncheck();
        await section.save();

        await expect(section.fieldByLabel(0, 'API Key')).toHaveValue('******');

        await section.enableToggle(0).check();
        await section.save();

        await expect(section.enableToggle(0)).toBeChecked();
        await expect(section.row(0)).not.toHaveClass(/ai-service-row-disabled/);
        await expect(section.fieldByLabel(0, 'API Key')).toHaveValue('******');
    });

    /**
     * Nothing may call a disabled service, so the two controls that call one are switched off with
     * it rather than left to be clicked and fail.
     */
    test('the controls that would use the service are switched off with it', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.save();

        await expect(section.row(0).locator('.action-test-connection')).toBeEnabled();
        await expect(section.row(0).locator('.ai-refresh-models')).toBeEnabled();

        await section.enableToggle(0).uncheck();

        await expect(section.row(0).locator('.action-test-connection')).toBeDisabled();
        await expect(section.row(0).locator('.ai-refresh-models')).toBeDisabled();

        await section.enableToggle(0).check();

        await expect(section.row(0).locator('.action-test-connection')).toBeEnabled();
        await expect(section.row(0).locator('.ai-refresh-models')).toBeEnabled();
    });

    /**
     * An unchecked checkbox posts nothing at all, so without the hidden field beside it a disabled
     * row would come back indistinguishable from one saved before the setting existed.
     */
    test('the off state actually reaches the server', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        // Read what the form will submit for this field, rather than the encoded request body:
        // the encoding is Magento's business and varies, while this is exactly the question.
        const submittedValues = () => section.enableToggle(0).evaluate((toggle) => {
            const form = toggle.closest('form') as HTMLFormElement;
            return new FormData(form).getAll(toggle.getAttribute('name') as string).map(String);
        });

        // Checked: the hidden field's "off" is sent first and the checkbox overwrites it, which is
        // the only reason a checkbox can express "off" at all.
        expect(await submittedValues()).toEqual(['0', '1']);

        await section.enableToggle(0).uncheck();

        // Unchecked: the checkbox sends nothing, leaving the hidden field to say so on its own.
        expect(await submittedValues()).toEqual(['0']);
    });

    test('turning a service off leaves the others alone', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.addProvider('openai');
        await section.save();

        await section.enableToggle(0).uncheck();
        await section.save();

        await expect(section.enableToggle(0)).not.toBeChecked();
        await expect(section.enableToggle(1)).toBeChecked();
        await expect(section.row(1)).not.toHaveClass(/ai-service-row-disabled/);
    });
});
