<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

use MageOS\AiBase\AiServices\Azure;
use MageOS\AiBase\AiServices\Deepseek;
use MageOS\AiBase\AiServices\Opper;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use PHPUnit\Framework\TestCase;

/**
 * What a provider hands its bridge factory, now that the provider rather than the client factory
 * knows the factory's signature. Ollama, LM Studio and OpenAI-Compatible are covered end to end in
 * ClientFactoryTest; these are the two shapes it has no bridge stub for.
 */
final class PlatformArgumentsTest extends TestCase
{
    /**
     * Most hosted providers' factories take the API key first, so that is what the base class gives.
     */
    public function test_a_hosted_provider_passes_its_api_key_alone(): void
    {
        $provider = new Deepseek($this->createMock(FieldDescriptorInterfaceFactory::class));

        self::assertSame(['sk-1'], $provider->getPlatformArguments(['api_key' => 'sk-1', 'model' => 'deepseek-chat']));
    }

    /**
     * A non-string where a credential belongs reaches the bridge as an empty key rather than as
     * "Array", which would fail as an authentication error naming neither row nor field.
     */
    public function test_a_hosted_provider_passes_an_empty_key_for_a_malformed_one(): void
    {
        $provider = new Deepseek($this->createMock(FieldDescriptorInterfaceFactory::class));

        self::assertSame([''], $provider->getPlatformArguments(['api_key' => ['oops']]));
    }

    /**
     * Opper keeps the positional-only contract and never casts malformed credentials to strings.
     */
    public function test_opper_uses_a_fixed_host_even_with_malformed_configuration(): void
    {
        $provider = new Opper(
            $this->createMock(FieldDescriptorInterfaceFactory::class),
            $this->createMock(JsonFetcherInterface::class),
        );

        self::assertSame([
            'https://api.opper.ai',
            '',
        ], $provider->getPlatformArguments(['api_key' => ['oops'], 'base_url' => 'https://other.example']));
    }

    public function test_azure_passes_endpoint_deployment_api_version_and_key_in_that_order(): void
    {
        $provider = new Azure($this->createMock(FieldDescriptorInterfaceFactory::class));

        self::assertSame(
            ['https://acme.openai.azure.com', 'gpt-4o-prod', '2025-01-01', 'key-1'],
            $provider->getPlatformArguments([
                'endpoint' => 'https://acme.openai.azure.com',
                'deployment' => 'gpt-4o-prod',
                'api_version' => '2025-01-01',
                'api_key' => 'key-1',
                'model' => 'gpt-4o',
            ])
        );
    }

    /**
     * Most deployments are named after their model, so a row without a separate deployment name
     * routes to the model name, on the default API version.
     */
    public function test_azure_falls_back_to_the_model_as_deployment_and_the_default_api_version(): void
    {
        $provider = new Azure($this->createMock(FieldDescriptorInterfaceFactory::class));

        self::assertSame(
            ['https://acme.openai.azure.com', 'gpt-4o', '2024-10-21', 'key-1'],
            $provider->getPlatformArguments([
                'endpoint' => 'https://acme.openai.azure.com',
                'api_key' => 'key-1',
                'model' => 'gpt-4o',
            ])
        );
    }
}
