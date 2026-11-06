<?php

/**
 * ContextAssembler renders a Context's inline `documents` (hermiq-context-documents).
 *
 * Uses the real ObjectEntity and a mock of the real ObjectService/IRootFolder
 * interfaces. Covers: a document rendered under its name, a malformed entry
 * skipped without aborting, the no-documents identity case, and documents
 * counting toward the existing charBudget.
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
 * @spec openspec/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the documents source kind in ContextAssembler.
 *
 * @spec openspec/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
 */
class ContextAssemblerDocumentsTest extends TestCase {

	/**
	 * Assemble one Context with the given payload; returns the result and every saved payload.
	 *
	 * @param array<string, mixed> $payload The Context object data.
	 *
	 * @return array{0: array{text: string, needsConsolidation: bool}, 1: array<int, array<string, mixed>>}
	 */
	private function assemblePayload(array $payload): array {
		$context = new ObjectEntity();
		$context->setUuid('ctx-uuid');
		$context->setObject($payload);

		$saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($context);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$assembler = new ContextAssembler($objectService, $this->createMock(IRootFolder::class), new NullLogger());
		$result = $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

		return [$result, $saved];
	}//end assemblePayload()

	/**
	 * A document is rendered as a section titled by its name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
	 */
	public function testADocumentIsRenderedUnderItsName(): void {
		[$result] = $this->assemblePayload(
			[
				'name' => 'Project standards',
				'documents' => [
					['name' => 'design.md', 'body' => "# Design\nUse the NL Design System tokens.", 'format' => 'markdown'],
				],
				'charBudget' => 8000,
			]
		);

		$this->assertStringContainsString('Context: Project standards', $result['text']);
		$this->assertStringContainsString("Document: design.md\n# Design\nUse the NL Design System tokens.", $result['text']);
		$this->assertFalse($result['needsConsolidation']);

	}//end testADocumentIsRenderedUnderItsName()

	/**
	 * A malformed entry (no body, no name, not an object) is skipped; the valid one still renders.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
	 */
	public function testAMalformedDocumentIsSkippedNotFatal(): void {
		[$result] = $this->assemblePayload(
			[
				'name' => 'Mixed',
				'documents' => [
					['name' => 'no-body.md'],
					['body' => 'Orphan body without a name.'],
					'just a string',
					['name' => 'coding-standard.md', 'body' => 'Tabs, not spaces.'],
				],
			]
		);

		$this->assertStringContainsString("Document: coding-standard.md\nTabs, not spaces.", $result['text']);
		$this->assertStringNotContainsString('no-body.md', $result['text']);
		$this->assertStringNotContainsString('Orphan body', $result['text']);

	}//end testAMalformedDocumentIsSkippedNotFatal()

	/**
	 * With no documents the output equals the output before this change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
	 */
	public function testNoDocumentsBehavesAsBefore(): void {
		[$absent] = $this->assemblePayload(['name' => 'Empty']);
		[$empty] = $this->assemblePayload(['name' => 'Empty', 'documents' => []]);

		$this->assertSame('Context: Empty', $absent['text']);
		$this->assertSame('Context: Empty', $empty['text']);

	}//end testNoDocumentsBehavesAsBefore()

	/**
	 * Documents count toward charBudget: over budget flags and persists needsConsolidation,
	 * and the text is never truncated.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-documents/spec.md#requirement-documents-share-the-existing-budget-contract
	 */
	public function testDocumentsPushABundleOverBudget(): void {
		[$result, $saved] = $this->assemblePayload(
			[
				'name' => 'Big',
				'documents' => [['name' => 'long.md', 'body' => 'Far more than ten characters of guidance.']],
				'charBudget' => 10,
				'needsConsolidation' => false,
			]
		);

		$this->assertTrue($result['needsConsolidation']);
		$this->assertStringContainsString('Far more than ten characters of guidance.', $result['text']);
		$this->assertCount(1, $saved);
		$this->assertTrue($saved[0]['needsConsolidation']);
		$this->assertSame('long.md', $saved[0]['documents'][0]['name'], 'The persisted payload keeps the documents.');

	}//end testDocumentsPushABundleOverBudget()
}//end class
