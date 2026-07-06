<?php

/**
 * Unit tests for SanitizesForSaveTrait (agent-engine-port).
 *
 * Covers the save-side normalisation that guards OpenRegister's whole-object
 * re-validation: SQL-shaped date-time strings reformatted to ISO-8601, all-null
 * nested objects collapsed back to null, lists never collapsed, and recursion
 * through nested payloads.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared sanitizeForSave() normalisation.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
 */
class SanitizesForSaveTraitTest extends TestCase
{

    /**
     * A concrete harness exposing the trait's private method.
     *
     * @return object Harness with a public sanitize() entry.
     */
    private function harness(): object
    {
        return new class {
            use SanitizesForSaveTrait;

            /**
             * Public entry to the private trait method.
             *
             * @param array<string, mixed> $data The payload.
             *
             * @return array<string, mixed> The normalised payload.
             */
            public function sanitize(array $data): array
            {
                return $this->sanitizeForSave(data: $data);
            }
        };

    }//end harness()

    /**
     * SQL-shaped date-times (`Y-m-d H:i:s`) are reformatted to ISO-8601 with a T.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     */
    public function testReformatsSqlDateTimes(): void
    {
        $result = $this->harness()->sanitize(['startedAt' => '2026-07-06 10:30:00']);

        $this->assertStringContainsString('T', $result['startedAt']);
        $this->assertStringStartsWith('2026-07-06T10:30:00', $result['startedAt']);

    }//end testReformatsSqlDateTimes()

    /**
     * Already-ISO strings and non-date strings pass through unchanged.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     */
    public function testLeavesOtherStringsUntouched(): void
    {
        $payload = [
            'iso'  => '2026-07-06T10:30:00+02:00',
            'text' => 'a message about 2026-07-06 10:30:00 embedded in prose',
            'name' => 'Agent',
        ];

        $result = $this->harness()->sanitize($payload);

        $this->assertSame($payload['iso'], $result['iso']);
        // Embedded dates inside longer prose do not match the full-string pattern.
        $this->assertSame($payload['text'], $result['text']);
        $this->assertSame('Agent', $result['name']);

    }//end testLeavesOtherStringsUntouched()

    /**
     * An all-null associative array (a materialized null nested object) collapses
     * back to null so nested minimum/required constraints do not fire.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     */
    public function testCollapsesAllNullNestedObjectToNull(): void
    {
        $result = $this->harness()->sanitize(
            [
                'metadata' => [
                    'summary'     => null,
                    'token_count' => null,
                ],
            ]
        );

        $this->assertNull($result['metadata']);

    }//end testCollapsesAllNullNestedObjectToNull()

    /**
     * A nested object with at least one real value is preserved (and recursed into).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     */
    public function testKeepsPartiallyFilledNestedObjectAndRecurses(): void
    {
        $result = $this->harness()->sanitize(
            [
                'metadata' => [
                    'summary'         => 'kept',
                    'last_summary_at' => '2026-07-06 09:00:00',
                ],
            ]
        );

        $this->assertSame('kept', $result['metadata']['summary']);
        $this->assertStringContainsString('T', $result['metadata']['last_summary_at']);

    }//end testKeepsPartiallyFilledNestedObjectAndRecurses()

    /**
     * Lists are never collapsed, even when they contain nulls; empty arrays are kept.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     */
    public function testNeverCollapsesListsOrEmptyArrays(): void
    {
        $result = $this->harness()->sanitize(
            [
                'sources' => [null, null],
                'tools'   => [],
            ]
        );

        $this->assertSame([null, null], $result['sources']);
        $this->assertSame([], $result['tools']);

    }//end testNeverCollapsesListsOrEmptyArrays()
}//end class
