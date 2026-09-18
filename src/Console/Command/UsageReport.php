<?php

declare(strict_types=1);

namespace MageOS\AiBase\Console\Command;

use MageOS\AiBase\Api\Data\Period;
use MageOS\AiBase\Api\Data\UsageBreakdownInterface;
use MageOS\AiBase\Api\Data\UsageTotalsInterface;
use MageOS\AiBase\Api\UsageStatsInterface;
use MageOS\AiBase\Model\Usage\UsageConfig;
use MageOS\AiBase\Model\Usage\UsageTotals;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento mageos:ai:usage` — prints the same totals and per-consumer breakdown the admin
 * usage dashboard shows, through {@see UsageStatsInterface}, for merchants and support who work
 * from the shell and anyone diffing usage between environments. Goes through the same read
 * contract the dashboard uses rather than querying the usage tables itself, so the CLI and the
 * dashboard can never disagree about what a period's usage was.
 */
class UsageReport extends Command
{
    /**
     * `--period` value that resolves to {@see Period::today()}.
     */
    private const PERIOD_TODAY = 'today';

    /**
     * `--period` value that resolves to {@see Period::thisMonth()}, and the default when the
     * option is omitted: a merchant checking spend without a period in mind almost always means
     * "so far this month".
     */
    private const PERIOD_MONTH = 'month';

    /**
     * `--period` value that resolves to {@see Period::thisYear()}.
     */
    private const PERIOD_YEAR = 'year';

    /**
     * `--format` value that renders a {@see Table} for a human reading the terminal, and the
     * default when the option is omitted.
     */
    private const FORMAT_TABLE = 'table';

    /**
     * `--format` value that prints stable-keyed JSON for a script to parse.
     */
    private const FORMAT_JSON = 'json';

    /**
     * @param UsageStatsInterface $usageStats The one read contract the dashboard also goes
     *        through, so the CLI and the dashboard can never disagree about a period's usage.
     * @param UsageConfig $usageConfig Consulted before anything else: printing zeroes while
     *        tracking is disabled would read as a real "no usage" answer instead of an unset
     *        toggle.
     * @param TimezoneInterface $timezone Resolves the named periods' local calendar boundaries; a
     *        console command has no ambient store scope, so this always reads the default scope.
     * @param \DateTimeImmutable|null $now Deterministic clock for tests, exactly like the role
     *        {@see Period}'s own named constructors give their own `$now` argument. Production
     *        callers leave this null and get the real current instant.
     */
    public function __construct(
        private readonly UsageStatsInterface $usageStats,
        private readonly UsageConfig $usageConfig,
        private readonly TimezoneInterface $timezone,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('mageos:ai:usage')
            ->setDescription('Prints AI usage totals and a per-consumer breakdown for a period.')
            ->addOption(
                'period',
                null,
                InputOption::VALUE_REQUIRED,
                'One of "today", "month" or "year".',
                self::PERIOD_MONTH
            )
            ->addOption(
                'consumer',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit the report to one consumer.'
            )
            ->addOption(
                'store',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit the report to one store id. Omitted, the report covers every store.'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'One of "table" or "json".',
                self::FORMAT_TABLE
            );

        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->usageConfig->isEnabled()) {
            $output->writeln('AI usage tracking is disabled.');

            return Command::SUCCESS;
        }

        $periodOption = $this->stringOption($input, 'period', self::PERIOD_MONTH);
        $period = $this->resolvePeriod($periodOption);
        if ($period === null) {
            $output->writeln(sprintf('<error>Unknown period "%s".</error>', $periodOption));

            return Command::FAILURE;
        }

        $storeOption = $this->nullableStringOption($input, 'store');
        if ($storeOption !== null && !ctype_digit($storeOption)) {
            $output->writeln(sprintf('<error>Unknown store "%s".</error>', $storeOption));

            return Command::FAILURE;
        }
        $storeId = $storeOption === null ? null : (int) $storeOption;

        $consumerFilter = $this->nullableStringOption($input, 'consumer');

        if ($consumerFilter !== null) {
            $consumerRows = $this->filterToConsumer(
                $this->usageStats->getByConsumer($period, $storeId),
                $consumerFilter
            );
            $totals = $this->totalsFromRows($consumerRows);
        } else {
            $consumerRows = $this->usageStats->getByConsumer($period, $storeId);
            $totals = $this->usageStats->getTotals($period, $storeId);
        }

        if ($totals->getCalls() === 0) {
            $output->writeln('No usage recorded for this period.');

            return Command::SUCCESS;
        }

        $format = $this->stringOption($input, 'format', self::FORMAT_TABLE);

        if ($format === self::FORMAT_JSON) {
            $this->renderJson($output, $periodOption, $consumerFilter, $storeId, $totals, $consumerRows);

            return Command::SUCCESS;
        }

        $this->renderTable($output, $consumerFilter, $storeId, $totals, $consumerRows);

        return Command::SUCCESS;
    }

    /**
     * Prints one JSON object with stable keys and numbers as numbers.
     *
     * For a script to parse rather than a human to read: no ANSI decoration, no
     * locale-formatted numbers.
     *
     * @param OutputInterface $output
     * @param string $periodOption
     * @param string|null $consumerFilter
     * @param int|null $storeId
     * @param UsageTotalsInterface $totals
     * @param UsageBreakdownInterface[] $consumerRows
     * @return void
     */
    private function renderJson(
        OutputInterface $output,
        string $periodOption,
        ?string $consumerFilter,
        ?int $storeId,
        UsageTotalsInterface $totals,
        array $consumerRows
    ): void {
        $payload = [
            'period' => $periodOption,
            'consumer' => $consumerFilter,
            'store' => $storeId,
            'totals' => $this->totalsToArray($totals),
            'by_consumer' => array_map(
                fn (UsageBreakdownInterface $row): array => array_merge(
                    ['consumer' => $row->getGroupValue()],
                    $this->totalsToArray($row->getTotals())
                ),
                $consumerRows
            ),
        ];

        $output->writeln((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * The stable-keyed array shape {@see renderJson()} embeds for one {@see UsageTotalsInterface}.
     *
     * @param UsageTotalsInterface $totals
     * @return array{
     *     calls: int,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     cache_read_tokens: int|null,
     *     cache_write_tokens: int|null,
     *     reasoning_tokens: int|null,
     *     failed_calls: int
     * }
     */
    private function totalsToArray(UsageTotalsInterface $totals): array
    {
        return [
            'calls' => $totals->getCalls(),
            'input_tokens' => $totals->getInputTokens(),
            'output_tokens' => $totals->getOutputTokens(),
            'total_tokens' => $totals->getTotalTokens(),
            'cache_read_tokens' => $totals->getCacheReadTokens(),
            'cache_write_tokens' => $totals->getCacheWriteTokens(),
            'reasoning_tokens' => $totals->getReasoningTokens(),
            'failed_calls' => $totals->getFailedCalls(),
        ];
    }

    /**
     * Prints the totals section, and, for the unfiltered report, the "by consumer" breakdown
     * table straight through in whatever order {@see UsageStatsInterface::getByConsumer()}
     * returned it: that ordering, biggest consumer first, is the interface's own contract, so
     * this command has no sorting of its own to get wrong.
     *
     * @param OutputInterface $output
     * @param string|null $consumerFilter
     * @param int|null $storeId
     * @param UsageTotalsInterface $totals
     * @param UsageBreakdownInterface[] $consumerRows
     * @return void
     */
    private function renderTable(
        OutputInterface $output,
        ?string $consumerFilter,
        ?int $storeId,
        UsageTotalsInterface $totals,
        array $consumerRows
    ): void {
        $output->writeln($consumerFilter !== null
            ? sprintf('Totals for consumer "%s"%s:', $consumerFilter, $this->storeSuffix($storeId))
            : sprintf('Totals%s:', $this->storeSuffix($storeId)));

        $totalsTable = new Table($output);
        $totalsTable->setHeaders([
            'Calls',
            'Input tokens',
            'Output tokens',
            'Total tokens',
            'Cache read tokens',
            'Cache write tokens',
            'Reasoning tokens',
            'Failed',
        ]);
        $totalsTable->addRow([
            $totals->getCalls(),
            $totals->getInputTokens(),
            $totals->getOutputTokens(),
            $totals->getTotalTokens(),
            $totals->getCacheReadTokens() ?? '-',
            $totals->getCacheWriteTokens() ?? '-',
            $totals->getReasoningTokens() ?? '-',
            $totals->getFailedCalls(),
        ]);
        $totalsTable->render();

        if ($consumerFilter !== null) {
            return;
        }

        $output->writeln('');
        $output->writeln('By consumer:');

        $breakdownTable = new Table($output);
        $breakdownTable->setHeaders(['Consumer', 'Calls', 'Input tokens', 'Output tokens', 'Total tokens']);
        foreach ($consumerRows as $row) {
            $breakdownTable->addRow([
                $row->getGroupValue(),
                $row->getTotals()->getCalls(),
                $row->getTotals()->getInputTokens(),
                $row->getTotals()->getOutputTokens(),
                $row->getTotals()->getTotalTokens(),
            ]);
        }
        $breakdownTable->render();
    }

    /**
     * The part of a table heading naming the store the report was scoped to.
     *
     * Empty for a report covering every store.
     *
     * @param int|null $storeId
     * @return string
     */
    private function storeSuffix(?int $storeId): string
    {
        return $storeId === null ? '' : sprintf(' in store %d', $storeId);
    }

    /**
     * The rows of {@see UsageStatsInterface::getByConsumer()} whose
     * {@see UsageBreakdownInterface::getGroupValue()} matches the `--consumer` filter.
     *
     * @param UsageBreakdownInterface[] $rows
     * @param string $consumer
     * @return UsageBreakdownInterface[]
     */
    private function filterToConsumer(array $rows, string $consumer): array
    {
        return array_values(array_filter(
            $rows,
            static fn (UsageBreakdownInterface $row): bool => $row->getGroupValue() === $consumer
        ));
    }

    /**
     * The single matched consumer's own totals, or a zeroed {@see UsageTotalsInterface} when the
     * `--consumer` filter matched nothing.
     *
     * @param UsageBreakdownInterface[] $rows
     * @return UsageTotalsInterface
     */
    private function totalsFromRows(array $rows): UsageTotalsInterface
    {
        return $rows === [] ? $this->zeroTotals() : $rows[0]->getTotals();
    }

    /**
     * A totals value carrying every count as zero, for a `--consumer` filter that matched no row.
     *
     * @return UsageTotalsInterface
     */
    private function zeroTotals(): UsageTotalsInterface
    {
        return new UsageTotals(0, 0, 0, 0, null, null);
    }

    /**
     * The string value of a `VALUE_REQUIRED` option that declares a default, falling back to
     * that same default for the one case Symfony's own type leaves open: an option explicitly
     * passed with no value at all.
     *
     * @param InputInterface $input
     * @param string $name
     * @param string $default
     * @return string
     */
    private function stringOption(InputInterface $input, string $name, string $default): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : $default;
    }

    /**
     * The string value of a `VALUE_REQUIRED` option with no default.
     *
     * Null when the caller left it out entirely.
     *
     * @param InputInterface $input
     * @param string $name
     * @return string|null
     */
    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Resolves the `--period` option to the {@see Period} it names.
     *
     * Null when the option names none of the periods this command understands.
     *
     * @param string $periodOption
     * @return Period|null
     */
    private function resolvePeriod(string $periodOption): ?Period
    {
        return match ($periodOption) {
            self::PERIOD_TODAY => Period::today($this->timezone, $this->now),
            self::PERIOD_MONTH => Period::thisMonth($this->timezone, $this->now),
            self::PERIOD_YEAR => Period::thisYear($this->timezone, $this->now),
            default => null,
        };
    }
}
