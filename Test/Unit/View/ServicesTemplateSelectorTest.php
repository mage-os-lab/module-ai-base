<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Guards the admin form template against building CSS selectors out of stored data.
 *
 * Row IDs, service codes and field names are all POST array keys of the serialized
 * `mageos_ai/services/configuration` value, and nothing normalises them on the way in.
 * Interpolated into a selector string, a value containing a double quote produces an
 * invalid selector; `querySelector` then throws a SyntaxError, which aborts the calling
 * loop and leaves the configuration form half-rendered.
 *
 * The JavaScript lives inside a PHP heredoc and this repo has no JS tooling, so the
 * property is asserted against the template source.
 */
final class ServicesTemplateSelectorTest extends TestCase
{
    private const TEMPLATE = __DIR__
        . '/../../../src/view/adminhtml/templates/system/config/form/field/services.phtml';

    private string $template;

    protected function setUp(): void
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template, 'Template is unreadable: ' . self::TEMPLATE);

        $this->template = $template;
    }

    /**
     * Every selector handed to querySelector/querySelectorAll must be a literal.
     *
     * A literal is a single-quoted string with no concatenation before the closing
     * parenthesis. Anything else is building a selector from a runtime value.
     */
    public function test_no_selector_is_built_from_runtime_values(): void
    {
        preg_match_all(
            '/querySelector(?:All)?\(([^)]*)\)/',
            $this->template,
            $matches
        );

        $dynamic = array_values(array_filter(
            $matches[1] ?? [],
            static fn (string $argument): bool => !preg_match("/^'[^']*'$/", trim($argument))
        ));

        self::assertSame(
            [],
            $dynamic,
            "Selectors must be literals; stored row IDs and field names reach the browser "
            . "unmodified and a double quote in one makes querySelector throw.\n"
            . "Build the lookup by comparing attributes in JavaScript instead. Offending arguments:\n  "
            . implode("\n  ", $dynamic)
        );
    }

    public function test_field_lookup_helper_is_present_so_the_guard_is_not_vacuous(): void
    {
        self::assertStringContainsString(
            'function findField(',
            $this->template,
            'The attribute-matching lookup is gone; selectors may have been reintroduced under another shape.'
        );
    }
}
