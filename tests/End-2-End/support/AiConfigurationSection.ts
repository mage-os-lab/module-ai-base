import { expect, type Locator, type Page } from '@playwright/test';

/**
 * The AI Configuration section, reached the way an administrator reaches it.
 *
 * Admin URLs carry a per-route secret key, so a hand-built URL lands on the dashboard. Following
 * the same links the menu offers is both what a user does and the only thing that works.
 */
export class AiConfigurationSection {
    private sectionUrl: string | null = null;

    constructor(private readonly page: Page) {}

    async open(): Promise<void> {
        if (this.sectionUrl !== null) {
            await this.page.goto(this.sectionUrl, { waitUntil: 'networkidle' });
            await this.waitUntilRendered();
            return;
        }

        const adminPath = process.env.ADMIN_PATH || 'admin';
        await this.page.goto(adminPath, { waitUntil: 'networkidle' });

        const configHref = await this.page.locator('a[href*="admin/system_config"]').first().getAttribute('href');
        await this.page.goto(configHref as string, { waitUntil: 'networkidle' });

        const sectionHref = await this.page.locator('a[href*="section/mageos_ai"]').first().getAttribute('href');
        await this.page.goto(sectionHref as string, { waitUntil: 'networkidle' });

        this.sectionUrl = this.page.url();
        await this.waitUntilRendered();
    }

    /**
     * The rows are built by JavaScript from a schema, so "the section loaded" is not the same as
     * "the rows exist".
     */
    private async waitUntilRendered(): Promise<void> {
        await expect(this.page.locator('.ai-services-configurator')).toBeVisible();
        await this.page.waitForFunction(() => {
            const container = document.querySelector('.ai-services-configurator');
            return !!container && container.querySelectorAll('.add-ai-service').length > 0;
        });
    }

    providerButton(code: string): Locator {
        return this.page.locator(`.add-ai-service[data-ai-service="${code}"]`);
    }

    rows(): Locator {
        return this.page.locator('.ai-services-configurator tbody tr[id]');
    }

    row(index: number): Locator {
        return this.rows().nth(index);
    }

    heading(index: number): Locator {
        return this.row(index).locator('.ai-service-row-heading');
    }

    nameField(index: number): Locator {
        return this.row(index).locator('[data-row-label]');
    }

    enableToggle(index: number): Locator {
        return this.row(index).locator('[data-row-enabled]');
    }

    renameButton(index: number): Locator {
        return this.row(index).locator('.ai-service-rename');
    }

    /**
     * The name field is hidden until the pencil in the heading asks for it, which is the only way
     * an administrator reaches it too.
     */
    async revealNameField(index: number): Promise<Locator> {
        const field = this.nameField(index);
        if (!(await field.isVisible())) {
            await this.renameButton(index).click();
        }
        await expect(field).toBeVisible();

        return field;
    }

    fieldByLabel(index: number, label: string): Locator {
        return this.row(index).locator('tr', { has: this.page.locator(`th label:text-is("${label}")`) })
            .locator('input, select')
            .first();
    }

    async addProvider(code: string): Promise<void> {
        const before = await this.rows().count();
        await this.providerButton(code).click();
        await expect(this.rows()).toHaveCount(before + 1);
    }

    async save(): Promise<void> {
        await this.page.click('#save');
        await this.page.waitForLoadState('networkidle');
        await expect(this.page.locator('.message-success')).toBeVisible();
        await this.waitUntilRendered();
    }

    /**
     * Remove every configured row and save, so a spec starts from a known empty section.
     */
    async reset(): Promise<void> {
        await this.open();

        const count = await this.rows().count();
        if (count === 0) {
            return;
        }

        // Scoped to the clearing loop and removed afterwards. A listener left on the page would
        // answer the confirmation a later test asks its own question about, and that test would
        // pass or fail on this helper rather than on the form.
        const acceptConfirmation = (dialog: { accept: () => Promise<void> }) => dialog.accept();
        this.page.on('dialog', acceptConfirmation);
        try {
            for (let i = 0; i < count; i += 1) {
                await this.row(0).locator('.action-delete').click();
            }
            await expect(this.rows()).toHaveCount(0);
        } finally {
            this.page.off('dialog', acceptConfirmation);
        }
        await this.save();
    }
}
