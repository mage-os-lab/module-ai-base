<?php

declare(strict_types=1);

namespace MageOS\AiBase\Controller\Adminhtml\Usage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the raw usage log listing (task 015).
 *
 * This is the module's first admin controller that renders a page rather than returning JSON:
 * every other controller under `Controller\Adminhtml` answers an ajax request with
 * {@see \Magento\Framework\Controller\Result\Json}. The layout handle `mageos_ai_usage_index`
 * places the `mageos_ai_usage_listing` UI component, so this class only has to resolve the page
 * and set the active menu item.
 *
 * Extends Backend\App\Action so admin authentication, form-key validation and ACL enforcement
 * (through {@see ADMIN_RESOURCE}) apply through the standard plugins, the same as every other
 * controller in this module.
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * Authorization resource guarding the listing, declared in `etc/acl.xml` (task 014).
     */
    public const ADMIN_RESOURCE = 'MageOS_AiBase::usage';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Renders the page and highlights the "AI Token Usage" menu item it was reached from.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        /**
         * PageFactory::create() is declared against the generic
         * \Magento\Framework\View\Result\Page, but PageFactory's own `instanceName` argument is
         * bound to \Magento\Backend\Model\View\Result\Page for the adminhtml area (see
         * Magento_Backend's `etc/adminhtml/di.xml`), which is what actually adds
         * {@see \Magento\Backend\Model\View\Result\Page::setActiveMenu()}.
         *
         * @var \Magento\Backend\Model\View\Result\Page $resultPage
         */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu(self::ADMIN_RESOURCE);
        $resultPage->getConfig()->getTitle()->prepend((string) __('AI Token Usage'));

        return $resultPage;
    }
}
