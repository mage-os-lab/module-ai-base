<?php

declare(strict_types=1);

namespace MageOS\AiBase\Block\Adminhtml\Configuration;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\ModelList\Resolver;
use MageOS\AiBase\Model\ServiceRegistry;

class Services extends AbstractFieldArray
{
    /**
     * @var string
     */
    protected $_template = 'MageOS_AiBase::system/config/form/field/services.phtml';

    /**
     * @param Context $context
     * @param Json $jsonSerializer
     * @param ServiceRegistry $serviceRegistry Registered backends; it validates its own entries
     * @param Resolver $modelListResolver
     * @param BridgeRegistry $bridgeRegistry
     * @param array<string,mixed> $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private readonly Json $jsonSerializer,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly Resolver $modelListResolver,
        private readonly BridgeRegistry $bridgeRegistry,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null,
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * Whether the install is one where the reader of this page can act on a composer command.
     *
     * Nobody runs composer against a production install from the admin, so the instructions for
     * installing a bridge package, and the providers those instructions are about, are addressed
     * to a developer and shown only where a developer is the one looking.
     *
     * A mode Magento cannot report (a deployment config that predates the setting) is treated as
     * production, because the cost of being wrong is a page telling an administrator to run a
     * command they cannot run.
     *
     * Read off the block context rather than injected: every Template block already carries the
     * application state, and adding a constructor argument to a block breaks every install whose
     * generated interceptor was compiled against the old signature until it is recompiled.
     *
     * @return bool
     */
    public function isDeveloperMode(): bool
    {
        try {
            return $this->_appState->getMode() === State::MODE_DEVELOPER;
        } catch (LocalizedException) {
            return false;
        }
    }

    /**
     * Whether the stored value comes from deployment configuration instead of the database.
     *
     * `bin/magento app:config:dump` and `config:set --lock-env` write this path into
     * `app/etc/env.php`, and deployment configuration wins over the database. Magento then marks
     * the field disabled and `Magento\Config\Model\Config` skips it on save, without saying so
     * anywhere: the section still reports "You saved the configuration" and stores nothing. Being
     * registered `sensitive` does not prevent that, it only decides which file the dump writes to.
     *
     * This form builds every control itself, so nothing else on the page reflects that state. Read
     * off the element the config form hands this renderer rather than asked of `SettingChecker`
     * directly, so the form says read-only for the same reasons the save path refuses to write,
     * including any added later.
     *
     * @return bool
     */
    public function isReadOnly(): bool
    {
        $element = $this->getData('element');

        return $element instanceof DataObject && (bool) $element->getData('disabled');
    }

    /**
     * The providers offered as buttons on this install.
     *
     * In production the unusable ones are left out entirely rather than greyed out: their label
     * explains a package that the person reading cannot install, and the row it would add cannot
     * be tested from here. Developer mode keeps them, because there the label is actionable.
     *
     * Only the buttons are filtered. The field schema still carries every registered provider, so
     * a row saved for one of them keeps rendering and keeps its configuration.
     *
     * @return array<string,array{code:string,name:string,available:bool,supported:bool,package:string}>
     */
    public function getSelectableServices(): array
    {
        $buttons = $this->getServicesButtons();
        if ($this->isDeveloperMode()) {
            return $buttons;
        }

        return array_filter($buttons, static fn (array $button): bool => $button['available']);
    }

    /**
     * Whether any provider is being kept off this page because it cannot be used here.
     *
     * @return bool
     */
    public function hasHiddenServices(): bool
    {
        return count($this->getSelectableServices()) < count($this->getServicesButtons());
    }

    /**
     * Buttons rendered in the admin form, one per registered AI backend.
     *
     * @return array<string,array{code:string,name:string,available:bool,supported:bool,package:string}>
     */
    public function getServicesButtons(): array
    {
        return array_map(
            fn (AiServiceConfigurationInterface $service) => [
                'code' => $service->getCode(),
                'name' => $service->getName(),
                'available' => $this->bridgeRegistry->isAvailable($service->getCode()),
                'supported' => $this->bridgeRegistry->isSupported($service->getCode()),
                'package' => (string) $this->bridgeRegistry->getPackage($service->getCode()),
            ],
            $this->serviceRegistry->getAll(),
        );
    }

