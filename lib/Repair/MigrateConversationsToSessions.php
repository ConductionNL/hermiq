<?php

/**
 * Hermiq Migrate Conversations To Sessions Repair Step.
 *
 * Copies every `conversation` object onto the `agentsession` schema, and every `message`
 * onto `agentsessionturn`, so the app can move to one vocabulary. This is the only spec
 * in the session chain that touches data, and the only one that can lose any.
 *
 * 🔴 COPY SEMANTICS, NOT MOVE. Source objects are never modified and never deleted, so
 * rollback is deleting the copies. `session-api-rename` and `session-frontend-rename`
 * read sessions; until they land, the app still reads conversations and is unaffected by
 * this step having run.
 *
 * 🔴 THE UUID IS PRESERVED, AND THAT IS WHAT KEEPS TURNS ATTACHED. A turn points at its
 * session by uuid. Writing a session under a fresh uuid orphans every turn that belonged
 * to it, silently, and the symptom is an empty thread rather than an error.
 *
 * 🔴 ARCHIVED OBJECTS NEED A DIFFERENT READ PATH, AND THIS IS THE WHOLE REASON THE STEP
 * IS WRITTEN THE WAY IT IS. `ObjectService`'s read excludes soft-deleted rows, and the
 * `_includeDeleted` flag does NOT fix it: measured on the dev instance 2026-09-07, it
 * moved the reported total from 1 to 10 while the result set stayed at 1 row, because the
 * flag reaches the count query only. The table held 10 conversations, 9 of them archived.
 * Reading through `ObjectService` alone would therefore have migrated 1 of 10 and emptied
 * the Archive tab, which is exactly the failure the spec's task 3.4 names.
 * `ObjectEntityMapper::findDeletedAcrossAllMagicTables()` is the path that returns them,
 * and it is what `DeletedController::index()` itself uses.
 *
 * 🔴 IDEMPOTENT BY UUID. A partial failure is re-run, not hand-repaired. An existing
 * session with the same uuid is left alone rather than overwritten, so a re-run cannot
 * undo a later edit made through the UI.
 *
 * ⚠️ A uuid collision with a soft-deleted tombstone is REPORTED, never auto-purged. The
 * `_uuid` unique index carries no `WHERE _deleted IS NULL`, so a tombstone holds its uuid
 * forever; a collision means something unexpected exists and a person should look at it.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Repair\Support\RegisterScopedSchema;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Copies conversation/message objects onto the session/session-turn schemas.
 *
 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A one-shot data migration touches both
 *   worlds by design: OpenRegister's object layer and its mapper for the archived read,
 *   user resolution for the ownership-preserving write, and the repair-step framework
 *   types. Same rationale MigrateAgentData records, and the register-scoped slug lookup
 *   has already been extracted to RegisterScopedSchema rather than suppressed away.
 */
class MigrateConversationsToSessions implements IRepairStep {
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'hermiq';

	/**
	 * Source schema slugs.
	 *
	 * @var string
	 */
	public const CONVERSATION_SCHEMA = 'conversation';

	/**
	 * Source schema slug for a single chat turn.
	 *
	 * @var string
	 */
	public const MESSAGE_SCHEMA = 'message';

	/**
	 * Target schema slugs.
	 *
	 * ⚠️ `agentsession`, NOT `session`. The bare slug resolves to another app's schema
	 * (scholiq's "a scheduled occurrence of a Cohort meeting"), and this register already
	 * prefixes for exactly that reason. See session-schema-declaration/design.md.
	 *
	 * @var string
	 */
	public const SESSION_SCHEMA = 'agentsession';

	/**
	 * Target schema slug for a single turn.
	 *
	 * @var string
	 */
	public const SESSION_TURN_SCHEMA = 'agentsessionturn';

	/**
	 * App-config key recording the outcome, so a later run and an e2e test can read what
	 * happened rather than inferring it from the object counts.
	 *
	 * @var string
	 */
	public const OUTCOME_KEY = 'session_migration';

	/**
	 * App-config key carrying the counts the outcome is based on.
	 *
	 * @var string
	 */
	public const OUTCOME_DETAIL_KEY = 'session_migration_detail';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Server container for lazy OpenRegister
	 *                                        resolution (it may not be installed).
	 * @param \OCP\IAppConfig    $appConfig   Records the outcome breadcrumb.
	 * @param RegisterScopedSchema $schemas   Resolves a schema slug inside hermiq's register.
	 * @param \OCP\IDBConnection $db          Writes the archive marker the object API cannot.
	 * @param LoggerInterface    $logger      PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly \OCP\IAppConfig $appConfig,
		private readonly RegisterScopedSchema $schemas,
		private readonly \OCP\IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec exclude Trivial IRepairStep display-name accessor; no behavioural spec.
	 */
	public function getName(): string {
		return 'Copy hermiq conversations onto the session schema (session-data-migration)';
	}//end getName()

