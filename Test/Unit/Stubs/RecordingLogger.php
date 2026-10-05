<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Psr\Log\AbstractLogger;

/**
 * A PSR logger that keeps every record in memory, so a test can read back what was logged.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private array $records = [];

    /**
     * @inheritdoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * Every logged message joined, for asserting that a value did or did not reach the log.
     *
     * @return string
     */
    public function getMessages(): string
    {
        return implode("\n", array_column($this->records, 'message'));
    }
}