    /**
     * Composer packages needed to make currently unavailable providers usable, deduplicated.
     *
     * @return array<int, string>
     */
    public function getMissingBridgePackages(): array
    {
        $packages = [];
        foreach ($this->getServicesButtons() as $button) {
            if (!$button['available'] && $button['supported'] && $button['package'] !== '') {
                $packages[] = $button['package'];
            }
        }

        return array_values(array_unique($packages));
    }

    /**
     * Display names of providers for which no bridge has been released upstream.
     *
     * Distinct from providers whose package is merely missing: there is nothing to install for
     * these, so the form must not offer a composer command for them.
     *
     * @return array<int, string>
     */
    public function getUnsupportedServiceNames(): array
    {
        $names = [];
        foreach ($this->getServicesButtons() as $button) {
            if (!$button['supported']) {
                $names[] = $button['name'];
            }
        }

        return $names;
    }

    /**
     * Whether any registered provider cannot currently be used through the bundled client.
     *
     * @return bool
     */
    public function hasUnavailableServices(): bool
    {
        foreach ($this->getServicesButtons() as $button) {
            if (!$button['available']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Field schema consumed by the admin form JavaScript.
     *
     * Each service entry carries its field descriptors (`fields`) and whether the backend supports
     * the live model-list refresh (`supportsModelRefresh`). Model select options come from the
     * model-list resolver, so a previously refreshed list wins over the curated defaults.
     *
     * @return string JSON object keyed by service code:
     *         {name: string, fields: array[], supportsModelRefresh: bool}
     */
    public function getServicesSchemaJson(): string
    {
        $schema = [];
        foreach ($this->serviceRegistry->getAll() as $code => $service) {
            $models = $this->modelListResolver->getModels($service);
            $schema[$code] = [
                // The rows are built in JavaScript, so the display name has to travel with the
                // schema. Without it a row shows its fields and never says which provider they
                // belong to, which is the one thing two rows of the same backend differ by.
                'name' => $service->getName(),
                'fields' => array_map(
                    fn (FieldDescriptorInterface $field) => [
                        'name'      => $field->getName(),
                        'label'     => $field->getLabel(),
                        'type'      => $field->getType(),
                        'options'   => $this->resolveFieldOptions($field, $models),
                        'default'   => $field->getDefault(),
                        'encrypted' => $field->isEncrypted(),
                    ],
                    $service->getConfigurationFields(),
                ),
                'supportsModelRefresh' => $service instanceof ModelListProviderInterface,
            ];
        }
        // SerializerInterface still declares the string|bool return of the pre-exception days;
        // Json::serialize throws instead of returning false, so the cast only narrows the type.
        return (string) $this->jsonSerializer->serialize($schema);
    }

    /**
     * Options for a field, substituting the resolved model list for the model field.
     *
     * Applies to the model field whatever its type. A select renders these as its options;
     * a free-text field renders them as datalist suggestions, so providers whose model list
     * cannot be known ahead of time (self-hosted Ollama and LM Studio, or OpenRouter's very
     * large catalogue) still benefit from a refresh instead of silently discarding it.
     *
     * @param FieldDescriptorInterface $field
     * @param array<string,string> $models Resolved model list (stored or curated) as value => label
     * @return array<int,array{value:string,label:string}>
     */
    private function resolveFieldOptions(FieldDescriptorInterface $field, array $models): array
    {
        if ($field->getName() !== 'model') {
            return $field->getOptions();
        }

        $options = [];
        foreach ($models as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        return $options;
    }

    /**
     * @inheritdoc
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('service', [
            'label' => __('Service'),
            'class' => 'required-entry',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Service');
    }
}
