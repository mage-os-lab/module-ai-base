<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use MageOS\AiBase\Block\Adminhtml\Configuration\UsageReportLink;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Block\Adminhtml\Configuration\UsageReportLink
 *
 * Built through reflection like the other blocks in this directory, since
 * `Magento\Backend\Block\Template::__construct()` resolves collaborators through the ObjectManager.
 * The URL builder and the authorization check are the only two collaborators the block reads,
 * and both are fakes here per this codebase's convention.
 */
final class UsageReportLinkTest extends TestCase
{
    public function test_it_links_to_the_usage_report_route(): void
    {
        $urlBuilder = new FakeUrlBuilder();
        $block = $this->block($urlBuilder, new FakeAuthorization(true));

        $html = $block->getReportLinkHtml();

        self::assertSame([UsageReportLink::REPORT_ROUTE], $urlBuilder->getRequestedRoutes());
        self::assertStringContainsString('href="' . $urlBuilder->urlFor(UsageReportLink::REPORT_ROUTE) . '"', $html);
        self::assertStringContainsString('Open the AI Token Usage report', $html);
    }

    public function test_it_escapes_the_url_it_was_handed(): void
    {
        // An admin URL carries a secret key and whatever query string the builder adds; the block
        // must not trust it to be attribute-safe.
        $urlBuilder = new FakeUrlBuilder('https://store.test/admin/?key="><script>');
        $block = $this->block($urlBuilder, new FakeAuthorization(true));

        $html = $block->getReportLinkHtml();

        self::assertStringNotContainsString('"><script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function test_it_withholds_the_link_from_a_role_the_report_would_refuse(): void
    {
        $urlBuilder = new FakeUrlBuilder();
        $authorization = new FakeAuthorization(false);
        $block = $this->block($urlBuilder, $authorization);

        $html = $block->getReportLinkHtml();

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('under Reports', $html);
        self::assertSame([UsageReportLink::REPORT_ACL_RESOURCE], $authorization->getCheckedResources());
        self::assertSame([], $urlBuilder->getRequestedRoutes(), 'No URL is built for a link that is not shown.');
    }

    private function block(FakeUrlBuilder $urlBuilder, FakeAuthorization $authorization): UsageReportLink
    {
        $reflection = new \ReflectionClass(UsageReportLink::class);
        $block = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('_urlBuilder')->setValue($block, $urlBuilder);
        $reflection->getProperty('_authorization')->setValue($block, $authorization);
        $reflection->getProperty('_escaper')->setValue($block, new Escaper());

        return $block;
    }
}

/**
 * In-memory stand-in for {@see UrlInterface} that answers `getUrl()` with a fixed prefix plus the
 * route, and records what it was asked for. Every other method is outside what the block calls
 * and throws, so a test that comes to depend on one fails loudly.
 */
final class FakeUrlBuilder implements UrlInterface
{
    /** @var string[] */
    private array $requestedRoutes = [];

    public function __construct(private readonly string $prefix = 'https://store.test/admin/')
    {
    }

    public function urlFor(string $route): string
    {
        return $this->prefix . $route . '/';
    }

    /**
     * @return string[]
     */
    public function getRequestedRoutes(): array
    {
        return $this->requestedRoutes;
    }

    public function getUrl($routePath = null, $routeParams = null): string
    {
        $this->requestedRoutes[] = (string) $routePath;

        return $this->urlFor((string) $routePath);
    }

    public function getUseSession(): bool
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function getBaseUrl($params = []): string
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function getCurrentUrl(): string
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function getRouteUrl($routePath = null, $routeParams = null): string
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function addSessionParam(): self
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function addQueryParams(array $data): self
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function setQueryParam($key, $data): self
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function escape($value): string
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function getDirectUrl($url, $params = []): string
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function sessionUrlVar($html): string
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function isOwnOriginUrl(): bool
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function getRedirectUrl($url): string
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }

    public function setScope($params): self
    {
        throw new \LogicException('Not needed by UsageReportLinkTest.');
    }
}

/**
 * In-memory stand-in for {@see AuthorizationInterface} with one fixed answer, recording which
 * resources were asked about.
 */
final class FakeAuthorization implements AuthorizationInterface
{
    /** @var string[] */
    private array $checkedResources = [];

    public function __construct(private readonly bool $isAllowed)
    {
    }

    /**
     * @return string[]
     */
    public function getCheckedResources(): array
    {
        return $this->checkedResources;
    }

    public function isAllowed($resource, $privilege = null): bool
    {
        $this->checkedResources[] = (string) $resource;

        return $this->isAllowed;
    }
}
