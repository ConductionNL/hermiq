<?php

/**
 * The Context payload the editor saves, against the REAL Context schema fragment
 * (hermiq-context-documents).
 *
 * ContextFormModal saves name, description, documents, files and objectQueries and
 * spreads the stored object first; this validates that exact payload shape against
 * lib/Settings/hermiq_register.json with Opis, so a write the schema refuses fails
 * here rather than live.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validates Context payloads carrying documents.
 *
 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
 */
class ContextDocumentsSchemaTest extends TestCase {

	/**
	 * Validate a payload against the Context schema from the register file.
	 *
	 * @param array<string, mixed> $payload The payload the editor saves.
	 *
	 * @return bool Whether it is valid.
	 */
	private function valid(array $payload): bool {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->Context;

		return (new Validator())->validate(json_decode((string)json_encode($payload)), $schema)->isValid();
	}//end valid()

	/**
	 * A created Context and an edited one (stored fields spread first) both save.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
	 */
	public function testTheEditorPayloadSaves(): void {
		$created = [
			'name' => 'Project standards',
			'description' => 'How this team writes code',
			'documents' => [['name' => 'design.md', 'body' => "# Design\nTokens only.", 'format' => 'markdown', 'description' => '']],
			'files' => [],
			'objectQueries' => [],
		];
		$this->assertTrue($this->valid($created));

		$edited = array_merge($created, ['charBudget' => 8000, 'viewRefs' => [], 'needsConsolidation' => false]);
		$this->assertTrue($this->valid($edited));

	}//end testTheEditorPayloadSaves()

	/**
	 * A document body that is not a string, or a documents value that is not a list, is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry
	 */
	public function testAWrongShapeIsRefused(): void {
		$this->assertFalse($this->valid(['name' => 'X', 'documents' => [['name' => 'a.md', 'body' => ['not', 'text']]]]));
		$this->assertFalse($this->valid(['name' => 'X', 'documents' => 'design.md']));
		$this->assertFalse($this->valid(['description' => 'no name']));

	}//end testAWrongShapeIsRefused()
}//end class
