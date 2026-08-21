<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\ServiceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The registered providers and their bridges, as Magento actually assembles them.
 *
 * Everything here was previously asserted by reading `src/etc/di.xml` with XPath, which proves only
 * that the file says what it says. It stays true when the `<type>` name stops matching the class,
 * when another module overrides the argument, and when the module is not enabled at all — three
 * ways for the admin form to end up empty while the test suite stays green.
 */
final class RegisteredProvidersTest extends TestCase
{
    /**
     * The providers this module ships. Written out rather than derived, because "every provider
     * that is registered is registered" is not an assertion; a provider silently dropped from the
     * merge is exactly the failure worth catching.
     */
    private const BUNDLED_PROVIDERS = [
        'anthropic',
        'azure',
        'deepseek',
        'google',
        'huggingface',
        'lmstudio',
        'ollama',
        'openai',
        'openrouter',
    ];

    private ServiceRegistry $serviceRegistry;
    private BridgeRegistry $bridgeRegistry;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->serviceRegistry = $objectManager->get(ServiceRegistry::class);
        $this->bridgeRegistry = $objectManager->get(BridgeRegistry::class);
    }

    public function test_every_bundled_provider_reaches_the_registry(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(self::BUNDLED_PROVIDERS, array_keys($this->serviceRegistry->getAll()))),
            'These providers ship with the module but do not come out of the registry, '
            . 'so the admin form does not offer them.'
        );
    }

    /**
     * The registry keys by the `di.xml` item name while every consumer looks providers up by the
     * code the class reports. A row saved under one and read back under the other is a service the
     * selector cannot resolve.
     */
    public function test_each_registry_key_is_the_code_its_provider_reports(): void
    {
        $mismatched = [];
        foreach ($this->serviceRegistry->getAll() as $key => $service) {
            if ($key !== $service->getCode()) {
                $mismatched[$key] = $service->getCode();
            }
        }

        self::assertSame([], $mismatched, 'Registry key => code the class reports.');
    }

    /**
     * Every registered provider has to be constructible with its real dependencies and describe
     * itself, since the admin form renders all of this for every provider on every page load.
     */
    public function test_every_registered_provider_describes_itself(): void
    {
        foreach ($this->serviceRegistry->getAll() as $code => $service) {
            self::assertInstanceOf(AiServiceConfigurationInterface::class, $service);
            self::assertNotSame('', $service->getName(), "$code has no display name");

            $fields = $service->getConfigurationFields();
            self::assertNotSame([], $fields, "$code offers no configuration fields");
            foreach ($fields as $field) {
                self::assertInstanceOf(FieldDescriptorInterface::class, $field);
                self::assertNotSame('', $field->getName(), "$code has an unnamed field");
            }
        }
    }

    /**
     * A bundled provider with no bridge is offered in the admin form and then cannot be used
     * through the built-in client, with nothing to install to fix it. Third parties may still
     * register one; the form marks those unsupported instead of offering a composer command.
     */
    public function test_every_bundled_provider_has_a_bridge(): void
    {
        $unsupported = array_values(array_filter(
            array_keys($this->serviceRegistry->getAll()),
            fn (string $code): bool => !$this->bridgeRegistry->isSupported($code),
        ));

        self::assertSame([], $unsupported);
    }

    /**
     * The admin form tells an administrator which package makes a provider usable, and the client
     * factory repeats it in its error. A bridge with no package name leaves both saying nothing.
     */
    public function test_every_bridge_names_the_package_that_installs_it(): void
    {
        $withoutPackage = [];
        foreach (array_keys($this->serviceRegistry->getAll()) as $code) {
            if (($this->bridgeRegistry->getPackage($code) ?? '') === '') {
                $withoutPackage[] = $code;
            }
        }

        self::assertSame([], $withoutPackage);
    }

    /**
     * A bridge without a dialect has its caller's options passed through untouched, which is the
     * silent mis-cap `OptionNormalizer` exists to prevent. Behaviour per dialect is covered in
     * Test\Integration\Model\Client\OptionNormalizerTest; this only asserts that one is declared.
     */
    public function test_every_bridge_declares_a_request_option_dialect(): void
    {
        $withoutDialect = [];
        foreach (array_keys($this->serviceRegistry->getAll()) as $code) {
            if (($this->bridgeRegistry->getDialect($code) ?? '') === '') {
                $withoutDialect[] = $code;
            }
        }

        self::assertSame([], $withoutDialect);
    }

    /**
     * Every package the admin form can tell someone to install has to be listed in composer, so
     * the metadata and the form cannot drift apart. A bridge in `require` is always installed and
     * cannot drift; every other bridge must sit in `suggest`. The file is the subject here, which
     * is why this one reads it: composer metadata has no runtime representation to ask instead.
     */
    public function test_every_bridge_package_is_listed_in_composer(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $suggested = array_keys($composer['suggest'] ?? []);
        $required = array_keys($composer['require'] ?? []);

        $packages = array_values(array_unique(array_filter(array_map(
            fn (string $code): ?string => $this->bridgeRegistry->getPackage($code),
            array_keys($this->serviceRegistry->getAll()),
        ))));

        self::assertSame([], array_values(array_diff($packages, $suggested, $required)));

        self::assertNotContains(
            'symfony/ai-platform',
            $suggested,
            'The platform package ships no bridges since 0.12; suggesting it alone sends people to a dead end.'
        );
    }
}
