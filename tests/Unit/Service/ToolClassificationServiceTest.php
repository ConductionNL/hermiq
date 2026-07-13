<?php

/**
 * Unit tests for ToolClassificationService (run-replay-and-dry-run).
 *
 * Verifies the fail-safe-closed side-effect classification dry-run relies on:
 * a hint-carrying or verb-suffixed read tool classifies as NOT side-effecting,
 * a write/destructive one classifies as side-effecting, and anything
 * unclassifiable (empty id, hint-less non-3-segment id) defaults CLOSED
 * (side-effecting) rather than throwing or silently passing as safe.
 *
 * Note: the design's own illustrative example (`openregister.searchObjects`)
 * is NOT a live OpenRegister tool id at HEAD — OpenRegister's built-in objects
 * tool is the single `openregister.objects` id with an `action` enum
 * (list/get/create/update/delete), not per-verb ids. These tests use
 * representative 3-segment ADR-063 ids and explicit hint descriptors instead,
 * which exercise the SAME underlying `ToolGrantResolver::isWriteOrDestructive()`
 * classification this service delegates to.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ToolClassificationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the run-replay-and-dry-run side-effect classifier.
 *
 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
 */
class ToolClassificationServiceTest extends TestCase
{

    /**
     * A 3-segment id with a read verb (no descriptor) classifies as NOT
     * side-effecting — the verb-suffix fallback recognises it as read.
     *
     * @return void
     */
    public function testReadVerbSuffixIsNotSideEffecting(): void
    {
        $classifier = new ToolClassificationService();
        $this->assertFalse($classifier->isSideEffecting(id: 'demo.schema.search'));

    }//end testReadVerbSuffixIsNotSideEffecting()

    /**
     * A 3-segment id with a write verb (no descriptor) classifies as
     * side-effecting.
     *
     * @return void
     */
    public function testWriteVerbSuffixIsSideEffecting(): void
    {
        $classifier = new ToolClassificationService();
        $this->assertTrue($classifier->isSideEffecting(id: 'demo.schema.create'));

    }//end testWriteVerbSuffixIsSideEffecting()

    /**
     * A hint-less, non-3-segment (curated/legacy) id with no descriptor
     * defaults to side-effecting — fail-safe closed, never silently read.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
     */
    public function testUnclassifiedCuratedIdDefaultsToSideEffecting(): void
    {
        $classifier = new ToolClassificationService();
        $this->assertTrue($classifier->isSideEffecting(id: 'pipelinq.createLead'));

    }//end testUnclassifiedCuratedIdDefaultsToSideEffecting()

    /**
     * A curated id WITH a descriptor carrying `readOnlyHint: true` classifies
     * as NOT side-effecting — the declared hint overrides the fail-closed
     * default for a hint-less/non-3-segment shape.
     *
     * @return void
     */
    public function testReadOnlyHintOverridesCuratedIdDefault(): void
    {
        $classifier = new ToolClassificationService();
        $this->assertFalse(
            $classifier->isSideEffecting(id: 'pipelinq.searchLeads', descriptor: ['readOnlyHint' => true])
        );

    }//end testReadOnlyHintOverridesCuratedIdDefault()

    /**
     * A curated id WITH a descriptor carrying `destructiveHint: true`
     * classifies as side-effecting.
     *
     * @return void
     */
    public function testDestructiveHintClassifiesAsSideEffecting(): void
    {
        $classifier = new ToolClassificationService();
        $this->assertTrue(
            $classifier->isSideEffecting(id: 'pipelinq.archiveLead', descriptor: ['destructiveHint' => true])
        );

    }//end testDestructiveHintClassifiesAsSideEffecting()

    /**
     * An empty/malformed registry id defaults to side-effecting rather than
     * throwing.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
     */
    public function testEmptyIdDefaultsToSideEffectingWithoutThrowing(): void
    {
        $classifier = new ToolClassificationService();
        $this->assertTrue($classifier->isSideEffecting(id: ''));

    }//end testEmptyIdDefaultsToSideEffectingWithoutThrowing()
}//end class
