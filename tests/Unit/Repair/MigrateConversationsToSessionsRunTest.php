<?php

/**
 * Unit tests for the MigrateConversationsToSessions repair step's run path.
 *
 * Covers the availability guards, the per-family copy counts (missing uuid,
 * already present, copied, failed write), the field renames applied on the way
 * across, the provenance column write and the recorded outcome.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use DateTime;
use OCA\Hermiq\Repair\MigrateConversationsToSessions;
use OCA\Hermiq\Repair\Support\RegisterScopedSchema;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateConversationsToSessions::run().
 *
 * @covers \OCA\Hermiq\Repair\MigrateConversationsToSessions
 * @uses   \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity
 * @uses   \OCA\Hermiq\Repair\Migration\FamilyMigration
 * @uses   \OCA\Hermiq\Repair\Migration\MigratedConversation
 * @uses   \OCA\Hermiq\Repair\Migration\ProvenanceTarget
 */
class MigrateConversationsToSessionsRunTest extends TestCase {

	/**
	 * Schema slug → schema id, as RegisterScopedSchema would resolve it.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_IDS = [
		'conversation'     => '11',
		'message'          => '12',
		'agentsession'     => '21',
		'agentsessionturn' => '22',
	];

	/**
	 * Messages written to the logger, by level.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $logged = [
		'error'   => [],
		'warning' => [],
		'info'    => [],
	];

	/**
	 * Messages written to the repair output, by level.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $printed = [
		'info'    => [],
		'warning' => [],
	];

	/**
	 * App config values written, key → value.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Every executed column update: table, uuid and the column values set.
	 *
	 * @var array<int, array{table: string, uuid: mixed, set: array<string, mixed>}>
	 */
	private array $updates = [];

	/**
	 * Source rows the provenance read returns, keyed "table:uuid".
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sourceRows = [];

	/**
	 * A source object as the live read returns it.
	 *
	 * @param string|null          $uuid The object uuid.
	 * @param array<string, mixed> $data The object body.
	 *
	 * @return ObjectEntity
	 */
	private function object(?string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end object()

	/**
	 * An ObjectService double that serves live rows per schema and records saves.
	 *
	 * @param array<string, array<int, ObjectEntity>> $live      Schema slug → live rows.
	 * @param array<int, string>                      $failUuids Uuids whose save throws.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $live, array $failUuids = []): ObjectService {
		return new class($live, $failUuids) extends ObjectService {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			public int $systemRuns = 0;

			private ?string $schema = null;

			/**
			 * @param array<string, array<int, ObjectEntity>> $live      Live rows.
			 * @param array<int, string>                      $failUuids Failing uuids.
			 */
			public function __construct(private array $live, private array $failUuids) {
			}

			public function runAsSystem(callable $operation): mixed {
				$this->systemRuns++;
				return $operation();
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string) $schema;
				return $this;
			}

			public function searchObjectsPaginated(
				array $query = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $deleted = false,
				?array $ids = null,
				?string $uses = null,
				?array $views = null,
			): array {
				return ['results' => ($this->live[$this->schema] ?? [])];
			}

