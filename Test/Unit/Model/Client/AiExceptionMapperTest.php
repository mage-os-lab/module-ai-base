<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use MageOS\AiBase\Model\Client\AiAuthenticationException;
use MageOS\AiBase\Model\Client\AiContentFilteredException;
use MageOS\AiBase\Model\Client\AiExceptionMapper;
use MageOS\AiBase\Model\Client\AiInvalidRequestException;
use MageOS\AiBase\Model\Client\AiRateLimitedException;
use MageOS\AiBase\Model\Client\AiServiceException;
use MageOS\AiBase\Model\Client\AiToolCallException;
use MageOS\AiBase\Model\Client\AiTransientException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MalformedToolCallException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * symfony/ai-platform is a soft dependency of this module, so these run only where it is
 * installed. Skipping beats failing: an install without the bridges is a supported setup.
 *
 * @covers \MageOS\AiBase\Model\Client\AiExceptionMapper
 */
final class AiExceptionMapperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AuthenticationException::class)) {
            self::markTestSkipped('symfony/ai-platform is not installed.');
        }
    }

    /**
     * @param \Closure(): \Throwable $buildOriginal
     * @param class-string<AiServiceException> $expectedClass
     */
    #[DataProvider('mappedExceptionsProvider')]
    public function test_it_maps_a_symfony_ai_exception_to_its_typed_equivalent(
        \Closure $buildOriginal,
        string $expectedClass,
    ): void {
        $original = $buildOriginal();

        $mapped = (new AiExceptionMapper())->map($original, 'openai');

        self::assertInstanceOf($expectedClass, $mapped);
        self::assertSame($original, $mapped->getPrevious());
        self::assertStringContainsString('openai', $mapped->getMessage());
        self::assertStringContainsString('provider said no', $mapped->getMessage());
    }

    /**
     * @return array<string, array{0: \Closure(): \Throwable, 1: class-string<AiServiceException>}>
     */
    public static function mappedExceptionsProvider(): array
    {
        return [
            'authentication' => [
                static fn () => new AuthenticationException('provider said no'),
                AiAuthenticationException::class,
            ],
            'server error' => [
                static fn () => new ServerException(500, 'provider said no'),
                AiTransientException::class,
            ],
            'incomplete stream' => [
                static fn () => new IncompleteStreamException('provider said no'),
                AiTransientException::class,
            ],
            'connection failure' => [
                static fn () => new TransportException('provider said no'),
                AiTransientException::class,
            ],
            'idle timeout' => [
                static fn () => new TimeoutException('provider said no'),
                AiTransientException::class,
            ],
            'bad request' => [
                static fn () => new BadRequestException('provider said no'),
                AiInvalidRequestException::class,
            ],
            'context size exceeded' => [
                static fn () => new ExceedContextSizeException('provider said no'),
                AiInvalidRequestException::class,
            ],
            'model not found' => [
                static fn () => new ModelNotFoundException('provider said no'),
                AiInvalidRequestException::class,
            ],
            'content filter' => [
                static fn () => new ContentFilterException('provider said no'),
                AiContentFilteredException::class,
            ],
            'malformed tool call' => [
                static fn () => new MalformedToolCallException('provider said no'),
                AiToolCallException::class,
            ],
        ];
    }

    public function test_it_maps_rate_limiting_and_keeps_the_retry_after(): void
    {
        $original = new RateLimitExceededException(30, 'too many requests');

        $mapped = (new AiExceptionMapper())->map($original, 'openai');

        self::assertInstanceOf(AiRateLimitedException::class, $mapped);
        self::assertSame(30, $mapped->getRetryAfter());
        self::assertSame($original, $mapped->getPrevious());
    }

    public function test_it_reports_no_retry_after_when_the_provider_did_not_say(): void
    {
        $mapped = (new AiExceptionMapper())->map(new RateLimitExceededException(), 'azure');

        self::assertInstanceOf(AiRateLimitedException::class, $mapped);
        self::assertNull($mapped->getRetryAfter());
    }

    public function test_it_falls_back_to_the_base_type_for_an_unrecognized_failure(): void
    {
        $original = new \RuntimeException('connection reset');

        $mapped = (new AiExceptionMapper())->map($original, 'ollama');

        self::assertInstanceOf(AiServiceException::class, $mapped);
        self::assertSame($original, $mapped->getPrevious());
    }

    public function test_it_names_the_service_in_the_message(): void
    {
        $mapped = (new AiExceptionMapper())->map(new AuthenticationException('bad key'), 'anthropic');

        self::assertStringContainsString('anthropic', $mapped->getMessage());
    }
}
