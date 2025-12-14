<?php

namespace MageOS\AiBase\Block\Adminhtml\Configuration;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

class Services extends AbstractFieldArray
{
    protected $_template = 'MageOS_AiBase::system/config/form/field/services.phtml';

    public function __construct(
        Context $context,
        /** @var AiServiceConfigurationInterface[] $services */
        private readonly array $services,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null,
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    public function getServicesButtons(): array
    {
        return array_map(fn (AiServiceConfigurationInterface $service) => [
            'code' => $service->getCode(),
            'name' => $service->getName(),
        ], $this->services);
    }

    public function getServicesTemplates(): array
    {
        return array_map(fn (AiServiceConfigurationInterface $service) => [
            'code' => $service->getCode(),
            'template' => $service->getConfigurationTemplate(),
        ], $this->services);
    }

    protected function _prepareToRender(): void
    {
        $this->addColumn(
            'service',
            [
                'label' => __('Service'),
                'class' => 'required-entry'
            ]
        );

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Service');
    }
}
