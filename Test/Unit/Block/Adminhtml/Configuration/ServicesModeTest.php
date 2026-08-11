<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Block\Adminhtml\Configuration\Services;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\ModelList\Resolver;
use MageOS\AiBase\Model\ServiceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the form offers depends on who is looking at it.
 *
 * A provider whose bridge package is missing is useless to an administrator on a production
 * install: they cannot install it, cannot test it, and the button's own label explains a composer
 * command they will never run. In developer mode the same button is actionable, so it stays.
 */
final class ServicesModeTest extends TestCase
{
    private Resolver&MockObject $modelListResolver;

    protected function setUp(): void
    {
        if (!class_exists(AbstractFieldArray::class)) {
            self::markTestSkipped('magento/module-config is not installed in this environment.');
        }

        $this->modelListResolver = $this->createMock(Resolver::class);
        $this->modelListResolver->method('getModels')->willReturn([]);
    }

    public function test_developer_mode_offers_every_registered_provider(): void
    {
        $block = $this->blockInMode(State::MODE_DEVELOPER);

        self::assertTrue($block->isDeveloperMode());
        self::assertSame(['installed', 'missing'], array_keys($block->getSelectableServices()));
        self::assertFalse($block->hasHiddenServices());
    }

    public function test_production_offers_only_the_providers_that_can_be_used(): void
    {
        $block = $this->blockInMode(State::MODE_PRODUCTION);

        self::assertFalse($block->isDeveloperMode());
        self::assertSame(['installed'], array_keys($block->getSelectableServices()));
        self::assertTrue($block->hasHiddenServices());
    }

    /**
     * Default mode is not developer mode, and the composer command is no more runnable there from
     * the admin than it is in production.
     *
     * @param string $mode
     */
    #[DataProvider('nonDeveloperModeProvider')]
    public function test_only_developer_mode_counts_as_developer_mode(string $mode): void
    {
        self::assertFalse($this->blockInMode($mode)->isDeveloperMode());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonDeveloperModeProvider(): array
    {
        return [
            'production' => [State::MODE_PRODUCTION],
            'default' => [State::MODE_DEFAULT],
        ];
    }

    /**
     * An install whose deployment config cannot report a mode is treated as production, because
     * the cost of guessing wrong is a page telling an administrator to run composer.
     */
    public function test_an_unreportable_mode_is_treated_as_production(): void
    {
        $appState = $this->createMock(State::class);
        $appState->method('getMode')->willThrowException(new LocalizedException(__('No mode.')));

        self::assertFalse($this->blockWithState($appState)->isDeveloperMode());
    }

    /**
     * The buttons are filtered, never the schema. A row saved for a provider whose package was
     * later removed still has to render with its fields, and still has to keep its credentials
     * through the next save; a row whose service code is missing from the schema renders nothing.
     */
    public function test_a_hidden_provider_keeps_its_place_in_the_field_schema(): void
    {
        $schema = json_decode($this->blockInMode(State::MODE_PRODUCTION)->getServicesSchemaJson(), true);

        self::assertArrayHasKey('missing', $schema, 'A saved row of this provider would render empty.');
        self::assertNotSame([], $schema['missing']['fields']);
    }

    /**
     * @param string $mode
     * @return Services
     */
    private function blockInMode(string $mode): Services
    {
        $appState = $this->createMock(State::class);
        $appState->method('getMode')->willReturn($mode);

        return $this->blockWithState($appState);
    }

    /**
     * One provider whose bridge class exists and one whose bridge class does not.
     *
     * @param State $appState
     * @return Services
     */
    private function blockWithState(State $appState): Services
    {
        $reflection = new \ReflectionClass(Services::class);
        $block = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('jsonSerializer')->setValue($block, new Json());
        $reflection->getProperty('serviceRegistry')->setValue($block, new ServiceRegistry([
            $this->serviceFor('installed'),
            $this->serviceFor('missing'),
        ]));
        $reflection->getProperty('modelListResolver')->setValue($block, $this->modelListResolver);
        $reflection->getProperty('bridgeRegistry')->setValue($block, new BridgeRegistry([
            'installed' => ['factory' => InstalledModeBridgeFactory::class, 'package' => 'symfony/ai-installed-platform'],
            'missing' => ['factory' => 'Absent\\Bridge\\Factory', 'package' => 'symfony/ai-missing-platform'],
        ]));
        $reflection->getProperty('_appState')->setValue($block, $appState);

        return $block;
    }

    /**
     * @param string $code
     * @return AiServiceConfigurationInterface
     */
    private function serviceFor(string $code): AiServiceConfigurationInterface
    {
        $field = $this->createMock(FieldDescriptorInterface::class);
        $field->method('getName')->willReturn('api_key');
        $field->method('getLabel')->willReturn('API Key');
        $field->method('getType')->willReturn(FieldDescriptorInterface::TYPE_PASSWORD);
        $field->method('getOptions')->willReturn([]);
        $field->method('isEncrypted')->willReturn(true);

        $service = $this->createMock(AiServiceConfigurationInterface::class);
        $service->method('getCode')->willReturn($code);
        $service->method('getName')->willReturn(ucfirst($code));
        $service->method('getConfigurationFields')->willReturn([$field]);

        return $service;
    }
}

/**
 * Stand-in for a bridge package that is installed: BridgeRegistry calls a provider available when
 * its factory class exists and can build a platform.
 */
final class InstalledModeBridgeFactory
{
    public static function createPlatform(string $apiKey): object
    {
        return new \stdClass();
    }
}
