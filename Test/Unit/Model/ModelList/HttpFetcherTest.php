<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\ModelList;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\ModelList\HttpFetcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\ModelList\HttpFetcher
 */
final class HttpFetcherTest extends TestCase
{
    private ClientInterface&MockObject $client;
    private HttpFetcher $subject;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->method('create')->willReturn($this->client);

        $this->subject = new HttpFetcher($clientFactory, new Json());
    }

    public function test_get_json_returns_decoded_array_and_sends_headers(): void
    {
        $this->client->expects(self::once())->method('setHeaders')
            ->with(['Authorization' => 'Bearer sk-test']);
        $this->client->expects(self::once())->method('get')
            ->with('https://api.example.com/v1/models');
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getBody')->willReturn('{"data":[{"id":"m1"}]}');

        $result = $this->subject->getJson('https://api.example.com/v1/models', ['Authorization' => 'Bearer sk-test']);

        self::assertSame(['data' => [['id' => 'm1']]], $result);
    }

    public function test_get_json_skips_set_headers_when_no_headers_given(): void
    {
        $this->client->expects(self::never())->method('setHeaders');
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getBody')->willReturn('{"models":[]}');

        self::assertSame(['models' => []], $this->subject->getJson('http://localhost:11434/api/tags'));
    }

    public function test_get_json_throws_localized_exception_on_non_2xx_status(): void
    {
        $this->client->method('getStatus')->willReturn(401);
        $this->client->method('getBody')->willReturn('{"error":"unauthorized"}');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('returned HTTP status 401');

        $this->subject->getJson('https://api.example.com/v1/models');
    }

    public function test_get_json_throws_localized_exception_on_invalid_json(): void
    {
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getBody')->willReturn('<html>not json</html>');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not valid JSON');

        $this->subject->getJson('https://api.example.com/v1/models');
    }

    public function test_get_json_throws_localized_exception_on_scalar_json(): void
    {
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getBody')->willReturn('"just a string"');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not a JSON object');

        $this->subject->getJson('https://api.example.com/v1/models');
    }

    public function test_get_json_wraps_transport_errors_in_localized_exception(): void
    {
        $this->client->method('get')->willThrowException(new \Exception('cURL error 7: connection refused'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('cURL error 7: connection refused');

        $this->subject->getJson('http://localhost:11434/api/tags');
    }
}
