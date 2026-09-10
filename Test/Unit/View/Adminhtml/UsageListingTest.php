<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\View\Adminhtml;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The declarative usage listing UI component (task 015): the module's first UI component, over
 * `mageos_ai_usage_log`. Parsed as plain XML rather than resolved through Magento's UI component
 * factory, since the tree itself carries no behaviour to exercise, only structure to get right.
 */
final class UsageListingTest extends TestCase
{
    private const LISTING_PATH = __DIR__ . '/../../../../src/view/adminhtml/ui_component/mageos_ai_usage_listing.xml';

    private SimpleXMLElement $listing;

    protected function setUp(): void
    {
        $this->listing = new SimpleXMLElement((string) file_get_contents(self::LISTING_PATH));
    }

    public function test_it_guards_the_listing_data_source_with_the_usage_acl_resource(): void
    {
        // This element is the whole authorization boundary for the grid's data endpoint:
        // Magento\Ui\Controller\Adminhtml\Index\Render checks it before serving rows, and its
        // own ADMIN_RESOURCE is only Magento_Backend::admin. Lose it and any admin with any role
        // can read every recorded call through the UI component endpoint, whatever the menu and
        // the page controller allow.
        $aclResources = $this->listing->xpath('//dataSource/aclResource');

        self::assertCount(1, $aclResources);
        self::assertSame('MageOS_AiBase::usage', (string) $aclResources[0]);
    }

    public function test_it_declares_every_token_count_as_a_column_of_the_listing(): void
    {
        foreach (['input_tokens', 'output_tokens', 'total_tokens', 'cached_tokens', 'reasoning_tokens'] as $columnName) {
            self::assertNotNull($this->findColumn($columnName), $columnName);
        }
    }

    public function test_it_declares_no_column_carrying_prompt_or_response_content(): void
    {
        $forbiddenNames = ['prompt', 'response', 'content', 'messages', 'input_text', 'output_text', 'raw_request', 'raw_response'];

        $columnNames = array_map(
            static fn (SimpleXMLElement $column): string => (string) $column['name'],
            $this->listing->xpath('//columns/column | //columns/selectionsColumn | //columns/actionsColumn'),
        );

        foreach ($forbiddenNames as $forbiddenName) {
            self::assertNotContains($forbiddenName, $columnNames);
        }
    }

    public function test_it_declares_no_mass_actions_on_the_listing(): void
    {
        self::assertCount(0, $this->listing->xpath('//massaction'));
    }

    public function test_it_sources_the_consumer_filter_from_the_distinct_recorded_consumers(): void
    {
        $consumerColumn = $this->findColumn('consumer');

        self::assertNotNull($consumerColumn);
        $options = $consumerColumn->xpath('.//options');
        self::assertNotEmpty($options);
        self::assertSame(
            'MageOS\AiBase\Model\Usage\Source\Consumer',
            (string) $options[0]['class']
        );
    }

    public function test_it_sources_the_service_filter_from_the_service_row_option_source(): void
    {
        $serviceColumn = $this->findColumn('service_id');

        self::assertNotNull($serviceColumn);
        $options = $serviceColumn->xpath('.//options');
        self::assertNotEmpty($options);
        self::assertSame(
            'MageOS\AiBase\Model\Usage\Source\ServiceRow',
            (string) $options[0]['class']
        );
    }

    public function test_it_renders_created_at_as_a_timezone_aware_date_column(): void
    {
        $createdAt = $this->findColumn('created_at');

        self::assertNotNull($createdAt);
        self::assertSame('Magento\Ui\Component\Listing\Columns\Date', (string) $createdAt['class']);
        $dataType = $createdAt->xpath('.//dataType');
        self::assertNotEmpty($dataType);
        self::assertSame('date', (string) $dataType[0]);
    }

    /**
     * `ui_configuration.xsd` pulls in `ui_components.xsd` through a `urn:magento:module:...`
     * schema location, which plain libxml cannot resolve on its own. Magento's own
     * {@see \Magento\Framework\Config\Dom\UrnResolver} is what every module.xsd/di.xsd validation
     * elsewhere in Magento core resolves those through, registered as libxml's external entity
     * loader for the one validation call.
     */
    public function test_it_validates_against_the_ui_component_schema(): void
    {
        $urnResolver = new \Magento\Framework\Config\Dom\UrnResolver();
        $schemaPath = $urnResolver->getRealPath('urn:magento:module:Magento_Ui:etc/ui_configuration.xsd');

        $document = new \DOMDocument();
        $document->load(self::LISTING_PATH);

        libxml_set_external_entity_loader($urnResolver->registerEntityLoader(...));
        $isValid = $document->schemaValidate($schemaPath);
        libxml_set_external_entity_loader(null);

        self::assertTrue($isValid);
    }

    private function findColumn(string $name): ?SimpleXMLElement
    {
        $matches = $this->listing->xpath('//columns/column[@name="' . $name . '"]');

        return $matches[0] ?? null;
    }
}
