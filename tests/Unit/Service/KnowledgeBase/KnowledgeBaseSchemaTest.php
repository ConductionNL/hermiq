<?php

/**
 * The KnowledgeBase schema and Agent.knowledgeBaseRefs against the REAL register file
 * (memory-knowledge-bases, task 1).
 *
 * Validates the payloads a knowledge base and an agent with knowledge bases save, with
 * Opis, against lib/Settings/hermiq_register.json, and pins the declaration the import
 * needs: the slug listed on the register, the $ref, and the version bump.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\KnowledgeBase
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\KnowledgeBase;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validates KnowledgeBase and Agent payloads and the register declaration.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
 */
class KnowledgeBaseSchemaTest extends TestCase {

	/**
	 * The register file, decoded.
	 *
	 * @return object The register.
	 */
	private function register(): object {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'));
	}//end register()

	/**
	 * One schema from the register, with OpenRegister's slug `$ref`s removed.
	 *
	 * @param string $name The schema key.
	 *
	 * @return object The schema.
	 */
	private function schema(string $name): object {
		$schema = $this->register()->components->schemas->{$name};
		$this->stripSlugRefs($schema);

		return $schema;
	}//end schema()

	/**
	 * Remove every `$ref` that is not a JSON pointer, recursively.
	 *
	 * @param mixed $node A schema node.
	 *
	 * @return void
	 */
	private function stripSlugRefs(mixed $node): void {
		if (is_object($node) === false && is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $child) {
			if ($key === '$ref' && is_string($child) === true && str_starts_with($child, '#') === false) {
				unset($node->{'$ref'});
				continue;
			}

			$this->stripSlugRefs($child);
		}
	}//end stripSlugRefs()

	/**
	 * Validate a payload against one schema.
	 *
	 * @param string               $name    The schema key.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool Whether it is valid.
	 */
	private function valid(string $name, array $payload): bool {
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $this->schema(name: $name))->isValid();
	}//end valid()

	/**
	 * A knowledge base with a folder and an integriq-filled folder saves.
	 *
	 * @return void
	 */
	public function testAKnowledgeBaseWithFolderSourcesSaves(): void {
		$this->assertTrue(
			$this->valid(
				name: 'KnowledgeBase',
				payload: [
					'name' => 'Inkoopbeleid',
					'description' => 'Het inkoop- en aanbestedingsbeleid.',
					'folderPath' => 'Team Inkoop/Kennisbank',
					'sources' => [
						['type' => 'folder', 'fileId' => 412, 'path' => 'Team Inkoop/Kennisbank'],
						['type' => 'folder', 'fileId' => 97, 'path' => 'Integraties/SharePoint/Beleidsdocumenten', 'label' => 'Synced by integriq from SharePoint'],
						['type' => 'objects', 'register' => 'wiki', 'schema' => 'page'],
					],
				]
			)
		);
	}//end testAKnowledgeBaseWithFolderSourcesSaves()

	/**
	 * A source of an unknown type, or a knowledge base without a name, is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceTypeOrAMissingNameIsRefused(): void {
		$this->assertFalse($this->valid(name: 'KnowledgeBase', payload: ['name' => 'X', 'sources' => [['type' => 'sharepoint']]]));
		$this->assertFalse($this->valid(name: 'KnowledgeBase', payload: ['description' => 'No name']));
	}//end testAnUnknownSourceTypeOrAMissingNameIsRefused()

	/**
	 * An agent saves with knowledge base uuids, and not with a non-array value.
	 *
	 * @return void
	 */
	public function testAnAgentCarriesKnowledgeBaseRefs(): void {
		$base = ['name' => 'Inkoopassistent'];
		$this->assertTrue($this->valid(name: 'Agent', payload: $base + ['knowledgeBaseRefs' => ['6f1c2a4e-1b7d-4c0a-9b8e-2d3f4a5b6c7d']]));
		$this->assertFalse($this->valid(name: 'Agent', payload: $base + ['knowledgeBaseRefs' => 'not-a-list']));
	}//end testAnAgentCarriesKnowledgeBaseRefs()

	/**
	 * The register lists the slug, the agent refers to it, writes stay owner-only, and the
	 * version moved so the import picks it up.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresTheSchemaForTheImport(): void {
		$register = $this->register();
		$schema = $register->components->schemas->KnowledgeBase;

		$this->assertSame('agentknowledgebase', $schema->slug);
		$this->assertContains('agentknowledgebase', $register->components->registers->hermiq->schemas);
		$this->assertSame('agentknowledgebase', $register->components->schemas->Agent->properties->knowledgeBaseRefs->items->{'$ref'});
		$this->assertSame(['read'], array_keys((array)$schema->authorization));
		$this->assertTrue(version_compare($register->info->version, '0.52.0', '>'));
	}//end testTheRegisterDeclaresTheSchemaForTheImport()
}//end class
