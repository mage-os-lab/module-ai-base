import { execFileSync } from 'child_process';
import path from 'path';

/**
 * One row {@see UsageLogFixture.readDetailedCalls} reads back: the columns the grid, dashboard and
 * CLI report gained in task 010, typed the way the fixture's own PHP snippet already casts them
 * before printing, so a spec never has to `parseInt` a stringly-typed count itself.
 */
export interface SeededDetailedCallRow {
    consumer: string;
    cacheReadTokens: number | null;
    cacheWriteTokens: number | null;
    failed: boolean;
    inputTokens: number | null;
}

/**
 * Seeds and removes exactly one row in `mageos_ai_usage_log`, entirely independent of
 * `mageos_ai/services/configuration`: `service_id`/`service_code` below are synthetic values this
 * fixture invents, never read from or written to the configured-service list this suite is
 * forbidden from touching (see the module's own `CLAUDE.md`).
 *
 * The dashboard (task 017) deliberately renders no graph at all when a period has zero calls —
 * "say 'no usage recorded yet' ... rather than rendering three empty charts" is task 017's own
 * brief — so proving the graph markup itself renders needs at least one real row. Nothing this
 * suite does through the browser alone can produce one without a live provider call, which this
 * suite also may not depend on, so this fixture reaches past the browser through Magento's own
 * object manager, the same process the admin request itself runs in, to insert one row and remove
 * it again. `seedOneCall()`/`remove()` are meant to be paired in a try/finally so a failing
 * assertion still leaves the table exactly as this fixture found it.
 */
export class UsageLogFixture {
    private static readonly MAGENTO_ROOT = path.resolve(__dirname, '../../../../../..');

    private insertedId: number | null = null;

    /**
     * Marker every row this fixture writes carries, so the cleanup can find them all without
     * knowing their ids and without touching a row it did not create.
     */
    private static readonly SEEDED_MODEL_PREFIX = 'e2e-seeded-';

    private seededSeries = false;

    async seedOneCall(): Promise<void> {
        const output = this.runPhp(`
            $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
            $table = $connection->getTableName('mageos_ai_usage_log');
            $connection->insert($table, [
                'service_id' => 'e2e-smoke-service',
                'service_code' => 'e2e_smoke',
                'model' => 'e2e-smoke-model',
                'consumer' => 'e2e_smoke_test',
                'store_id' => 0,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'streamed' => 0,
            ]);
            echo $connection->lastInsertId($table);
        `);
        this.insertedId = parseInt(output.trim(), 10);
    }

    /**
     * Seeds enough rows for the trend to have something to draw: two consumers across two service
     * rows, over two consecutive days, so both the by-consumer and the by-service charts have more
     * than one line and more than one bucket.
     *
     * Rows are marked by their model name rather than tracked by id, so the cleanup below can
     * remove exactly this fixture's rows in one statement even if a spec fails partway through.
     *
     * `storeId` and `label` exist for the store-scope specs, which need two sets of rows that a
     * scoped page can tell apart; the defaults are what every other caller wants.
     */
    async seedSeries(storeId = 0, label = ''): Promise<void> {
        this.runPhp(`
            $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
            $table = $connection->getTableName('mageos_ai_usage_log');
            $rows = [];
            // The dashboard defaults to the current calendar month, so a row seeded "2 days ago"
            // falls outside the period it is meant to appear in whenever the suite runs on the
            // 1st or 2nd. Clamping to the start of the month keeps every seeded row inside the
            // default period on every date; early in a month the two offsets collapse onto one
            // bucket, which no assertion depends on.
            $now = new \\DateTimeImmutable('now');
            $monthStart = $now->modify('first day of this month')->setTime(0, 0);
            foreach ([1, 2] as $dayOffset) {
                $seededAt = $now->modify('-' . $dayOffset . ' days');
                $at = ($seededAt < $monthStart ? $monthStart : $seededAt)->format('Y-m-d H:i:s');
                foreach ([
                    ['e2e_alpha${label}', 'e2e-service-a${label}', 'e2e_a', 400],
                    ['e2e_beta${label}',  'e2e-service-b${label}', 'e2e_b', 250],
                ] as [$consumer, $serviceId, $serviceCode, $tokens]) {
                    $rows[] = [
                        'service_id' => $serviceId,
                        'service_code' => $serviceCode,
                        'model' => '${UsageLogFixture.SEEDED_MODEL_PREFIX}' . $consumer,
                        'consumer' => $consumer,
                        'store_id' => ${storeId},
                        'input_tokens' => $tokens,
                        'output_tokens' => (int) ($tokens / 2),
                        'total_tokens' => $tokens + (int) ($tokens / 2),
                        'streamed' => 0,
                        'created_at' => $at,
                    ];
                }
            }
            $connection->insertMultiple($table, $rows);
        `);
        this.seededSeries = true;
    }

    /**
     * Whether the install already has usage inside the dashboard's default period.
     *
     * The empty-state spec asserts on a state it cannot create — it can only remove its own rows,
     * not anyone else's — so it asks first rather than assuming. A developer install that has
     * actually used the assistant this month is not a failure of the empty state.
     */
    async hasUsageInDefaultPeriod(): Promise<boolean> {
        const output = this.runPhp(`
            $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
            $table = $connection->getTableName('mageos_ai_usage_log');
            $monthStart = (new \\DateTimeImmutable('now'))->modify('first day of this month')->setTime(0, 0);
            $select = $connection->select()
                ->from($table, ['count' => 'COUNT(*)'])
                ->where('created_at >= ?', $monthStart->format('Y-m-d H:i:s'));
            echo (int) $connection->fetchOne($select);
        `);

        return parseInt(output.trim(), 10) > 0;
    }

