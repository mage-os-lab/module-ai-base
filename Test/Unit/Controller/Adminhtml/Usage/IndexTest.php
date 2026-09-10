<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Controller\Adminhtml\Usage;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use MageOS\AiBase\Controller\Adminhtml\Usage\Index;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Controller\Adminhtml\Usage\Index
 *
 * Requires Magento\Backend classes (Backend\App\Action inheritance chain); in the standalone
 * module checkout run PHPUnit with a bootstrap that autoloads the Magento\Backend module
 * sources, otherwise the tests skip.
 */
final class IndexTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Magento\Backend\App\Action::class)) {
            self::markTestSkipped('Magento\Backend is not available in this environment.');
        }
    }

    public function test_it_renders_the_usage_listing_page_for_an_admin_with_the_usage_permission(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with(Index::ADMIN_RESOURCE)->willReturn(true);

        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with('AI Token Usage');
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $page = $this->createMock(Page::class);
        $page->expects(self::once())->method('setActiveMenu')->with('MageOS_AiBase::usage');
        $page->method('getConfig')->willReturn($pageConfig);

        $controller = $this->createController($authorization, $page);

        self::assertTrue($this->isAllowed($controller));
        self::assertSame($page, $controller->execute());
    }

    public function test_it_titles_the_page_after_the_menu_item_it_was_reached_from(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with(Index::ADMIN_RESOURCE)->willReturn(true);

        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with('AI Token Usage');
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);

        $this->createController($authorization, $page)->execute();
    }

    public function test_it_refuses_access_to_an_admin_without_the_usage_permission(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with(Index::ADMIN_RESOURCE)->willReturn(false);

        $controller = $this->createController($authorization, $this->createMock(Page::class));

        self::assertFalse($this->isAllowed($controller));
    }

    private function createController(AuthorizationInterface $authorization, Page $page): Index
    {
        $context = $this->createMock(Context::class);
        $context->method('getAuthorization')->willReturn($authorization);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        return new Index($context, $pageFactory);
    }

    /**
     * Exercises the same protected hook Magento\Backend\App\AbstractAction::dispatch() calls
     * before an action ever runs, without reconstructing the whole dispatch() call chain.
     */
    private function isAllowed(Index $controller): bool
    {
        $method = new \ReflectionMethod($controller, '_isAllowed');

        return (bool) $method->invoke($controller);
    }
}
