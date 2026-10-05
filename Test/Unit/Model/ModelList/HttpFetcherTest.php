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

    /**
     * An administrator reading "HTTP status 401" has to know that a model listing is authenticated
     * with the same key as everything else, and that this is how the provider spells a rejected
     * one. The status stays in the message, because that is what a bug report needs.
     */
    public function test_get_json_says_a_rejected_key_is_a_rejected_key(): void
    {
        $this->client->method('getStatus')->willReturn(401);
        $this->client->method('getBody')->willReturn('{"error":"unauthorized"}');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The API key was rejected by api.example.com (HTTP 401).');

        $this->subject->getJson('https://api.example.com/v1/models');
    }

    public function test_get_json_points_a_404_at_the_base_url_that_produced_it(): void
    {
        $this->client->method('getStatus')->willReturn(404);
        $this->client->method('getBody')->willReturn('');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'api.example.com has no model list at its base URL (HTTP 404). Check the base URL saved for this service.'
        );

        $this->subject->getJson('https://api.example.com/v1/models');
    }

    /**
     * A self-hosted base URL can carry a token in its query string or credentials in front of the
     * host, and every message here ends up in the admin page. Only the host is named.
     */
    public function test_get_json_names_the_host_and_never_the_rest_of_the_url(): void
    {
        $this->client->method('getStatus')->willReturn(503);
        $this->client->method('getBody')->willReturn('');

        try {
            $this->subject->getJson('https://user:secret@gw.example:8443/v1/models?token=super-secret-value');
            self::fail('A 503 must throw.');
        } catch (LocalizedException $e) {
            self::assertSame('Request to gw.example:8443 returned HTTP status 503.', $e->getMessage());
        }

        try {
            $this->subject->getJson('gw.example/v1/models?token=super-secret-value');
            self::fail('A 503 must throw.');
        } catch (LocalizedException $e) {
            self::assertSame('Request to gw.example returned HTTP status 503.', $e->getMessage());
        }
    }

    /**
     * A status this module has nothing specific to say about still has to report itself.
     */
    public function test_get_json_reports_an_unrecognised_status_as_it_is(): void
    {
        $this->client->method('getStatus')->willReturn(503);
        $this->client->method('getBody')->willReturn('');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('returned HTTP status 503');

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

    /**
     * The HTTP client's text routinely includes the request URL, so it travels as the cause, where
     * a log record still carries it, and not in the message the page shows.
     */
    public function test_get_json_keeps_the_transport_error_on_the_cause_not_in_the_message(): void
    {
        $cause = new \Exception('cURL error 7: connection refused for http://localhost:11434/api/tags?token=x');
        $this->client->method('get')->willThrowException($cause);

        try {
            $this->subject->getJson('http://localhost:11434/api/tags?token=x');
            self::fail('A transport failure must throw.');
        } catch (LocalizedException $e) {
            self::assertSame('Request to localhost:11434 failed.', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }
}