    /**
     * Seeds the two rows the new grid columns, dashboard totals and CLI report (task 010) exist to
     * show: one call reporting cache reads and writes, and one that failed before the provider
     * reported any usage at all, the pre-response-failure case decision 2 of `_plan.md` describes,
     * which is why its token columns are null rather than zero.
     *
     * Both rows carry {@see SEEDED_MODEL_PREFIX}, so `remove()` cleans them up the same way as
     * `seedSeries()`'s rows. `label` keeps this call's consumer and model names apart from any
     * other row seeded during the same run, so a spec can find exactly its own rows by text.
     */
    async seedDetailedCalls(storeId = 0, label = ''): Promise<void> {
        this.runPhp(`
            $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
            $table = $connection->getTableName('mageos_ai_usage_log');
            $connection->insertMultiple($table, [
                [
                    'service_id' => 'e2e-cache-service${label}',
                    'service_code' => 'e2e_cache',
                    'model' => '${UsageLogFixture.SEEDED_MODEL_PREFIX}cache${label}',
                    'consumer' => 'e2e_cache_reader${label}',
                    'store_id' => ${storeId},
                    'input_tokens' => 500,
                    'output_tokens' => 200,
                    'total_tokens' => 700,
                    'cache_read_tokens' => 300,
                    'cache_write_tokens' => 150,
                    'streamed' => 0,
                    'failed' => 0,
                ],
                [
                    'service_id' => 'e2e-failed-service${label}',
                    'service_code' => 'e2e_failed',
                    'model' => '${UsageLogFixture.SEEDED_MODEL_PREFIX}failed${label}',
                    'consumer' => 'e2e_failed_call${label}',
                    'store_id' => ${storeId},
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                    'cache_read_tokens' => null,
                    'cache_write_tokens' => null,
                    'streamed' => 0,
                    'failed' => 1,
                ],
            ]);
        `);
        this.seededSeries = true;
    }

    /**
     * Reads back exactly the rows {@see seedDetailedCalls()} wrote for one `label`, the way the
     * "seeds rows with cache read, cache write, failed and null token counts" spec proves the
     * fixture itself before any other spec relies on it being right. Drives no browser, only the
     * same object-manager path every other method here uses.
     */
    async readDetailedCalls(label = ''): Promise<SeededDetailedCallRow[]> {
        const output = this.runPhp(`
            $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
            $table = $connection->getTableName('mageos_ai_usage_log');
            $select = $connection->select()
                ->from($table, ['consumer', 'cache_read_tokens', 'cache_write_tokens', 'failed', 'input_tokens'])
                ->where('model LIKE ?', '${UsageLogFixture.SEEDED_MODEL_PREFIX}%${label}')
                ->order('consumer ASC');
            $rows = array_map(static function (array $row): array {
                return [
                    'consumer' => $row['consumer'],
                    'cacheReadTokens' => $row['cache_read_tokens'] === null ? null : (int) $row['cache_read_tokens'],
                    'cacheWriteTokens' => $row['cache_write_tokens'] === null ? null : (int) $row['cache_write_tokens'],
                    'failed' => (bool) $row['failed'],
                    'inputTokens' => $row['input_tokens'] === null ? null : (int) $row['input_tokens'],
                ];
            }, $connection->fetchAll($select));
            echo json_encode($rows);
        `);

        return JSON.parse(output.trim()) as SeededDetailedCallRow[];
    }

    async remove(): Promise<void> {
        if (this.seededSeries) {
            this.runPhp(`
                $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
                $table = $connection->getTableName('mageos_ai_usage_log');
                $connection->delete($table, ["model LIKE '${UsageLogFixture.SEEDED_MODEL_PREFIX}%'"]);
            `);
            this.seededSeries = false;
        }

        if (this.insertedId === null) {
            return;
        }
        this.runPhp(`
            $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
            $table = $connection->getTableName('mageos_ai_usage_log');
            $connection->delete($table, ['entity_id = ?' => ${this.insertedId}]);
        `);
        this.insertedId = null;
    }

    /**
     * Runs a PHP snippet inside a bootstrapped Magento object manager, with `$om` already
     * available to it.
     *
     * Where that PHP runs is configurable, because the two places this suite runs reach Magento
     * differently. Locally the install is on the same filesystem, so `php` is enough. In CI the
     * store is a container the runner drives from outside, so the snippet has to be handed to it:
     * set `E2E_MAGENTO_EXEC` to the command that gets inside (`docker exec store`) and
     * `E2E_MAGENTO_ROOT` to the install's path in there. With neither set the behaviour is exactly
     * as before.
     *
     * The command is assembled as an argument list rather than a string, so nothing in the snippet
     * is ever interpreted by a shell on the way in.
     */
    private runPhp(body: string): string {
        const magentoRoot = process.env.E2E_MAGENTO_ROOT || UsageLogFixture.MAGENTO_ROOT;
        const script = `
            require '${magentoRoot}/app/bootstrap.php';
            $bootstrap = \\Magento\\Framework\\App\\Bootstrap::create('${magentoRoot}', $_SERVER);
            $om = $bootstrap->getObjectManager();
            ${body}
        `;

        const exec = (process.env.E2E_MAGENTO_EXEC || '').trim();
        if (exec === '') {
            return execFileSync('php', ['-r', script], { encoding: 'utf8' });
        }

        const [command, ...args] = exec.split(/\s+/);

        return execFileSync(command, [...args, 'php', '-r', script], { encoding: 'utf8' });
    }
}
