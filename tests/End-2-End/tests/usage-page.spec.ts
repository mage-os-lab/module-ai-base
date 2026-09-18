import { expect, test } from '@playwright/test';
import { UsagePage } from '../support/UsagePage';
import { UsageLogFixture } from '../support/UsageLogFixture';

/**
 * Smoke test for the module's first non-configuration admin screen (tasks 014/015/016/017): proves
 * the menu item, the layout handle, the dashboard block and the grid UI component are wired
 * together in a real browser, not just individually correct in isolation. Everything else in this
 * feature is verified against fakes, parsed XML or a test database; this is the only check that the
 * admin page actually renders.
 *
 * Deliberately does not touch `mageos_ai/services/configuration`: unlike every other spec in this
 * suite, this page needs no configured AI service and must not remove one that another suite on
 * this install depends on.
 */
test.describe('AI Token Usage page', () => {
    test('it opens the usage page from the reports menu', async ({ page }) => {
        await new UsagePage(page).openFromMenu();

        expect(page.url()).toContain('mageos_ai/usage/index');
        await expect(page).toHaveTitle(/AI Token Usage/);
    });

    test('it renders the dashboard above the usage grid', async ({ page }) => {
        await new UsagePage(page).openFromMenu();

        const dashboardIsAboveGrid = await page.evaluate(() => {
            const dashboard = document.querySelector('.mageos-ai-usage-dashboard');
            const grid = document.querySelector('[data-bind*="mageos_ai_usage_listing"].admin__data-grid-outer-wrap');
            if (!dashboard || !grid) {
                return false;
            }

            return dashboard.getBoundingClientRect().top < grid.getBoundingClientRect().top;
        });

        expect(dashboardIsAboveGrid).toBe(true);
    });

    test('it shows the empty state when no usage has been recorded', async ({ page }) => {
        // This spec cannot create the state it asserts on: it can remove its own rows, never
        // anyone else's. An install that has genuinely used the assistant this month is outside
        // what it can say anything about, so it says so rather than failing.
        test.skip(
            await new UsageLogFixture().hasUsageInDefaultPeriod(),
            'the install already has usage recorded in the default period',
        );

        const usagePage = new UsagePage(page);
        await usagePage.openFromMenu();

        await expect(usagePage.emptyStateMessage()).toBeVisible();
        await expect(usagePage.emptyStateMessage()).toContainText('No usage recorded yet for this period.');
        await expect(usagePage.totals()).toHaveCount(0);
        await expect(usagePage.grid()).toContainText('records');
    });

    test('it renders a graph element with no console errors', async ({ page }) => {
        const consoleErrors: string[] = [];
        page.on('console', (message) => {
            if (message.type() === 'error') {
                consoleErrors.push(message.text());
            }
        });
        page.on('pageerror', (error) => consoleErrors.push(error.message));

        const usageLog = new UsageLogFixture();
        await usageLog.seedOneCall();
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();

            const graph = usagePage.graphs().first();
            await expect(graph).toBeVisible();
            await expect(graph).toHaveAttribute('role', 'img');
        } finally {
            await usageLog.remove();
        }

        expect(consoleErrors).toEqual([]);
    });

    test('it switches the trend between consumers and service rows', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        await usageLog.seedSeries();
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();

            await expect(usagePage.currentTrend()).toHaveText('By consumer');
            // The fixture cannot promise its consumers outrank whatever the install has already
            // recorded this month, so it asks for presence in the legend, not for first place.
            await expect(usagePage.legendEntries().filter({ hasText: 'e2e_' })).not.toHaveCount(0);

            await usagePage.selectTrend('By service');

            await expect(usagePage.currentTrend()).toHaveText('By service');
            expect(page.url()).toContain('series=service');
            // The by-service chart is keyed on service rows, so no consumer name survives into it.
            await expect(usagePage.legendEntries().filter({ hasText: 'e2e_alpha' })).toHaveCount(0);
        } finally {
            await usageLog.remove();
        }
    });

    test('it keeps the selected trend when the period changes', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        await usageLog.seedSeries();
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();
            await usagePage.selectTrend('By service');

            await usagePage.selectPeriod('This year');

            // Losing either half of the state on a click is the failure this guards: the period
            // links and the trend links each carry both parameters for exactly this reason.
            expect(page.url()).toContain('period=this_year');
            expect(page.url()).toContain('series=service');
            await expect(usagePage.currentTrend()).toHaveText('By service');
        } finally {
            await usageLog.remove();
        }
    });

    test('it draws a legend entry for every trend line', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        await usageLog.seedSeries();
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();

            const lines = usagePage.dashboard().locator('.mageos-ai-usage-graph-trend');
            const lineCount = await lines.count();

            expect(lineCount).toBeGreaterThan(1);
            await expect(usagePage.legendEntries()).toHaveCount(lineCount);
        } finally {
            await usageLog.remove();
        }
    });

    test('it scopes the dashboard to the store picked in the selector', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        // One set of rows per store, named apart, so a scoped page either shows the right set or
        // visibly shows the wrong one.
        await usageLog.seedSeries(1, '_one');
        await usageLog.seedSeries(2, '_two');
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();

            await expect(usagePage.currentStore()).toHaveText('All stores');
            await expect(usagePage.dashboard()).toContainText('e2e_alpha_one');
            await expect(usagePage.dashboard()).toContainText('e2e_alpha_two');

            await usagePage.selectStore('Default Store View');

            expect(page.url()).toContain('store=1');
            await expect(usagePage.dashboard()).toContainText('e2e_alpha_one');
            await expect(usagePage.dashboard()).not.toContainText('e2e_alpha_two');
        } finally {
            await usageLog.remove();
        }
    });

    test('it keeps the selected store when the period changes', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        await usageLog.seedSeries(1, '_one');
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();
            await usagePage.selectStore('Default Store View');

            await usagePage.selectPeriod('This year');

            // Every selector carries the whole view, so switching one never resets another.
            expect(page.url()).toContain('period=this_year');
            expect(page.url()).toContain('store=1');
            await expect(usagePage.currentStore()).toHaveText('Default Store View');
        } finally {
            await usageLog.remove();
        }
    });

    test('it widens back to every store when the url names a store that does not exist', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        await usageLog.seedSeries(1, '_one');
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();
            const unscopedTotals = await usagePage.totals().first().innerText();

            await usagePage.openWithQuery('?store=4242');

            // A stale bookmark should show a total that is too broad, which a reader can see,
            // rather than an empty page that reads as the feature being broken.
            await expect(usagePage.emptyStateMessage()).toHaveCount(0);
            await expect(usagePage.currentStore()).toHaveText('All stores');
            expect(await usagePage.totals().first().innerText()).toBe(unscopedTotals);
        } finally {
            await usageLog.remove();
        }
    });

    test('seeds rows with cache read, cache write, failed and null token counts', async () => {
        // Proves the fixture itself before any other spec below relies on it being right: no
        // browser involved, just the same object-manager path every other fixture method uses.
        const usageLog = new UsageLogFixture();
        const label = '_seed_check';
        await usageLog.seedDetailedCalls(0, label);
        try {
            const rows = await usageLog.readDetailedCalls(label);

            const cachedRow = rows.find((row) => row.consumer === `e2e_cache_reader${label}`);
            const failedRow = rows.find((row) => row.consumer === `e2e_failed_call${label}`);

            expect(cachedRow).toMatchObject({ cacheReadTokens: 300, cacheWriteTokens: 150, failed: false });
            expect(failedRow).toMatchObject({
                failed: true,
                inputTokens: null,
                cacheReadTokens: null,
                cacheWriteTokens: null,
            });
        } finally {
            await usageLog.remove();
        }
    });

    test('shows the failed calls count in the dashboard totals', async ({ page }) => {
        const usagePage = new UsagePage(page);
        await usagePage.openFromMenu();
        // Scoped to a store the rest of this suite never writes to, so a figure this test reads
        // exactly is not at the mercy of another spec's rows landing in the same period.
        await usagePage.openWithQuery('?store=3');
        const hadNoUsageBefore = (await usagePage.emptyStateMessage().count()) > 0;
        const failedCallsBefore = hadNoUsageBefore
            ? 0
            : parseInt(await usagePage.failedCallsStat().innerText(), 10);

        const usageLog = new UsageLogFixture();
        await usageLog.seedDetailedCalls(3, '_dashboard_failed');
        try {
            await usagePage.openWithQuery('?store=3');

            await expect(usagePage.failedCallsStat()).toHaveText(String(failedCallsBefore + 1));
        } finally {
            await usageLog.remove();
        }
    });

    test('shows cache read and cache write columns in the grid', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        const label = '_grid_cache';
        await usageLog.seedDetailedCalls(0, label);
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();

            const row = usagePage.gridRow(`e2e_cache_reader${label}`);
            await expect(row).toBeVisible();
            await expect(await usagePage.cacheReadTokensCell(row)).toHaveText('300');
            await expect(await usagePage.cacheWriteTokensCell(row)).toHaveText('150');
        } finally {
            await usageLog.remove();
        }
    });

    test('shows an empty cell for a row without reported tokens', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        const label = '_grid_empty';
        await usageLog.seedDetailedCalls(0, label);
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();

            const row = usagePage.gridRow(`e2e_failed_call${label}`);
            await expect(row).toBeVisible();
            await expect(await usagePage.cacheReadTokensCell(row)).toHaveText('');
        } finally {
            await usageLog.remove();
        }
    });

    test('it reveals a value panel when a trend bucket is hovered', async ({ page }) => {
        const usageLog = new UsageLogFixture();
        await usageLog.seedSeries();
        try {
            const usagePage = new UsagePage(page);
            await usagePage.openFromMenu();

            const tooltip = usagePage.trendTooltips().first();
            // The panel is revealed by CSS on its band's hover, so opacity is what changes — the
            // element is in the DOM either way, which is why this asserts on the computed style
            // rather than on visibility.
            await expect(tooltip).toHaveCSS('opacity', '0');

            await usagePage.trendBands().first().hover({ force: true });

            await expect(tooltip).toHaveCSS('opacity', '1');
            await expect(tooltip).toContainText('e2e_');
        } finally {
            await usageLog.remove();
        }
    });
});
