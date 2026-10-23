<?php

/**
 * An in-memory knowledge graph for tests: an ObjectService double that honours the
 * register, schema, equality filters and uuids GraphService passes, and a
 * GraphVisibility double that shows a node while its record key is in $readable.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use OCA\Hermiq\Service\Graph\GraphService;
use OCA\Hermiq\Service\Graph\GraphVisibility;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * In-memory graph fixture for TestCase subclasses.
 */
trait InMemoryGraph {

	/**
	 * Stored objects per schema slug, keyed by uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	protected array $store = [];

	/**
	 * Every saveObject call, in order: schema, uuid and payload.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $writes = [];

	/**
	 * Every read call's RBAC flag.
	 *
	 * @var array<int, bool>
	 */
	protected array $readRbac = [];

	/**
	 * Record keys (sourceRef uuid, or "file:<id>") the acting user can read.
	 *
	 * @var array<int, string>
	 */
	protected array $readable = [];

	/**
	 * The ObjectService double behind the last graph() call; it also serves any
	 * record put in $store under its own schema slug.
	 *
	 * @var ObjectService|null
	 */
	protected ?ObjectService $objects = null;

	/**
	 * Records only their owner may find with RBAC on: record uuid to uid.
	 *
	 * @var array<string, string>
	 */
	protected array $ownerOf = [];

	/**
	 * Who holds the session, for the RBAC check of find(); null means nobody is checked.
	 *
	 * @var \Closure|null
	 */
	protected ?\Closure $sessionUid = null;

	/**
	 * Build the service over the in-memory store.
	 *
	 * @param array<string, int> $config App config integers by key.
	 *
	 * @return GraphService
	 */
	protected function graph(array $config = []): GraphService {
		$schema = '';
		$objects = $this->createMock(ObjectService::class);
		$this->objects = $objects;
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			static function (mixed $slug) use (&$schema, $objects): ObjectService {
				$schema = (string)$slug;

				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use (&$schema): array {
				$this->readRbac[] = $_rbac;
				$out = [];
				foreach (($this->store[$schema] ?? []) as $uuid => $data) {
					foreach (($config['filters'] ?? []) as $field => $value) {
						if (($data[$field] ?? null) !== $value) {
							continue 2;
						}
					}

					$out[] = $this->entity(uuid: $uuid, data: $data);
				}

				return array_slice($out, 0, (int)($config['limit'] ?? 1000));
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schemaArg = null, bool $_rbac = true): ?ObjectEntity {
				$this->readRbac[] = $_rbac;
				$data = ($this->store[(string)$schemaArg][(string)$id] ?? null);
				$owner = ($this->ownerOf[(string)$id] ?? null);
				if ($_rbac === true && $owner !== null && $this->sessionUid !== null && ($this->sessionUid)() !== $owner) {
					$data = null;
				}

				return ($data === null) ? null : $this->entity(uuid: (string)$id, data: $data);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schemaArg = null, ?string $uuid = null): ObjectEntity {
				$uuid = ($uuid ?? sprintf('00000000-0000-4000-8000-%012d', count($this->writes) + 1));
				$this->writes[] = ['schema' => $schemaArg, 'uuid' => $uuid, 'object' => $object];
				$this->store[(string)$schemaArg][$uuid] = $object;

				return $this->entity(uuid: $uuid, data: $object);
			}
		);

		$visibility = $this->createMock(GraphVisibility::class);
		$visibility->method('isVisible')->willReturnCallback(
			function (string $sourceType, array $sourceRef, string $uid): bool {
				$key = ($sourceType === 'file') ? 'file:' . ($sourceRef['fileId'] ?? '') : (string)($sourceRef['uuid'] ?? '');

				return $uid === 'alice' && in_array($key, $this->readable, true);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($config[$key] ?? $default)
		);

		return new GraphService($objects, $visibility, $appConfig, $this->createMock(LoggerInterface::class));

	}//end graph()

	/**
	 * An ObjectEntity carrying the data.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	protected function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Upsert an entity referencing an object with the given uuid.
	 *
	 * @param GraphService $graph The service.
	 * @param string $label The label.
	 * @param string $record The referenced object uuid.
	 *
	 * @return string The entity uuid.
	 */
	protected function node(GraphService $graph, string $label, string $record): string {
		return $graph->upsertEntity(
			[
				'label' => $label,
				'entityType' => 'person',
				'sourceType' => 'object',
				'sourceRef' => ['register' => 'crm', 'schema' => 'contact', 'uuid' => $record],
				'confidence' => 0.9,
				'extractedBy' => 'test@1',
			]
		);
	}//end node()
}//end trait
