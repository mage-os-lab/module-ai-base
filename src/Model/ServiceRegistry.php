<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

/**
 * The AI backends registered with this module, keyed by service code.
 *
 * One list, one place in `di.xml`. The admin form, the option source consumer modules point their
 * own `system.xml` at, the sensitive-data processor and the model-list refresh controller all need
 * the same set: a provider present in one and missing from another does not fail loudly, it
 * degrades. An unregistered provider shows up as a raw machine code in the admin dropdown, or has
 * its stored credentials fall back to a field-name heuristic instead of its declared field schema.
 * Registering a provider is therefore a single `di.xml` entry here, and nothing needs keeping in
 * sync afterwards.
 *
 * Keyed by AiServiceConfigurationInterface::getCode() rather than by the `di.xml` item name, so a
 * lookup cannot miss a provider whose item name and code drifted apart. Registration order is
 * preserved, because it is the order the admin form offers the backends in.
 */
class ServiceRegistry
{
    /**
     * Registered backends, keyed by service code.
     *
     * @var array<string, AiServiceConfigurationInterface>
     */
    private readonly array $services;

    /**
     * Typed as a plain array because the entries arrive from `di.xml`, where nothing enforces what
     * a third party puts in the list. Validating here is what makes every reader's array typed.
     *
     * @param array<mixed> $services Registered AI backends
     * @throws \InvalidArgumentException When an entry is not a backend definition
     */
    public function __construct(array $services = [])
    {
        $registered = [];
        foreach ($services as $service) {
            if (!$service instanceof AiServiceConfigurationInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Each registered service must implement %s, got %s',
                    AiServiceConfigurationInterface::class,
                    get_debug_type($service),
                ));
            }
            $registered[$service->getCode()] = $service;
        }

        $this->services = $registered;
    }

    /**
     * Every registered backend, keyed by service code, in registration order.
     *
     * @return array<string, AiServiceConfigurationInterface>
     */
    public function getAll(): array
    {
        return $this->services;
    }

    /**
     * The backend registered for a service code, or null when none is.
     *
     * Null rather than an exception: a stored configuration row outlives the module that
     * registered its provider, and every caller here has something sensible to do without one.
     *
     * @param string $code
     * @return AiServiceConfigurationInterface|null
     */
    public function get(string $code): ?AiServiceConfigurationInterface
    {
        return $this->services[$code] ?? null;
    }

    /**
     * Whether a backend is registered for a service code.
     *
     * @param string $code
     * @return bool
     */
    public function has(string $code): bool
    {
        return isset($this->services[$code]);
    }
}
