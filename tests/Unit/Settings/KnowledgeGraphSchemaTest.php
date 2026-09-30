<?php

/**
 * The GraphEntity and GraphRelation payloads GraphService writes, against the REAL
 * schema fragments in lib/Settings/hermiq_register.json (knowledge-graph).
 *
 * GraphServiceTest captures the exact arrays GraphService hands to saveObject();
 * this validates those shapes with Opis, so a write the schema refuses fails here
 * rather than live.
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use OCA\Hermiq\Service\Graph\GraphService;
use OCA\Hermiq\Service\Graph\GraphVisibility;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\Validator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Validates graph payloads against the register.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
 */
class KnowledgeGraphSchemaTest extends TestCase {

	/**
	 * The decoded register file.
	 *
	 * @return object The register.
	 */
	private function register(): object {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
	}//end register()

	/**
	 * Validate a payload against a schema from the register file.
	 *
	 * @param string $schema The schema key under components.schemas.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool Whether it is valid.
	 */
	private function valid(string $schema, array $payload): bool {
		$fragment = $this->register()->components->schemas->{$schema};
		$this->stripSlugRefs(node: $fragment);

		return (new Validator())->validate(json_decode((string)json_encode($payload)), $fragment)->isValid();
	}//end valid()

	/**
	 * Remove every OpenRegister slug `$ref` (not a JSON pointer), recursively; Opis
	 * cannot resolve a schema slug, and the uuid format still applies.
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

			$this->stripSlugRefs(node: $child);
		}
	}//end stripSlugRefs()

	/**
	 * The payloads GraphService actually writes validate against both schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
	 */
	public function testThePayloadsGraphServiceWritesAreValid(): void {
		$saved = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null) use (&$saved): ObjectEntity {
				$saved[] = ['schema' => $schema, 'object' => $object];
				$entity = new ObjectEntity();
				$entity->setUuid('00000000-0000-4000-8000-00000000000' . count($saved));
				$entity->setObject($object);

				return $entity;
			}
		);

		$graph = new GraphService(
			$objects,
			$this->createMock(GraphVisibility::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

		$from = $graph->upsertEntity(
			[
				'label' => 'Jan Jansen',
				'entityType' => 'person',
				'sourceType' => 'object',
				'sourceRef' => ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'c-1'],
				'aliases' => ['J. Jansen'],
				'confidence' => 0.9,
				'extractedBy' => 'llm-extractor@1',
			]
		);
		$to = $graph->upsertEntity(
			[
				'label' => 'Report.pdf',
				'entityType' => 'document',
				'sourceType' => 'file',
				'sourceRef' => ['fileId' => 42, 'path' => '/Reports/Report.pdf'],
				'confidence' => 0.5,
				'extractedBy' => 'llm-extractor@1',
			]
		);
		$graph->upsertEntity(
			[
				'label' => 'Weekly sync',
				'entityType' => 'topic',
				'sourceType' => 'mail',
				'sourceRef' => ['accountId' => 1, 'mailboxId' => 2, 'messageId' => 3],
			]
		);
		$graph->upsertEntity(
			[
				'label' => 'Budget',
				'entityType' => 'topic',
				'sourceType' => 'conversation',
				'sourceRef' => ['conversationUuid' => 'conv-1', 'messageId' => 'msg-1'],
			]
		);
		$graph->upsertRelation(
			[
				'fromEntity' => $from,
				'predicate' => 'authoredBy',
				'toEntity' => $to,
				'sourceType' => 'file',
				'sourceRef' => ['fileId' => 42],
				'confidence' => 0.7,
				'extractedBy' => 'llm-extractor@1',
			]
		);

		$this->assertCount(5, $saved);
		foreach ($saved as $write) {
			$key = ($write['schema'] === GraphService::ENTITY_SCHEMA) ? 'GraphEntity' : 'GraphRelation';
			$this->assertTrue($this->valid($key, $write['object']), $key . ' refused: ' . json_encode($write['object']));
		}

	}//end testThePayloadsGraphServiceWritesAreValid()

	/**
	 * The schemas refuse a node without a source and a relation without endpoints,
	 * and a confidence outside 0 to 1; the Agent gains graphEnabled defaulting to false.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-nodes-reference-records-and-never-copy-them
	 */
	public function testTheSchemasRefuseIncompleteEntries(): void {
		$this->assertFalse($this->valid('GraphEntity', ['label' => 'X', 'entityType' => 'topic']));
		$this->assertFalse(
			$this->valid(
				'GraphEntity',
				['label' => 'X', 'entityType' => 'topic', 'sourceType' => 'web', 'sourceRef' => []]
			)
		);
		$this->assertFalse(
			$this->valid(
				'GraphEntity',
				['label' => 'X', 'entityType' => 'topic', 'sourceType' => 'file', 'sourceRef' => ['fileId' => 1], 'confidence' => 2]
			)
		);
		$this->assertFalse($this->valid('GraphRelation', ['predicate' => 'about']));

		$register = $this->register();
		$this->assertContains('graphentity', $register->components->registers->hermiq->schemas);
		$this->assertContains('graphrelation', $register->components->registers->hermiq->schemas);
		$this->assertFalse($register->components->schemas->Agent->properties->graphEnabled->default);
		$this->assertSame('0.37.0', $register->info->version);

	}//end testTheSchemasRefuseIncompleteEntries()
}//end class
