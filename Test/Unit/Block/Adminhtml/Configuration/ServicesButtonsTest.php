<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Block\Adminhtml\Configuration\Services;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\ModelList\Resolver;
use MageOS\AiBase\Model\ServiceRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The "Add Service" buttons tell an administrator whether a provider can be used through the
 * bundled client, and what to install when it cannot.
 *
 * Three states have to stay distinguishable, because the form says something different for each:
 * available; unavailable but installable; and unsupported, meaning no bridge exists upstream at
 * all so there is nothing to install.
 */
final class ServicesButtonsTest extends TestCase
{
    private Resolver&MockObject $modelListResolver;
    private BridgeRegistry $bridgeRegistry;

    protected function setUp(): void
    {
        if (!class_exists(AbstractFieldArray::class)) {
            self::markTestSkipped('magento/module-config is not installed in this environment.');
        }

        $this->modelListResolver = $this->createMock(Resolver::class);
        $this->bridgeRegistry = new BridgeRegistry([]);
    }

    public function test_a_provider_whose_bridge_is_installed_is_available(): void
    {
        $this->bridgeRegistry = new BridgeRegistry(['openai' => [
            'factory' => InstalledBridgeFactory::class,
            'package' => 'symfony/ai-open-ai-platform',
        ]]);

        $button = $this->buttonFor('openai');

        self::assertTrue($button['available']);
        self::assertTrue($button['supported']);
        self::assertSame('symfony/ai-open-ai-platform', $button['package']);
    }

    public function test_a_provider_whose_package_is_missing_is_unavailable_but_installable(): void
    {
        $this->bridgeRegistry = new BridgeRegistry(['ollama' => [
            'factory' => 'Symfony\\AI\\Platform\\Bridge\\Ollama\\Factory',
            'package' => 'symfony/ai-ollama-platform',
        ]]);

        $button = $this->buttonFor('ollama');

        self::assertFalse($button['available'], 'The bridge class is not installed in this environment.');
        self::assertTrue($button['supported'], 'A package exists, so the admin can be told to install it.');
        self::assertSame(
            'symfony/ai-ollama-platform',
            $button['package'],
            'Without the package name the form cannot produce a usable composer require command.'
        );
    }

    public function test_a_provider_with_no_released_bridge_is_unsupported(): void
    {
        $button = $this->buttonFor('acmeai');

        self::assertFalse($button['available']);
        self::assertFalse($button['supported'], 'A third-party provider with no bridge has nothing to install.');
        self::assertSame('', $button['package']);
    }

    public function test_missing_packages_are_listed_once_each_for_the_install_command(): void
    {
        $this->bridgeRegistry = new BridgeRegistry([
            'ollama' => ['factory' => 'Absent\\Ollama', 'package' => 'symfony/ai-ollama-platform'],
            'google' => ['factory' => 'Absent\\Gemini', 'package' => 'symfony/ai-gemini-platform'],
            'openai' => ['factory' => InstalledBridgeFactory::class, 'package' => 'symfony/ai-open-ai-platform'],
        ]);

        $packages = $this->blockWithAll(['ollama', 'google', 'openai'])->getMissingBridgePackages();

        self::assertSame(
            ['symfony/ai-ollama-platform', 'symfony/ai-gemini-platform'],
            $packages,
            'Only providers that are both unavailable and installable belong in the composer command.'
        );
    }

    /**
     * Providers with no bridge upstream must never reach the install command, or the admin is
     * told to require a package that does not exist.
     */
    public function test_an_unsupported_provider_is_never_offered_as_installable(): void
    {
        $block = $this->blockWithAll(['acmeai']);

        self::assertSame([], $block->getMissingBridgePackages());
        self::assertSame(['Acmeai'], $block->getUnsupportedServiceNames());
        self::assertTrue($block->hasUnavailableServices());
    }

    public function test_no_note_is_shown_when_every_provider_is_available(): void
    {
        $this->bridgeRegistry = new BridgeRegistry([
            'openai' => ['factory' => InstalledBridgeFactory::class, 'package' => 'symfony/ai-open-ai-platform'],
        ]);

        $block = $this->blockWithAll(['openai']);

        self::assertFalse($block->hasUnavailableServices());
        self::assertSame([], $block->getMissingBridgePackages());
        self::assertSame([], $block->getUnsupportedServiceNames());
    }

    /**
     * @param array<int, string> $codes
     */
    private function blockWithAll(array $codes): Services
    {
        $reflection = new \ReflectionClass(Services::class);
        $block = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('jsonSerializer')->setValue($block, new Json());
        $reflection->getProperty('serviceRegistry')->setValue($block, new ServiceRegistry(array_map(
            fn (string $code): AiServiceConfigurationInterface => $this->serviceFor($code),
            $codes
        )));
        $reflection->getProperty('modelListResolver')->setValue($block, $this->modelListResolver);
        $reflection->getProperty('bridgeRegistry')->setValue($block, $this->bridgeRegistry);

        return $block;
    }

    /**
     * The one button for a provider, looked up the way the form does it.
     *
     * getServicesButtons() is keyed by service code, so indexing by position would pass only
     * because a single-provider fixture happens to have one entry.
     *
     * @return array{code: string, name: string, available: bool, supported: bool, package: string}
     */
    private function buttonFor(string $code): array
    {
        return $this->blockWith($this->serviceFor($code))->getServicesButtons()[$code];
    }

    private function serviceFor(string $code): AiServiceConfigurationInterface
    {
        return new class ($code) implements AiServiceConfigurationInterface {
            public function __construct(private readonly string $code)
            {
            }

            /**
             * @inheritdoc
             */
            public function getCode(): string
            {
                return $this->code;
            }

            /**
             * @inheritdoc
             */
            public function getName(): string
            {
                return ucfirst($this->code);
            }

            /**
             * @inheritdoc
             */
            public function getConfigurationFields(): array
            {
                return [];
            }

            /**
             * @inheritdoc
             */
            public function getSupportedModels(): array
            {
                return [];
            }
        };
    }

    /**
     * Build the block without running its constructor.
     *
     * `Magento\Backend\Block\Template::__construct()` resolves its `jsonHelper` through
     * `ObjectManager::getInstance()`, two levels above this class, so no constructor argument
     * avoids it and the real ObjectManager is not available in a unit test.
     * `getServicesButtons()` depends only on the properties set here.
     */
    private function blockWith(AiServiceConfigurationInterface $service): Services
    {
        $reflection = new \ReflectionClass(Services::class);
        $block = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('jsonSerializer')->setValue($block, new Json());
        $reflection->getProperty('serviceRegistry')->setValue($block, new ServiceRegistry([$service]));
        $reflection->getProperty('modelListResolver')->setValue($block, $this->modelListResolver);
        $reflection->getProperty('bridgeRegistry')->setValue($block, $this->bridgeRegistry);

        return $block;
    }
}

/**
 * A bridge factory that exists and exposes createPlatform(), i.e. an installed bridge.
 *
 * Declared here rather than reused from another test file: `autoload-dev` is not applied to a
 * package consumed as a dependency, so a class is only resolvable if PHPUnit loaded the file
 * that declares it, which makes cross-test-file references order dependent.
 */
final class InstalledBridgeFactory
{
    /**
     * @param string $apiKey
     * @return object
     */
    public static function createPlatform(string $apiKey): object
    {
        return new \stdClass();
    }
}
