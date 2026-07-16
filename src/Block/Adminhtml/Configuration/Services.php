<?php

declare(strict_types=1);

namespace MageOS\AiBase\Block\Adminhtml\Configuration;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;

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
     * @param array $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private readonly Json $jsonSerializer,
        /** @var AiServiceConfigurationInterface[] */
        private readonly array $services,
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
     * @return string JSON object keyed by service code, each value is a list of field descriptors as arrays
     */
    public function getServicesSchemaJson(): string
    {
        $schema = [];
        foreach ($this->services as $service) {
            $schema[$service->getCode()] = array_map(
                fn (FieldDescriptorInterface $field) => [
                    'name'      => $field->getName(),
                    'label'     => $field->getLabel(),
                    'type'      => $field->getType(),
                    'options'   => $field->getOptions(),
                    'default'   => $field->getDefault(),
                    'encrypted' => $field->isEncrypted(),
                ],
                $service->getConfigurationFields(),
            );
        }
        return $this->jsonSerializer->serialize($schema);
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
