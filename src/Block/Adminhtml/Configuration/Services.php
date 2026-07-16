<?php

declare(strict_types=1);

namespace MageOS\AiBase\Block\Adminhtml\Configuration;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\Resolver;

class Services extends AbstractFieldArray
{
    /**
     * @var string
     */
    protected $_template = 'MageOS_AiBase::system/config/form/field/services.phtml';

    /**
     * @param Context $context
     * @param Json $jsonSerializer
     * @param AiServiceConfigurationInterface[] $services
     * @param Resolver $modelListResolver
     * @param array $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private readonly Json $jsonSerializer,
        /** @var AiServiceConfigurationInterface[] */
        private readonly array $services,
        private readonly Resolver $modelListResolver,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null,
    ) {
        parent::__construct($context, $data, $secureRenderer);

        foreach ($this->services as $service) {
            if (!$service instanceof AiServiceConfigurationInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Each registered service must implement %s, got %s',
                    AiServiceConfigurationInterface::class,
                    get_debug_type($service),
                ));
            }
        }
    }

    /**
     * Buttons rendered in the admin form, one per registered AI backend.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getServicesButtons(): array
    {
        return array_map(
            fn (AiServiceConfigurationInterface $service) => [
                'code' => $service->getCode(),
                'name' => $service->getName(),
            ],
            $this->services,
        );
    }

    /**
     * Field schema consumed by the admin form JavaScript.
     *
     * Each service entry carries its field descriptors (`fields`) and whether the backend supports
     * the live model-list refresh (`supportsModelRefresh`). Model select options come from the
     * model-list resolver, so a previously refreshed list wins over the curated defaults.
     *
     * @return string JSON object keyed by service code:
     *         {fields: array[], supportsModelRefresh: bool}
     */
    public function getServicesSchemaJson(): string
    {
        $schema = [];
        foreach ($this->services as $service) {
            $models = $this->modelListResolver->getModels($service);
            $schema[$service->getCode()] = [
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
        return $this->jsonSerializer->serialize($schema);
    }

    /**
     * Options for a field, substituting the resolved model list for the model select.
     *
     * @param FieldDescriptorInterface $field
     * @param array $models Resolved model list (stored or curated) as value => label
     * @return array<int, array{value: string, label: string}>
     */
    private function resolveFieldOptions(FieldDescriptorInterface $field, array $models): array
    {
        if ($field->getName() !== 'model' || $field->getType() !== FieldDescriptorInterface::TYPE_SELECT) {
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
        $this->_addButtonLabel = __('Add Service');
    }
}
