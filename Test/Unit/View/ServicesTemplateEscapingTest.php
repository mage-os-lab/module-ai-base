<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the admin form template against unescaped interpolation of the stored row ID.
 *
 * Row IDs are the array keys of the serialized `mageos_ai/services/configuration` value.
 * They arrive as POST array keys and nothing between the request and `core_config_data`
 * normalises them, so they must be treated as untrusted wherever they reach markup.
 *
 * The template's JavaScript lives inside a PHP heredoc, so no JS tooling in this repo can
 * see it. These tests assert the escaping contract against the template source instead.
 */
final class ServicesTemplateEscapingTest extends TestCase
{
    private const TEMPLATE = __DIR__
        . '/../../../src/view/adminhtml/templates/system/config/form/field/services.phtml';

    /**
     * Uses of `rowId` that legitimately do not need `escapeHtml`, with the reason.
     *
     * Anything else that touches `rowId` is assumed to build markup and must escape it.
     */
    private const NON_MARKUP_USES = [
        'const rowId = existingRowId' => 'generates the value',
        'buildField(serviceCode, rowId, field)' => 'buildField escapes the assembled name attribute',
        'findField(rowId,' => 'findField compares the assembled name with getAttribute(), never parsing it',
        "+ rowId + ']['" => 'builds a plain name string compared against getAttribute(), not markup',
        'dataset.rowId' => 'reads the value back out of the DOM',
        'syncRowModel(rowId,' => 'passes the value on to a function that compares it with getAttribute()',
        'findRowModelLabel(rowId)' => 'compares the assembled value with getAttribute(), never parsing it',
        'return rowId;' => 'hands the value back to the caller, no markup involved',
        "getAttribute('data-row-model') === rowId" => 'compares the value, never parses or renders it',
        'const rowId = addRow(' => 'receives the value back from addRow()',
        'document.getElementById(rowId)' => 'getElementById takes an ID, not a selector, so nothing parses it',
    ];

    /**
     * A function declaring `rowId` as a parameter, matched by shape rather than by name so
     * that renaming the function does not fail this guard for a line that cannot interpolate.
     *
     * The pattern is anchored on both ends and stops at the opening brace, so a line that
     * both declares the parameter and builds markup cannot slip through it.
     */
    private const PARAMETER_DECLARATION = '/^function \w+\((?:[\w\s,]*,\s*)?rowId\b[\w\s,]*\) \{$/';

    private string $template;

    protected function setUp(): void
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template, 'Template is unreadable: ' . self::TEMPLATE);

        $this->template = $template;
    }

    public function test_every_row_id_interpolation_into_markup_is_escaped(): void
    {
        $unescaped = array_filter(
            $this->linesContainingRowId(),
            fn (string $line): bool => !$this->isNonMarkupUse($line) && !str_contains($line, 'escapeHtml(rowId)')
        );

        self::assertSame(
            [],
            $unescaped,
            "Row IDs are attacker-controllable and must not reach markup unescaped.\n"
            . "Wrap each of these in escapeHtml(), or add the use to NON_MARKUP_USES with a reason:\n  "
            . implode("\n  ", $unescaped)
        );
    }

    public function test_the_row_id_sinks_are_still_present_so_the_guard_cannot_pass_vacuously(): void
    {
        self::assertNotSame(
            [],
            array_filter($this->linesContainingRowId(), fn (string $line): bool => str_contains($line, 'escapeHtml(rowId)')),
            'No escaped row-ID interpolation found at all; the template moved and this test now guards nothing.'
        );
    }

    /**
     * The parameter-declaration exemption is the one rule matched by shape instead of by
     * literal, so pin what it must not swallow: anything that concatenates the row ID.
     */
    #[DataProvider('lines_the_parameter_declaration_exemption_must_reject')]
    public function test_the_parameter_declaration_exemption_covers_declarations_only(string $line): void
    {
        self::assertDoesNotMatchRegularExpression(
            self::PARAMETER_DECLARATION,
            $line,
            'The parameter-declaration exemption now covers a line that reaches markup.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lines_the_parameter_declaration_exemption_must_reject(): array
    {
        return [
            'attribute interpolation' => ["const rowHtml = '<tr id=\"' + rowId + '\">'"],
            'declaration followed by markup' => ["function f(rowId) { return '<tr id=\"' + rowId + '\">'; }"],
            'call rather than declaration' => ['renderRow(rowId, serviceCode);'],
        ];
    }

    /**
     * The fix above is only worth anything if the helper it delegates to closes the
     * attribute it is used inside, so pin the characters that break out of one.
     */
    #[DataProvider('attribute_breaking_characters')]
    public function test_escape_html_helper_handles_attribute_breaking_characters(string $character): void
    {
        self::assertMatchesRegularExpression(
            '/\.replace\(\/' . preg_quote($character, '/') . '\/g,/',
            $this->escapeHtmlHelper(),
            sprintf('escapeHtml() no longer escapes "%s", so escaping the row ID stops protecting anything.', $character)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function attribute_breaking_characters(): array
    {
        return [
            'ampersand' => ['&'],
            'less than' => ['<'],
            'greater than' => ['>'],
            'double quote' => ['"'],
            'single quote' => ["'"],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function linesContainingRowId(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $this->template)),
            fn (string $line): bool => str_contains($line, 'rowId')
        ));
    }

    private function isNonMarkupUse(string $line): bool
    {
        if (preg_match(self::PARAMETER_DECLARATION, $line) === 1) {
            return true;
        }

        foreach (array_keys(self::NON_MARKUP_USES) as $allowed) {
            if (str_contains($line, $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function escapeHtmlHelper(): string
    {
        preg_match('/function escapeHtml\(value\) \{(.*?)\n    \}/s', $this->template, $matches);
        self::assertArrayHasKey(1, $matches, 'escapeHtml() helper not found in the template.');

        return $matches[1];
    }
}
