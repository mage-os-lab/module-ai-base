<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class DbSchemaTest extends TestCase
{
    private const RAW_TABLE = 'mageos_ai_usage_log';
    private const DAILY_TABLE = 'mageos_ai_usage_daily';

    private SimpleXMLElement $schema;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/src/etc/db_schema.xml';
        $this->schema = new SimpleXMLElement((string) file_get_contents($path));
    }

    public function test_it_declares_the_raw_usage_log_table_with_an_auto_increment_primary_key(): void
    {
        $table = $this->getTable(self::RAW_TABLE);

        $entityId = $table->xpath('column[@name="entity_id"]')[0] ?? null;
        self::assertNotNull($entityId);
        self::assertSame('true', (string) $entityId['identity']);
        $primary = $table->xpath('constraint[@xsi:type="primary"]/column[@name="entity_id"]');
        self::assertCount(1, $primary);
    }

    public function test_it_declares_every_token_count_column_on_the_raw_usage_table(): void
    {
        $table = $this->getTable(self::RAW_TABLE);

        foreach (['input_tokens', 'output_tokens', 'total_tokens', 'reasoning_tokens'] as $columnName) {
            self::assertNotNull($table->xpath('column[@name="' . $columnName . '"]')[0] ?? null, $columnName);
        }
    }

    public function test_it_declares_nullable_token_counts_on_the_log(): void
    {
        $table = $this->getTable(self::RAW_TABLE);

        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $columnName) {
            $column = $table->xpath('column[@name="' . $columnName . '"]')[0] ?? null;
            self::assertNotNull($column, $columnName);
            self::assertSame('true', (string) $column['nullable'], $columnName);
            self::assertSame('', (string) $column['default'], $columnName);
        }
    }

    public function test_it_declares_cache_read_and_write_columns_on_both_tables(): void
    {
        foreach ([self::RAW_TABLE, self::DAILY_TABLE] as $tableName) {
            $table = $this->getTable($tableName);

            foreach (['cache_read_tokens', 'cache_write_tokens'] as $columnName) {
                self::assertNotNull($table->xpath('column[@name="' . $columnName . '"]')[0] ?? null, $tableName . '.' . $columnName);
            }
        }
    }

    public function test_it_no_longer_declares_a_cached_tokens_column(): void
    {
        foreach ([self::RAW_TABLE, self::DAILY_TABLE] as $tableName) {
            $table = $this->getTable($tableName);

            self::assertNull($table->xpath('column[@name="cached_tokens"]')[0] ?? null, $tableName);
        }
    }

    public function test_it_declares_a_failed_flag_on_the_log(): void
    {
        $table = $this->getTable(self::RAW_TABLE);

        $failed = $table->xpath('column[@name="failed"]')[0] ?? null;
        self::assertNotNull($failed);
        self::assertSame('boolean', (string) $table->xpath('column[@name="failed"]/@xsi:type')[0]);
        self::assertSame('false', (string) $failed['nullable']);
        self::assertSame('false', (string) $failed['default']);
    }

    public function test_it_declares_a_failed_calls_count_on_the_daily_table(): void
    {
        $table = $this->getTable(self::DAILY_TABLE);

        $failedCalls = $table->xpath('column[@name="failed_calls"]')[0] ?? null;
        self::assertNotNull($failedCalls);
        self::assertSame('int', (string) $table->xpath('column[@name="failed_calls"]/@xsi:type')[0]);
        self::assertSame('true', (string) $failedCalls['unsigned']);
        self::assertSame('false', (string) $failedCalls['nullable']);
        self::assertSame('0', (string) $failedCalls['default']);
    }

    public function test_it_declares_no_column_capable_of_holding_prompt_or_response_content(): void
    {
        $forbiddenTypes = ['text', 'mediumtext', 'longtext', 'blob', 'mediumblob', 'longblob', 'json'];

        $columnTypes = $this->schema->xpath('//table/column/@xsi:type');

        foreach ($columnTypes as $columnType) {
            self::assertNotContains((string) $columnType, $forbiddenTypes);
        }
    }

    public function test_it_declares_the_daily_rollup_table_with_a_unique_constraint_over_the_grouping_keys(): void
    {
        $table = $this->getTable(self::DAILY_TABLE);

        $uniqueColumns = $table->xpath('constraint[@xsi:type="unique"]/column');
        $uniqueColumnNames = array_map(static fn (SimpleXMLElement $column): string => (string) $column['name'], $uniqueColumns);

        self::assertSame(['usage_date', 'service_id', 'model', 'consumer', 'store_id'], $uniqueColumnNames);
    }

    public function test_it_declares_store_id_as_not_null_on_both_tables_so_the_unique_key_can_match(): void
    {
        foreach ([self::RAW_TABLE, self::DAILY_TABLE] as $tableName) {
            $storeId = $this->getTable($tableName)->xpath('column[@name="store_id"]')[0];

            self::assertSame('false', (string) $storeId['nullable'], $tableName);
        }
    }

    public function test_it_indexes_the_raw_table_on_the_columns_the_grid_filters_by(): void
    {
        $table = $this->getTable(self::RAW_TABLE);

        $indexedColumns = array_map(
            static fn (SimpleXMLElement $column): string => (string) $column['name'],
            $table->xpath('index/column'),
        );

        foreach (['created_at', 'consumer', 'service_id'] as $columnName) {
            self::assertContains($columnName, $indexedColumns, $columnName);
        }
    }

    public function test_it_lists_both_tables_in_the_declarative_schema_whitelist(): void
    {
        $path = dirname(__DIR__, 3) . '/src/etc/db_schema_whitelist.json';
        self::assertFileExists($path);

        $whitelist = json_decode((string) file_get_contents($path), true);

        self::assertArrayHasKey(self::RAW_TABLE, $whitelist);
        self::assertArrayHasKey(self::DAILY_TABLE, $whitelist);
    }

    public function test_it_lists_every_declared_column_in_the_whitelist(): void
    {
        $path = dirname(__DIR__, 3) . '/src/etc/db_schema_whitelist.json';
        $whitelist = json_decode((string) file_get_contents($path), true);

        foreach ([self::RAW_TABLE, self::DAILY_TABLE] as $tableName) {
            $declaredColumns = array_map(
                static fn (SimpleXMLElement $column): string => (string) $column['name'],
                $this->getTable($tableName)->xpath('column'),
            );

            foreach ($declaredColumns as $columnName) {
                self::assertArrayHasKey($columnName, $whitelist[$tableName]['column'], $tableName . '.' . $columnName);
                self::assertTrue($whitelist[$tableName]['column'][$columnName], $tableName . '.' . $columnName);
            }
        }
    }

    private function getTable(string $name): SimpleXMLElement
    {
        $table = $this->schema->xpath('//table[@name="' . $name . '"]')[0] ?? null;
        self::assertNotNull($table);

        return $table;
    }
}
