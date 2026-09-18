import { expect, type Locator, type Page } from '@playwright/test';

/**
 * The AI Token Usage admin page (tasks 014/015/017), reached the way an administrator reaches it:
 * through the Reports menu, not a hand-built URL. Admin URLs carry a per-route secret key, so a
 * hand-built URL lands on the dashboard instead of this page — the same reasoning
 * `AiConfigurationSection.open()` documents for the configuration section.
 */
export class UsagePage {
    constructor(private readonly page: Page) {}

    /**
     * Clicks through the menu an administrator actually sees: Reports, then the "AI Token Usage"
     * item registered under it. Waits for both halves of the page — the dashboard (task
     * 017) and the grid (task 015) — to be visible before handing control back, so every test that
     * calls this can assume the page is not merely loaded but rendered.
     */
    async openFromMenu(): Promise<void> {
        const adminPath = process.env.ADMIN_PATH || 'admin';
        await this.page.goto(adminPath, { waitUntil: 'networkidle' });
        await this.dismissAdminUsageNotification();
        await this.page.locator('#menu-magento-reports-report > a').click();
        await this.page.locator('[data-ui-id="menu-mageos-aibase-usage"] a').click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.dashboard()).toBeVisible();
        await expect(this.grid()).toBeVisible();
    }

    /**
     * Reopens the page it is already on with extra query parameters, for the view state that has
     * no control to click on a single-store install.
     */
    async openWithQuery(query: string): Promise<void> {
        await this.page.goto(this.page.url().split('?')[0] + query, { waitUntil: 'networkidle' });
        await expect(this.dashboard()).toBeVisible();
    }

    /**
     * Magento Open Source greets a fresh admin with Magento_AdminAnalytics's "Allow Adobe to
     * collect usage data" modal, which sits over the whole page and swallows the menu click this
     * object is about to make. Mage-OS does not ship that module, so the modal is answered only
     * when it is actually there; the answer itself is irrelevant to anything this suite asserts.
     */
    private async dismissAdminUsageNotification(): Promise<void> {
        const notification = this.page.locator('.modal-popup.admin-usage-notification._show');
        if ((await notification.count()) === 0) {
            return;
        }

        await notification.getByRole('button', { name: "Don't Allow" }).click();
        await expect(notification).toBeHidden();
    }

    dashboard(): Locator {
        return this.page.locator('.mageos-ai-usage-dashboard');
    }

    /**
     * The scope selector, which the dashboard only draws on an install that has more than one
     * store to choose between.
     */
    storeSelector(): Locator {
        return this.dashboard().locator('.mageos-ai-usage-store-selector');
    }

    /**
     * The currently selected store, as its label reads in the selector.
     */
    currentStore(): Locator {
        return this.storeSelector().locator('li.current');
    }

    /**
     * Switches the store scope and waits for the reloaded page, the same way the trend and the
     * period selectors do.
     */
    async selectStore(label: string): Promise<void> {
        await this.storeSelector().getByRole('link', { name: label, exact: true }).click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.dashboard()).toBeVisible();
    }

    grid(): Locator {
        return this.page.locator('[data-bind*="mageos_ai_usage_listing"].admin__data-grid-outer-wrap');
    }

    emptyStateMessage(): Locator {
        return this.dashboard().locator('.mageos-ai-usage-dashboard-empty');
    }

    totals(): Locator {
        return this.dashboard().locator('.mageos-ai-usage-dashboard-totals');
    }

    /**
     * The inline bar-chart graphs {@see \MageOS\AiBase\Model\Usage\Graph\SvgRenderer} draws (task
     * 016), one per breakdown. Each is a plain `<svg role="img">`, the marker the renderer's own
     * `wrapSvg()` always emits.
     */
    /**
     * The trend panel's own selector, which switches the chart between consumers and service rows.
     */
    trendSelector(): Locator {
        return this.page.locator('.mageos-ai-usage-trend-selector');
    }

    /**
     * The currently selected trend, as its label reads in the selector.
     */
    currentTrend(): Locator {
        return this.trendSelector().locator('li.current');
    }

    /**
     * Switches the trend and waits for the reloaded page to finish rendering, so a caller never
     * asserts against the chart it was looking at before the click.
     */
    async selectTrend(label: string): Promise<void> {
        await this.trendSelector().getByRole('link', { name: label, exact: true }).click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.dashboard()).toBeVisible();
    }

    /**
     * Switches the period the same way.
     */
    async selectPeriod(label: string): Promise<void> {
        await this.page.locator('.mageos-ai-usage-dashboard-period-selector')
            .getByRole('link', { name: label, exact: true }).click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.dashboard()).toBeVisible();
    }

    /**
     * One entry per series drawn, the dependable identity channel beside the lines themselves.
     */
    legendEntries(): Locator {
        return this.dashboard().locator('.mageos-ai-usage-graph-legend-entry');
    }

    /**
     * The trend's per-bucket hover targets.
     */
    trendBands(): Locator {
        return this.dashboard().locator('.mageos-ai-usage-graph-band');
    }

    /**
     * The panel a hovered bucket reveals.
     */
    trendTooltips(): Locator {
        return this.dashboard().locator('.mageos-ai-usage-graph-tip');
    }

    graphs(): Locator {
        return this.dashboard().locator('svg[role="img"]');
    }

    /**
     * One of the small `<dt>`/`<dd>` figures beside the hero total (task 010): failed calls, cache
     * read tokens, cache write tokens. Located by its label rather than by position, since the
     * three sit in one `<dl>` with no other per-stat hook.
     */
    private dashboardStat(label: string): Locator {
        return this.dashboard().locator('.mageos-ai-usage-dashboard-stat').filter({ hasText: label }).locator('dd');
    }

    failedCallsStat(): Locator {
        return this.dashboardStat('Failed calls');
    }

    /**
     * A data row in the usage grid identified by text unique to it — the grid renders no stable
     * per-row id, so a fixture-seeded consumer or model name is the only handle a spec has.
     */
    gridRow(matchingText: string): Locator {
        return this.grid().locator('tbody tr').filter({ hasText: matchingText });
    }

    /**
     * The live position of a column, by its header label.
     *
     * The grid renders no `data-column` attribute per `<td>` (see
     * `vendor/mage-os/module-ui/view/base/web/templates/grid/listing.html`), and an administrator
     * can drag columns into any order, which the grid then remembers per user — so a cell's
     * position is not something a spec can hard-code. Resolving it from the header text this way
     * finds it wherever it currently sits.
     */
    private async gridColumnIndex(label: string): Promise<number> {
        const headers = await this.grid().locator('thead th').allTextContents();
        const index = headers.findIndex((header) => header.trim() === label);
        if (index === -1) {
            throw new Error(`No "${label}" column in the usage grid.`);
        }

        return index;
    }

    async cacheReadTokensCell(row: Locator): Promise<Locator> {
        return row.locator('td').nth(await this.gridColumnIndex('Cache Read Tokens'));
    }

    async cacheWriteTokensCell(row: Locator): Promise<Locator> {
        return row.locator('td').nth(await this.gridColumnIndex('Cache Write Tokens'));
    }
}
