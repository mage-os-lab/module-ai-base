import { defineConfig, devices } from '@playwright/test';

/**
 * The admin form behaves differently by application mode: developer mode offers every registered
 * provider and explains what to install, anything else offers only the providers that can be used.
 * Both are real states of the same code, so CI runs this suite once per mode and each spec tags
 * which one it belongs to. Untagged specs run in both.
 */
const magentoMode = process.env.MAGENTO_MODE === 'production' ? 'production' : 'developer';

export default defineConfig({
    globalSetup: require.resolve('./global-setup'),

    testDir: './tests/',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    maxFailures: process.env.CI ? 5 : undefined,

    reporter: process.env.CI
        ? [['html', { open: 'never' }], ['list'], ['github'], ['junit', { outputFile: './test-results/junit-report.xml' }]]
        : [['list'], ['html', { open: 'never' }]],

    use: {
        baseURL: process.env.BASE_URL || 'http://localhost/',
        storageState: './test-results/admin-session.json',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        ignoreHTTPSErrors: true,
        actionTimeout: 15_000,
    },

    timeout: 120_000,

    /* Run everything except what is tagged for the mode this run is not in. */
    grepInvert: magentoMode === 'production' ? /@developer-mode/ : /@production-mode/,

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