			public function saveObject(
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?\OCP\IUser $currentUser = null,
				bool $failIfExists = false,
				bool $_unowned = false,
				bool $_dedupOverride = false,
				?string $_owner = null,
			): ObjectEntity {
				if (in_array($uuid, $this->failUuids, true) === true) {
					throw new RuntimeException('duplicate uuid');
				}

				$this->saved[] = [
					'object'   => $object,
					'register' => $register,
					'schema'   => $schema,
					'uuid'     => $uuid,
					'rbac'     => $_rbac,
					'mt'       => $_multitenancy,
				];
				return new ObjectEntity();
			}
		};
	}//end objectService()

	/**
	 * A query builder double that records the update it executes and answers
	 * the provenance read from $this->sourceRows.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$state = new \stdClass();
		$state->table = '';
		$state->set = [];
		$state->params = [];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			static function (mixed $column, mixed $value) use ($state): string {
				$state->uuid = $value;
				return 'eq';
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('where')->willReturnSelf();
		$qb->method('from')->willReturnCallback(
			static function (string $table) use ($qb, $state): IQueryBuilder {
				$state->table = $table;
				return $qb;
			}
		);
		$qb->method('update')->willReturnCallback(
			static function (string $table) use ($qb, $state): IQueryBuilder {
				$state->table = $table;
				return $qb;
			}
		);
		$qb->method('set')->willReturnCallback(
			static function (string $column, mixed $value) use ($qb, $state): IQueryBuilder {
				$state->set[$column] = $value;
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($state): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn($this->sourceRows[$state->table . ':' . $state->uuid] ?? false);
				return $result;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($state): int {
				$this->updates[] = ['table' => $state->table, 'uuid' => $state->uuid, 'set' => $state->set];
				return 1;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * Build the repair step.
	 *
	 * @param bool          $installed     Whether OpenRegister is installed.
	 * @param mixed         $objectService The ObjectService, or a Throwable the container throws.
	 * @param array<string> $present       "schemaId:uuid" pairs the target mapper finds.
	 * @param bool          $configFails   Whether writing the outcome throws.
	 * @param bool          $tablesResolve Whether the magic tables resolve.
	 *
	 * @return MigrateConversationsToSessions
	 */
	private function repair(
		bool $installed,
		mixed $objectService,
		array $present = [],
		bool $configFails = false,
		bool $tablesResolve = true
	): MigrateConversationsToSessions {
		$magic = new class($present, $tablesResolve) {
			/**
			 * @param array<string> $present       Present "schemaId:uuid" pairs.
			 * @param bool          $tablesResolve Whether table lookups succeed.
			 */
			public function __construct(private array $present, private bool $tablesResolve) {
			}

			public function findDeletedAcrossAllMagicTables(int $limit, int $offset): array {
				return [];
			}

			public function find(
				string|int $identifier,
				?Register $register = null,
				?Schema $schema = null,
				bool $includeDeleted = false,
				bool $_rbac = true,
				bool $_multitenancy = true
			): ObjectEntity {
				if ($includeDeleted === true && in_array($schema?->getId() . ':' . $identifier, $this->present, true) === true) {
					return new ObjectEntity();
				}

				throw new RuntimeException('not found');
			}

			public function getTableNameForRegisterSchema(Register $register, Schema $schema): string {
				if ($this->tablesResolve === false) {
					throw new RuntimeException('no table');
				}

				return 'oc_openregister_table_' . $schema->getId();
			}
		};

		$schemaMapper = new class extends SchemaMapper {
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema {
				$schema = new Schema();
				$schema->setId((int) $id);
				return $schema;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $magic, $schemaMapper): mixed {
				if ($id === ObjectService::class) {
					if ($objectService instanceof \Throwable) {
						throw $objectService;
					}

					return $objectService;
				}

				return match ($id) {
					MagicMapper::class    => $magic,
					SchemaMapper::class   => $schemaMapper,
					RegisterMapper::class => new RegisterMapper(),
				};
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('openregister')->willReturn($installed);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use ($configFails): bool {
				if ($configFails === true) {
					throw new RuntimeException('config locked');
				}

				$this->config[$app . '.' . $key] = $value;
				return true;
			}
		);

		$schemas = $this->createMock(RegisterScopedSchema::class);
		$schemas->method('idFor')->willReturnCallback(
			static fn (string $registerSlug, string $schemaSlug): ?string => self::SCHEMA_IDS[$schemaSlug] ?? null
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[$level][] = $message;
				}
			);
		}

		return new MigrateConversationsToSessions($container, $appManager, $appConfig, $schemas, $db, $logger);
	}//end repair()

	/**
	 * A repair output double recording info and warning lines.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->printed[$level][] = $message;
				}
			);
		}

		return $output;
	}//end repairOutput()

	/**
	 * Without OpenRegister the step records "unavailable" and touches nothing else.
	 *
	 * @return void
	 */
	public function testMissingOpenRegisterRecordsUnavailable(): void {
		$service = $this->objectService(['conversation' => [$this->object('a', [])]]);
		$repair = $this->repair(installed: false, objectService: $service);

		$repair->run($this->repairOutput());

		$this->assertSame(['OpenRegister is not installed. Skipping the session migration.'], $this->printed['warning']);
		$this->assertSame(
			[
				'hermiq.session_migration'        => 'unavailable',
				'hermiq.session_migration_detail' => 'openregister is not installed',
			],
			$this->config
		);
		$this->assertSame(0, $service->systemRuns);
		$this->assertSame([], $service->saved);
	}//end testMissingOpenRegisterRecordsUnavailable()

	/**
	 * When the ObjectService cannot be resolved the reason is logged and recorded.
	 *
	 * @return void
	 */
	public function testUnresolvableObjectServiceRecordsTheReason(): void {
		$repair = $this->repair(installed: true, objectService: new RuntimeException('container down'));

		$repair->run($this->repairOutput());

		$this->assertSame(['OpenRegister not available — skipping session migration.'], $this->printed['warning']);
		$this->assertSame(['[hermiq] session-data-migration skipped: container down'], $this->logged['warning']);
		$this->assertSame(
			[
				'hermiq.session_migration'        => 'unavailable',
				'hermiq.session_migration_detail' => 'container down',
			],
			$this->config
		);
	}//end testUnresolvableObjectServiceRecordsTheReason()

	/**
	 * Each family is counted per row: no uuid fails, an existing target is left
	 * alone, a failed write fails, the rest is copied; any failure makes the
	 * outcome partial.
	 *
	 * @return void
	 */
	public function testRunCountsEachFamilyAndRecordsAPartialOutcome(): void {
		$created = new DateTime('2026-03-01T10:00:00+00:00');
		$conversation = $this->object('c-new', ['title' => 'Hello', 'triggerOrigin' => 'schedule']);
		$conversation->setOrganisation(' org-1 ');
		$conversation->setCreated($created);

		$service = $this->objectService(
			[
				'conversation' => [
					$conversation,
					$this->object('c-old', ['title' => 'Old']),
					$this->object('   ', ['title' => 'Blank']),
				],
				'message'      => [
					$this->object('m-1', ['conversationId' => 'c-new', 'content' => 'Hi']),
					$this->object('m-2', ['content' => 'Broken']),
				],
			],
			['m-2']
		);
		$this->sourceRows = [
			'openregister_table_11:c-new' => [
				'_owner'        => 'alice',
				'_organisation' => 'org-1',
				'_created'      => '2026-03-01 10:00:00',
				'_updated'      => '2026-03-02 10:00:00',
				'_deleted'      => '{"deletedBy":"bob"}',
			],
			'openregister_table_12:m-1'   => [
				'_owner'        => 'alice',
				'_organisation' => null,
				'_created'      => '2026-03-01 10:01:00',
				'_updated'      => '2026-03-01 10:01:00',
				'_deleted'      => null,
			],
		];

		$repair = $this->repair(installed: true, objectService: $service, present: ['21:c-old']);
		$repair->run($this->repairOutput());

		$this->assertSame(1, $service->systemRuns);
		$this->assertCount(2, $service->saved);

		$this->assertSame(
			[
				'object'   => [
					'id'            => 'c-new',
					'title'         => 'Hello',
					'triggerOrigin' => 'human',
					'@self'         => [
						'organisation' => 'org-1',
						'created'      => '2026-03-01T10:00:00+00:00',
					],
				],
				'register' => 'hermiq',
				'schema'   => 'agentsession',
				'uuid'     => 'c-new',
				'rbac'     => false,
				'mt'       => false,
			],
			$service->saved[0]
		);
		$this->assertSame(
			['id' => 'm-1', 'content' => 'Hi', 'sessionId' => 'c-new'],
			$service->saved[1]['object']
		);
		$this->assertSame('agentsessionturn', $service->saved[1]['schema']);

		$this->assertSame(
			[
				[
					'table' => 'openregister_table_21',
					'uuid'  => 'c-new',
					'set'   => [
						'_owner'        => 'alice',
						'_organisation' => 'org-1',
						'_created'      => '2026-03-01 10:00:00',
						'_updated'      => '2026-03-02 10:00:00',
						'_deleted'      => '{"deletedBy":"bob"}',
					],
				],
				[
					'table' => 'openregister_table_22',
					'uuid'  => 'm-1',
					'set'   => [
						'_owner'        => 'alice',
						'_organisation' => null,
						'_created'      => '2026-03-01 10:01:00',
						'_updated'      => '2026-03-01 10:01:00',
						'_deleted'      => null,
					],
				],
			],
			$this->updates
		);

		$detail = 'sessions: 1 copied, 1 already present, 1 failed; turns: 1 copied, 0 already present, 1 failed';
		$this->assertSame(
			[
				'  conversation → agentsession: 1 copied, 1 already present, 1 failed',
				'  message → agentsessionturn: 1 copied, 0 already present, 1 failed',
				'Hermiq session migration — ' . $detail,
			],
			$this->printed['info']
		);
		$this->assertSame(['[hermiq] session-data-migration: ' . $detail], $this->logged['info']);
		$this->assertSame(
			[
				'[hermiq] session-data-migration: a conversation row carries no uuid and was skipped.',
				'[hermiq] session-data-migration: failed to write agentsessionturn m-2 (a soft-deleted tombstone may hold this uuid; it is NOT purged automatically): duplicate uuid',
			],
			$this->logged['error']
		);
		$this->assertSame(
			[
				'hermiq.session_migration'        => 'partial',
				'hermiq.session_migration_detail' => $detail,
			],
			$this->config
		);
	}//end testRunCountsEachFamilyAndRecordsAPartialOutcome()

	/**
	 * A clean run records "migrated"; provenance that cannot be carried is
	 * logged per object but still counts the object as copied.
	 *
	 * @return void
	 */
	public function testUnresolvableTablesLeaveTheCopyButLogTheLostProvenance(): void {
		$service = $this->objectService(['conversation' => [$this->object('c-1', ['title' => 'One'])]]);
		$repair = $this->repair(installed: true, objectService: $service, tablesResolve: false);

		$repair->run($this->repairOutput());

		$this->assertCount(1, $service->saved);
		$this->assertSame([], $this->updates);
		$this->assertSame(
			[
				'[hermiq] session-data-migration: c-1 was migrated but its PROVENANCE (owner/created/updated/archive marker) could not be carried across: a magic table could not be resolved',
			],
			$this->logged['error']
		);
		$this->assertSame('migrated', $this->config['hermiq.session_migration']);
		$this->assertSame(
			'sessions: 1 copied, 0 already present, 0 failed; turns: 0 copied, 0 already present, 0 failed',
			$this->config['hermiq.session_migration_detail']
		);
	}//end testUnresolvableTablesLeaveTheCopyButLogTheLostProvenance()

	/**
	 * A source row that vanished before the provenance read is reported, and no
	 * column update is issued for it.
	 *
	 * @return void
	 */
	public function testAVanishedSourceRowIsReportedWithoutAnUpdate(): void {
		$service = $this->objectService(['message' => [$this->object('m-9', ['content' => 'x'])]]);
		$repair = $this->repair(installed: true, objectService: $service);

		$repair->run($this->repairOutput());

		$this->assertSame([], $this->updates);
		$this->assertSame(
			[
				'[hermiq] session-data-migration: m-9 was migrated but its PROVENANCE (owner/created/updated/archive marker) could not be carried across: the source row disappeared between read and write',
			],
			$this->logged['error']
		);
	}//end testAVanishedSourceRowIsReportedWithoutAnUpdate()

	/**
	 * A failure to write the outcome is a warning, never a failed upgrade.
	 *
	 * @return void
	 */
	public function testAnOutcomeThatCannotBeStoredIsOnlyAWarning(): void {
		$repair = $this->repair(installed: false, objectService: null, configFails: true);

		$repair->run($this->repairOutput());

		$this->assertSame(
			['[hermiq] session-data-migration: could not record outcome: config locked'],
			$this->logged['warning']
		);
		$this->assertSame([], $this->config);
	}//end testAnOutcomeThatCannotBeStoredIsOnlyAWarning()
}//end class