	/**
	 * Copy conversations and messages onto the session schemas.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping session migration.');
			$this->logger->warning('[hermiq] session-data-migration skipped: ' . $e->getMessage());
			$this->recordOutcome(outcome: 'unavailable', detail: $e->getMessage());
			return;
		}

		// An upgrade has no session and OpenRegister refuses the write for 'Anonymous',
		// so without this the migration copies nothing and says so only in a warning,
		// which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->runInner(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * The migration itself, already under a system identity.
	 *
	 * @param ObjectService $objectService OpenRegister's object read/write path.
	 * @param IOutput       $output        Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	private function runInner(ObjectService $objectService, IOutput $output): void {
		$sessions = $this->migrateFamily(
			objectService: $objectService,
			output: $output,
			sourceSchema: self::CONVERSATION_SCHEMA,
			targetSchema: self::SESSION_SCHEMA
		);

		$turns = $this->migrateFamily(
			objectService: $objectService,
			output: $output,
			sourceSchema: self::MESSAGE_SCHEMA,
			targetSchema: self::SESSION_TURN_SCHEMA
		);

		$detail = sprintf(
			'sessions: %d copied, %d already present, %d failed; turns: %d copied, %d already present, %d failed',
			$sessions['copied'],
			$sessions['present'],
			$sessions['failed'],
			$turns['copied'],
			$turns['present'],
			$turns['failed']
		);

		$output->info('Hermiq session migration — ' . $detail);
		$this->logger->info('[hermiq] session-data-migration: ' . $detail);

		$outcome = 'migrated';
		if (($sessions['failed'] + $turns['failed']) > 0) {
			$outcome = 'partial';
		}

		$this->recordOutcome(outcome: $outcome, detail: $detail);
	}//end runInner()

	/**
	 * Copy one source schema onto its target, live rows and archived rows alike.
	 *
	 * @param ObjectService $objectService OpenRegister's object read/write path.
	 * @param IOutput       $output        Repair output channel.
	 * @param string        $sourceSchema  Schema slug to read from.
	 * @param string        $targetSchema  Schema slug to write to.
	 *
	 * @return array{copied: int, present: int, failed: int} The per-family counts.
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	private function migrateFamily(
		ObjectService $objectService,
		IOutput $output,
		string $sourceSchema,
		string $targetSchema
	): array {
		$counts = [
			'copied' => 0,
			'present' => 0,
			'failed' => 0,
		];

		foreach ($this->readSource(objectService: $objectService, schema: $sourceSchema) as $source) {
			$uuid = trim((string) $source->getUuid());
			if ($uuid === '') {
				$counts['failed']++;
				$this->logger->error(
					'[hermiq] session-data-migration: a ' . $sourceSchema . ' row carries no uuid and was skipped.'
				);
				continue;
			}

			if ($this->objectExists(schema: $targetSchema, uuid: $uuid) === true) {
				$counts['present']++;
				continue;
			}

			$written = $this->persist(
				objectService: $objectService,
				sourceSchema: $sourceSchema,
				targetSchema: $targetSchema,
				uuid: $uuid,
				source: $source
			);

			$bucket = 'failed';
			if ($written === true) {
				$bucket = 'copied';
			}

			$counts[$bucket]++;
		}//end foreach

		$output->info(
			sprintf(
				'  %s → %s: %d copied, %d already present, %d failed',
				$sourceSchema,
				$targetSchema,
				$counts['copied'],
				$counts['present'],
				$counts['failed']
			)
		);

		return $counts;
	}//end migrateFamily()

	/**
	 * Every source object of one schema, INCLUDING the soft-deleted ones.
	 *
	 * 🔴 Two reads, deliberately. `ObjectService` returns live rows only, and its
	 * `_includeDeleted` parameter does not change that — measured 2026-09-07, it moved the
	 * reported total from 1 to 10 while the result set stayed at 1 row, because the flag
	 * reaches the count query and not the row query. Nine of ten conversations on that
	 * instance were archived. A migration reading only the first path silently leaves them
	 * behind and empties the Archive tab.
	 *
	 * `findDeletedAcrossAllMagicTables()` is the read that returns them; it is what
	 * `DeletedController::index()` uses. It has no schema filter, so the rows are filtered
	 * here.
	 *
	 * @param ObjectService $objectService OpenRegister's object read path.
	 * @param string        $schema        Source schema slug.
	 *
	 * @return array<int, ObjectEntity> Source objects, live first then archived.
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	private function readSource(ObjectService $objectService, string $schema): array {
		$rows = [];

		try {
			$live = $objectService->setRegister(self::REGISTER_SLUG)->setSchema($schema)
				->searchObjectsPaginated(query: ['_limit' => 10000]);
			foreach (($live['results'] ?? []) as $object) {
				if ($object instanceof ObjectEntity) {
					$rows[(string) $object->getUuid()] = $object;
				}
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'[hermiq] session-data-migration: live read of ' . $schema . ' failed: ' . $e->getMessage()
			);
		}

		try {
			$schemaId = $this->schemas->idFor(registerSlug: self::REGISTER_SLUG, schemaSlug: $schema);
			$mapper = $this->container->get(\OCA\OpenRegister\Db\MagicMapper::class);
			$deleted = $mapper->findDeletedAcrossAllMagicTables(limit: 10000, offset: 0);
			foreach ($deleted as $object) {
				if (($object instanceof ObjectEntity) === false) {
					continue;
				}

				if ($schemaId === null || (string) $object->getSchema() !== $schemaId) {
					continue;
				}

				$rows[(string) $object->getUuid()] = $object;
			}
		} catch (Throwable $e) {
			// Loud, not swallowed: without this read the archived objects are simply
			// absent, and a migration that quietly drops nine tenths of the data is the
			// exact failure this method exists to prevent.
			$this->logger->error(
				'[hermiq] session-data-migration: ARCHIVED read of ' . $schema
				. ' failed, archived objects were NOT migrated: ' . $e->getMessage()
			);
		}//end try

		return array_values($rows);
	}//end readSource()

	/**
	 * Whether a target object already exists under this uuid.
	 *
	 * @param string $schema Target schema slug.
	 * @param string $uuid   The uuid to look for.
	 *
	 * @return bool True when it exists.
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	private function objectExists(string $schema, string $uuid): bool {
		try {
			$registerMapper = $this->container->get(\OCA\OpenRegister\Db\RegisterMapper::class);
			$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
			$mapper = $this->container->get(\OCA\OpenRegister\Db\MagicMapper::class);

			$schemaId = $this->schemas->idFor(registerSlug: self::REGISTER_SLUG, schemaSlug: $schema);
			if ($schemaId === null) {
				return false;
			}

			// 🔴 `includeDeleted: true`, AND THE MAPPER RATHER THAN ObjectService. Two
			// separate traps, both measured on this step:
			//
			//   - `ObjectService::find()` excludes soft-deleted rows, so an ARCHIVED
			//     session reads as absent. A re-run then reported "8 copied, 2 already
			//     present" when all ten existed, and rewrote nine archived objects —
			//     which is exactly the overwrite the idempotency guarantee forbids,
			//     because it would undo an edit made through the UI afterwards.
			//   - passing `schema:` to `ObjectService::find()` does not guarantee the
			//     answer belongs to it: it returned the SOURCE conversation when asked
			//     for a session with the same uuid, so the one live conversation was
			//     reported "already present" and never migrated at all.
			//
			// The mapper, given register and schema explicitly, reads one table.
			$mapper->find(
				identifier: $uuid,
				register: $registerMapper->find(id: self::REGISTER_SLUG, _rbac: false, _multitenancy: false),
				schema: $schemaMapper->find((int) $schemaId),
				includeDeleted: true,
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable) {
			// The mapper throws when it finds nothing, which is the ordinary case on a
			// first run rather than a fault.
			return false;
		}
	}//end objectExists()

	/**
	 * Write one target object, carrying the source's identity and provenance across.
	 *
	 * 🔴 The object is built in FULL. `saveObject()` is PUT-semantic, so a property left
	 * out of the payload is written away rather than left alone.
	 *
	 * @param ObjectService $objectService OpenRegister's object write path.
	 * @param string        $sourceSchema  Source schema slug, for the archive-marker copy.
	 * @param string        $targetSchema  Target schema slug.
	 * @param string        $uuid          The uuid to preserve.
	 * @param ObjectEntity  $source        The source object.
	 *
	 * @return bool True when the write succeeded.
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	private function persist(
		ObjectService $objectService,
		string $sourceSchema,
		string $targetSchema,
		string $uuid,
		ObjectEntity $source
	): bool {
		// `getObject()` is declared `: array`, so no shape guard is needed here.
		$data = $source->getObject();

		// `conversationId` is what a message calls its parent; a turn calls it
		// `sessionId`. Same value, and the uuid is preserved on both sides, so the
		// parent it names still resolves.
		if ($targetSchema === self::SESSION_TURN_SCHEMA && isset($data['conversationId']) === true) {
			$data['sessionId'] = $data['conversationId'];
			unset($data['conversationId']);
		}

		// Every migrated session is a person opening a chat. Automated origins only
		// exist from the moment the run paths start writing sessions themselves.
		if ($targetSchema === self::SESSION_SCHEMA) {
			$data['triggerOrigin'] = 'human';
		}

		$self = $this->buildSelf(source: $source);
		if ($self !== []) {
			$data['@self'] = $self;
		}


		try {
			$objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: $targetSchema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);

			$this->carryProvenance(
				sourceSchema: $sourceSchema,
				targetSchema: $targetSchema,
				uuid: $uuid
			);
			return true;
		} catch (Throwable $e) {
			// A uuid collision with a soft-deleted tombstone lands here. It is reported
			// and never auto-purged: the unique index has no `WHERE _deleted IS NULL`, so
			// a tombstone holds its uuid forever, and a collision means something
			// unexpected exists that a person should look at.
			$this->logger->error(
				'[hermiq] session-data-migration: failed to write ' . $targetSchema . ' ' . $uuid
				. ' (a soft-deleted tombstone may hold this uuid; it is NOT purged automatically): '
				. $e->getMessage()
			);
			return false;
		}//end try
	}//end persist()

	/**
	 * Copy the source row's provenance onto the object just written.
	 *
	 * 🔴 THE WRITE PATH DOES NOT PRESERVE ANY OF IT, and each failure is silent:
	 * `saveObject()` ignores `@self.deleted` so an archived object arrives live, stamps
	 * `_owner` from the acting identity, and stamps `_created`/`_updated` with now. All
	 * three were measured on this step before this method existed.
	 *
	 * @param string $sourceSchema Source schema slug, read for the original values.
	 * @param string $targetSchema Target schema slug, written to.
	 * @param string $uuid         The uuid on both sides.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	private function carryProvenance(string $sourceSchema, string $targetSchema, string $uuid): void {
		try {
			$sourceTable = $this->magicTableFor(schemaSlug: $sourceSchema);
			$targetTable = $this->magicTableFor(schemaSlug: $targetSchema);
			if ($sourceTable === null || $targetTable === null) {
				throw new RuntimeException('a magic table could not be resolved');
			}

			// 🔴 READ FROM THE ROW, NOT FROM THE ENTITY. The entities
			// `findDeletedAcrossAllMagicTables()` returns do NOT carry `deleted` hydrated,
			// even though the query selects `*` — measured twice: the migration reported
			// 10 of 10 copied and every archived object still arrived LIVE, with no error
			// anywhere, because `getDeleted()` came back empty. The columns are the only
			// honest source for this.
			$read = $this->db->getQueryBuilder();
			$read->select('_owner', '_organisation', '_created', '_updated', '_deleted')
				->from($sourceTable)
				->where($read->expr()->eq('_uuid', $read->createNamedParameter($uuid)));
			$result = $read->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			if ($row === false || $row === null) {
				throw new RuntimeException('the source row disappeared between read and write');
			}

			// 🔴 A DIRECT COLUMN WRITE, DELIBERATELY, and this is the one place this step
			// leaves the object API. The spec requires uuid, owner, organisation, created,
			// updated and the archive marker to survive EXACTLY, and the write path does
			// not offer that:
			//
			//   - `saveObject()` ignores `@self.deleted`, so an archived object arrives
			//     live. Measured: nine of ten, silently.
			//   - it stamps `_owner` from the acting identity. Impersonating the owner
			//     around the call was tried and did not take: every row landed as
			//     `__system__` rather than `admin`.
			//   - it stamps `_created`/`_updated` with now, so a thread from March claims
			//     to have been written today.
			//
			// Soft-deleting the copy afterwards would be worse still: it rewrites
			// `deletedAt`/`deletedBy` to today and to this migration, losing when and by
			// whom something was really archived.
			$write = $this->db->getQueryBuilder();
			$write->update($targetTable)
				->set('_owner', $write->createNamedParameter($row['_owner']))
				->set('_organisation', $write->createNamedParameter($row['_organisation']))
				->set('_created', $write->createNamedParameter($row['_created']))
				->set('_updated', $write->createNamedParameter($row['_updated']))
				->set('_deleted', $write->createNamedParameter($row['_deleted']))
				->where($write->expr()->eq('_uuid', $write->createNamedParameter($uuid)));
			$write->executeStatement();
		} catch (Throwable $e) {
			// Loud. An archived object silently arriving live, or history re-dated to
			// today, is the failure this method exists to stop, and neither is visible
			// from the counts alone.
			$this->logger->error(
				'[hermiq] session-data-migration: ' . $uuid . ' was migrated but its PROVENANCE '
				. '(owner/created/updated/archive marker) could not be carried across: ' . $e->getMessage()
			);
		}//end try
	}//end carryProvenance()

	/**
	 * The unprefixed magic-table name backing one schema in hermiq's register.
	 *
	 * @param string $schemaSlug The schema slug.
	 *
	 * @return string|null The table name for the query builder, or null when unresolvable.
	 *
	 * @spec exclude Local table-name lookup for the archive-marker copy; no behavioural spec.
	 */
	private function magicTableFor(string $schemaSlug): ?string {
		try {
			$registerMapper = $this->container->get(\OCA\OpenRegister\Db\RegisterMapper::class);
			$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
			$mapper = $this->container->get(\OCA\OpenRegister\Db\MagicMapper::class);

			// ⚠️ `find()`, not `findBySlug()` — see RegisterScopedSchema for the same trap.
			$register = $registerMapper->find(id: self::REGISTER_SLUG, _rbac: false, _multitenancy: false);
			$schemaId = $this->schemas->idFor(registerSlug: self::REGISTER_SLUG, schemaSlug: $schemaSlug);
			if ($schemaId === null) {
				return null;
			}

			$table = $mapper->getTableNameForRegisterSchema(
				register: $register,
				schema: $schemaMapper->find((int) $schemaId)
			);

			// The query builder adds the instance's own table prefix, so hand it the bare
			// name rather than the `oc_`-prefixed one this accessor returns.
			return preg_replace('/^oc_/', '', $table);
		} catch (Throwable) {
			return null;
		}
	}//end magicTableFor()

