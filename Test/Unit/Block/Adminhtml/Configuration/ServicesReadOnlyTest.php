<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use MageOS\AiBase\Block\Adminhtml\Configuration\Services;
use PHPUnit\Framework\TestCase;

/**
 * A field set in deployment configuration must say so instead of pretending to be editable.
 *
 * `bin/magento app:config:dump` and `config:set --lock-env` write this path into `app/etc/env.php`,
 * and deployment configuration wins over the database. `Magento\Config\Model\Config` then skips the
 * field on save without reporting anything, and the section still answers "You saved the
 * configuration". Magento's own field renderers reflect that by disabling the control, but this
 * form builds every control itself, so an administrator gets a fully interactive page, a success
 * message, and no change: adding a provider, renaming one and deleting one all appear to work and
 * none of them survive the reload.
 *
 * The config form marks that state on the element it hands this renderer, which is where the block
 * reads it from.
 */
final class ServicesReadOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AbstractFieldArray::class)) {
            self::markTestSkipped('magento/module-config is not installed in this environment.');
        }
    }

    public function test_a_field_disabled_by_deployment_configuration_is_reported_read_only(): void
    {
        self::assertTrue($this->blockForElement(new DataObject(['disabled' => true]))->isReadOnly());
    }

    public function test_an_editable_field_is_not_reported_read_only(): void
    {
        self::assertFalse($this->blockForElement(new DataObject(['disabled' => false]))->isReadOnly());
    }

    /**
     * Magento only sets the key when it has an answer, and an absent key is the ordinary case: it
     * is what every install that never dumped its configuration renders with.
     */
    public function test_an_element_without_the_key_is_not_reported_read_only(): void
    {
        self::assertFalse($this->blockForElement(new DataObject())->isReadOnly());
    }

    /**
     * The renderer is a layout singleton, so it is asked before the config form has handed it an
     * element at all. Answering read-only there would lock a form nothing is wrong with.
     */
    public function test_a_block_with_no_element_yet_is_not_reported_read_only(): void
    {
        self::assertFalse($this->block()->isReadOnly());
    }

    /**
     * @param DataObject $element
     * @return Services
     */
    private function blockForElement(DataObject $element): Services
    {
        $block = $this->block();
        $block->setData('element', $element);

        return $block;
    }

    /**
     * The constructor pulls in the whole block context, and none of it is reached by the state this
     * test is about.
     *
     * @return Services
     */
    private function block(): Services
    {
        return (new \ReflectionClass(Services::class))->newInstanceWithoutConstructor();
    }
}
