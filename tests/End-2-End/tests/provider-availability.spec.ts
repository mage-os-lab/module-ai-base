import { expect, test } from '@playwright/test';
import { AiConfigurationSection } from '../support/AiConfigurationSection';

/**
 * Which providers the form offers depends on the application mode, so CI runs this suite once per
 * mode and each test here declares which one it is about.
 */
test.describe('Provider availability', () => {
    test('developer mode offers providers whose package is missing, and says what to install @developer-mode', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();

        await expect(page.locator('.add-ai-service.ai-service-unavailable').first()).toBeVisible();
        await expect(page.locator('.ai-service-availability-note')).toBeVisible();
        await expect(page.locator('.ai-service-availability-note')).toContainText('composer require');
        await expect(page.locator('.ai-service-availability-hint')).toHaveCount(0);
    });

    test('production offers only providers that can be used, and points at a developer @production-mode', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();

        await expect(page.locator('.add-ai-service.ai-service-unavailable')).toHaveCount(0);
        await expect(page.locator('.ai-service-availability-note')).toHaveCount(0);
        await expect(page.locator('.ai-service-availability-hint')).toContainText('Contact your developer');
    });

    /**
     * A row saved while its provider was installable has to keep rendering after the package is
     * gone, or an administrator loses the credentials with no way to see what happened.
     */
    test('a row of a hidden provider still renders with its fields @production-mode', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.reset();
        await section.open();

        const offered = await page.locator('.add-ai-service').count();
        expect(offered).toBeGreaterThan(0);

        await section.addProvider(await page.locator('.add-ai-service').first().getAttribute('data-ai-service') as string);
        await section.save();

        await expect(section.rows()).toHaveCount(1);
        await expect(section.fieldByLabel(0, 'Model')).toBeVisible();
    });

    test('the guidance sits with the buttons it describes', async ({ page }) => {
        await new AiConfigurationSection(page).open();

        const hintAboveButtons = await page.evaluate(() => {
            const hint = document.querySelector('.ai-service-add-hint');
            const buttons = document.querySelector('.col-actions-add ul');
            if (!hint || !buttons) return false;
            return hint.getBoundingClientRect().bottom <= buttons.getBoundingClientRect().top + 1;
        });

        expect(hintAboveButtons).toBe(true);
    });
});