	/**
	 * The provenance block that travels with a migrated object.
	 *
	 * 🔴 THE ARCHIVE MARKER IS PART OF IT. Without `deleted`, every archived conversation
	 * arrives as a LIVE session: the chat list fills with threads the user archived and
	 * the Archive tab empties. That is the visible symptom the spec's task 3.4 names, and
	 * it is a data-shape bug rather than a crash, so nothing reports it.
	 *
	 * @param ObjectEntity $source The source object.
	 *
	 * @return array<string, mixed> The `@self` block, or [] when nothing is worth carrying.
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	private function buildSelf(ObjectEntity $source): array {
		$self = [];

		$carry = [
			'organisation' => $this->stringOrNull(value: $source->getOrganisation()),
			'created' => $this->stringOrNull(value: $source->getCreated()),
			'updated' => $this->stringOrNull(value: $source->getUpdated()),
		];

		foreach ($carry as $key => $value) {
			if ($value !== null) {
				$self[$key] = $value;
			}
		}

		// `deleted` is deliberately NOT set here. `saveObject()` ignores it, so putting it
		// on `@self` reads as if the archive state travels when it does not.
		// carryProvenance() writes the columns afterwards.
		return $self;
	}//end buildSelf()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The trimmed string, or null when empty or not stringable.
	 *
	 * @spec exclude Local scalar coercion helper; no behavioural spec.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format('c');
		}

		if (is_scalar($value) === false) {
			return null;
		}

		$string = trim((string) $value);
		if ($string === '') {
			return null;
		}

		return $string;
	}//end stringOrNull()

	/**
	 * Record what the step did, so it can be read back rather than inferred.
	 *
	 * @param string $outcome One of migrated|partial|unavailable.
	 * @param string $detail  The counts, or the failure cause.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
	 */
	private function recordOutcome(string $outcome, string $detail = ''): void {
		try {
			$this->appConfig->setValueString(\OCA\Hermiq\AppInfo\Application::APP_ID, self::OUTCOME_KEY, $outcome);
			$this->appConfig->setValueString(
				\OCA\Hermiq\AppInfo\Application::APP_ID,
				self::OUTCOME_DETAIL_KEY,
				$detail
			);
		} catch (Throwable $e) {
			$this->logger->warning('[hermiq] session-data-migration: could not record outcome: ' . $e->getMessage());
		}
	}//end recordOutcome()
}//end class
