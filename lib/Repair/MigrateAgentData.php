<?php

/**
 * Hermiq Migrate Agent Data Repair Step.
 *
 * Chain-tail of the agent-core-port program (ADR-050 §7): copies OpenRegister's four
 * legacy QBMapper tables — `openregister_agents`, `openregister_conversations`,
 * `openregister_messages`, `openregister_feedback` — into equivalent `Agent`/
 * `Conversation`/`Message`/`Feedback` objects in the `hermiq` OpenRegister register.
 *
 * Behaviour (Decisions, proposal.md — Ruben 2026-07-06):
 *  - Gated on the `hermiq`.`engine.enabled` feature flag: skipped (with a log line)
 *    until an operator has opted into the in-app engine (agent-engine-port).
 *  - Idempotent: each source row's uuid is preserved as the migrated object's identity,
 *    so a second run skips any row whose uuid already exists as a hermiq object.
 *  - Integer FKs (`Conversation.agentId`, `Message.conversationId`,
 *    `Feedback.{messageId,conversationId,agentId}`) are resolved to the referenced row's
 *    uuid via an id→uuid map built in the same pass; a dangling reference is nulled,
 *    logged, and counted — the row is still migrated (never a fatal error).
 *
 *    EXCEPT where the target schema marks the FK REQUIRED. `Conversation.agentId` is
 *    required — a conversation that runs against no agent is not a conversation — so
 *    nulling it produces a row that can only ever fail validation. Those rows are
 *    skipped and reported as unmigratable rather than attempted: the migration used to
 *    emit "failed to write conversation <uuid>: Property 'agentId' should be type
 *    'string' but is 'null'" eight times per repair, an error describing the symptom
 *    from a step that had already decided to carry on.
 *
 *    An unmigratable row is WARNED ABOUT ONCE. The source rows stay in OR's tables
 *    forever (this step never deletes), so without a memory the same warning re-fires on
 *    every `occ upgrade` for the lifetime of the install — a permanent false alarm about
 *    a condition that cannot change. Reported uuids are recorded in the
 *    `agent-data-migration.unmigratable` app-config key; later runs skip them with a
 *    debug log line only. A NEW unmigratable row still warns normally.
 *  - Reads OR's tables directly via IDBConnection (they belong to OR and may be absent on
 *    a fresh install): each table is guarded with a table-exists check and skipped
 *    gracefully. This deviates from tasks.md §1.2 (inject OR mappers) on purpose — a raw
 *    read needs no hard DI on OR mapper classes, so the repair step still constructs when
 *    OpenRegister is not installed, and the table-exists guard handles fresh installs.
 *  - Writes go through OpenRegister's single ObjectService write-path (ADR-001), resolved
 *    lazily so the step no-ops when OpenRegister is absent. Owner is preserved by
 *    impersonating the source row's owner during the save (the same pattern
 *    ScheduleService uses); `owner`/`organisation`/`created` are carried as `@self`
 *    metadata, never as schema properties (agent-engine-schemas).
 *  - `openregister_chat_history` is NOT migrated — confirmed dead code (zero live callers).
 *  - OR's source tables are never dropped or modified by this step.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-data-migration/tasks.md#1-build-the-repair-step
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy OR's legacy agent-engine tables into hermiq-register objects (flag-gated, idempotent).
 *
 * @spec openspec/changes/agent-data-migration/tasks.md#1-build-the-repair-step
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   A one-shot data migration touches
 *   both worlds by design: the legacy DB layer (IDBConnection), OR's object layer,
 *   user resolution and the repair-step framework types.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Sum of one small mapper per
 *   migrated legacy table (agents, versions, runs, schedules) plus idempotency
 *   guards — the complexity tracks the legacy catalogue, not one algorithm.
 */
class MigrateAgentData implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * IAppConfig key (app `hermiq`) gating the migration: 'true' opts the install into the
	 * in-app engine, at which point the legacy OR data must exist as hermiq objects.
	 *
	 * @var string
	 */
	private const ENGINE_FLAG_KEY = 'engine.enabled';

	/**
	 * Read page size for the paginated table copy (volume unknown — see design.md).
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * IAppConfig key (app `hermiq`) holding the JSON array of conversation uuids already
	 * reported as unmigratable, so the warning fires once per row instead of once per
	 * upgrade forever.
	 *
	 * @var string
	 */
	private const UNMIGRATABLE_KEY = 'agent-data-migration.unmigratable';

	/**
	 * Source table → target schema slug map, in FK-dependency order (parents first) so an
	 * id→uuid map is always populated before a child table resolves against it.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_SLUG = [
		'openregister_agents' => 'agent',
		'openregister_conversations' => 'conversation',
		'openregister_messages' => 'message',
		'openregister_feedback' => 'feedback',
	];

	/**
	 * Agent data columns (snake_case DB column → value type). Metadata columns
	 * (`id`/`uuid`/`owner`/`organisation`/`created`/`updated`) are handled separately.
	 *
	 * @var array<string, string>
	 */
	private const AGENT_FIELDS = [
		'name' => 'string',
		'description' => 'string',
		'type' => 'string',
		'provider' => 'string',
		'model' => 'string',
		'prompt' => 'string',
		'temperature' => 'float',
		'max_tokens' => 'int',
		'configuration' => 'json',
		'active' => 'bool',
		'enable_rag' => 'bool',
		'rag_search_mode' => 'string',
		'rag_num_sources' => 'int',
		'rag_include_files' => 'bool',
		'rag_include_objects' => 'bool',
		'request_quota' => 'int',
		'token_quota' => 'int',
		'views' => 'json',
		'search_files' => 'bool',
		'search_objects' => 'bool',
		'is_private' => 'bool',
		'invited_users' => 'json',
		'groups' => 'json',
		'tools' => 'json',
		'user' => 'string',
	];

	/**
	 * Column → schema-property name overrides for columns whose target property does
	 * NOT match the generic snake→camel derivation (`camelCase()`). Currently just the
	 * legacy `user` column (OR's `openregister_agents.user`), which now targets the
	 * renamed `actingUser` Agent property (agent-capability-profile) — the DB column
	 * itself cannot be renamed, so this is the one explicit exception.
	 *
	 * @var array<string, string>
	 */
	private const PROPERTY_OVERRIDES = [
		'user' => 'actingUser',
	];

	/**
	 * Conversation data columns (agentId is resolved from the int FK separately).
	 *
	 * @var array<string, string>
	 */
	private const CONVERSATION_FIELDS = [
		'title' => 'string',
		'user_id' => 'string',
		'metadata' => 'json',
	];

	/**
	 * Message data columns (conversationId is resolved separately; `context` copied verbatim).
	 *
	 * @var array<string, string>
	 */
	private const MESSAGE_FIELDS = [
		'role' => 'string',
		'content' => 'string',
		'sources' => 'json',
		'context' => 'json',
	];

	/**
	 * Feedback data columns (messageId/conversationId/agentId resolved separately).
	 *
	 * @var array<string, string>
	 */
	private const FEEDBACK_FIELDS = [
		'user_id' => 'string',
		'type' => 'string',
		'comment' => 'string',
	];

	/**
	 * Optional integer foreign keys: FK label → [source column, schema slug of the
	 * referenced table], resolved against that table's id → uuid map.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const FOREIGN_KEYS = [
		'Message.conversationId' => ['conversation_id', 'conversation'],
		'Feedback.messageId' => ['message_id', 'message'],
		'Feedback.conversationId' => ['conversation_id', 'conversation'],
		'Feedback.agentId' => ['agent_id', 'agent'],
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Core DB connection — reads OR's source tables directly.
	 * @param IAppConfig $appConfig Reads the `hermiq`.`engine.enabled` feature flag.
	 * @param IUserSession $userSession Impersonates the source owner during each write (owner preservation).
	 * @param IUserManager $userManager Resolves an owner UID to an IUser for impersonation.
	 * @param ContainerInterface $container Server container for lazy ObjectService resolution.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly ContainerInterface $container,
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
		return 'Migrate OpenRegister agent-engine data into hermiq-register objects (agent-data-migration)';
	}//end getName()

	/**
	 * Copy OR's four legacy tables into hermiq objects when the engine flag is on.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#a-repair-step-migrates-existing-or-agent-engine-data-into-hermiq-register-objects
	 */
	public function run(IOutput $output): void {
		if ($this->isEngineEnabled() === false) {
			$output->info('Hermiq engine flag off — skipping agent-data migration (no data copied).');
			$this->logger->info('[hermiq] agent-data-migration skipped: engine.enabled is off');
			return;
		}

		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping agent-data migration.');
			$this->logger->warning('[hermiq] agent-data-migration skipped: ' . $e->getMessage());
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses the write for 'Anonymous'. Without it this migration copies
		// nothing and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->runInner(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * The migration itself.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 */
	private function runInner(object $objectService, IOutput $output): void {
		$run = new AgentDataMigrationRun(objectService: $objectService, output: $output);
		$previouslyReported = $this->loadReportedUnmigratable();
		$run->reportedUnmigratable = $previouslyReported;

		// Tables in FK-dependency order (parents first). The id → uuid maps are built
		// across the whole pass (including already-migrated rows) so a child table's
		// FK resolution works on a re-run / partial migration too.
		$written = [];
		foreach (array_keys(self::SCHEMA_SLUG) as $table) {
			$written[] = $this->migrateTable(run: $run, table: $table);
		}

		$this->storeReportedUnmigratable(run: $run, previouslyReported: $previouslyReported);
		$this->reportSummary(run: $run, written: $written);

	}//end runInner()

	/**
	 * Report the pass's per-table write counts and the dangling FKs it nulled.
	 *
	 * @param AgentDataMigrationRun $run     The finished pass.
	 * @param array<int, int>       $written Objects newly written per table, in SCHEMA_SLUG order.
	 *
	 * @return void
	 */
	private function reportSummary(AgentDataMigrationRun $run, array $written): void {
		$run->output->info(
			vsprintf(
				'agent-data migration complete: %d agents, %d conversations, %d messages, %d feedback new; %d dangling FK(s) nulled.',
				[...$written, $run->danglingCount]
			)
		);

	}//end reportSummary()

	/**
	 * Remember the conversations reported as unmigratable, when the pass reported new ones.
	 *
	 * @param AgentDataMigrationRun $run                The finished pass.
	 * @param array<int, string>    $previouslyReported The uuids reported before this pass.
	 *
	 * @return void
	 */
	private function storeReportedUnmigratable(AgentDataMigrationRun $run, array $previouslyReported): void {
		if ($run->reportedUnmigratable === $previouslyReported) {
			return;
		}

		$encoded = json_encode(array_values($run->reportedUnmigratable));
		if (is_string($encoded) === false) {
			$encoded = '[]';
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::UNMIGRATABLE_KEY, $encoded);

	}//end storeReportedUnmigratable()

	/**
	 * Migrate one OR table into objects of its target schema (uuid + owner/organisation
	 * preserved), skipping rows that are already migrated.
	 *
	 * @param AgentDataMigrationRun $run   The pass.
	 * @param string                $table The OR table (a key of SCHEMA_SLUG).
	 *
	 * @return int The number of objects newly written this run.
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-agent-row-is-migrated-with-its-uuid-preserved
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#re-running-the-repair-step-is-a-no-op-on-migrated-records
	 */
	private function migrateTable(AgentDataMigrationRun $run, string $table): int {
		if ($this->guardTable(table: $table, output: $run->output) === false) {
			return 0;
		}

		$run->schema = self::SCHEMA_SLUG[$table];
		$written = 0;
		foreach ($this->readTable(table: $table) as $row) {
			$written += $this->migrateRow(run: $run, row: $row);
		}

		return $written;

	}//end migrateTable()

	/**
	 * Migrate one source row, recording its id → uuid first.
	 *
	 * @param AgentDataMigrationRun $run The pass.
	 * @param array<string, mixed>  $row The source row.
	 *
	 * @return int 1 when the row was written, 0 when it was skipped.
	 */
	private function migrateRow(AgentDataMigrationRun $run, array $row): int {
		$uuid = $this->rowUuid(row: $row);
		if ($uuid === null) {
			return 0;
		}

		$run->uuids[$run->schema][(int)$row['id']] = $uuid;
		if ($this->objectExists(objectService: $run->objectService, schema: $run->schema, uuid: $uuid) === true) {
			return 0;
		}

		$run->row = $row;
		$run->uuid = $uuid;

		return $this->writeRow(run: $run);

	}//end migrateRow()

	/**
	 * Write the row being migrated, unless it cannot be migrated.
	 *
	 * @param AgentDataMigrationRun $run The pass, at the row being migrated.
	 *
	 * @return int 1 when the row was written, 0 when it was skipped.
	 */
	private function writeRow(AgentDataMigrationRun $run): int {
		$data = $this->rowData(run: $run);
		if ($data === null) {
			return 0;
		}

		$this->persist(objectService: $run->objectService, schema: $run->schema, uuid: $run->uuid, data: $data, row: $run->row);
		return 1;

	}//end writeRow()

	/**
	 * The object payload of the row being migrated, or null when it cannot be migrated.
	 *
	 * @param AgentDataMigrationRun $run The pass, at the row being migrated.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowData(AgentDataMigrationRun $run): ?array {
		return match ($run->schema) {
			'agent' => $this->buildData(row: $run->row, fields: self::AGENT_FIELDS),
			'conversation' => $this->conversationData(run: $run),
			'message' => $this->messageData(run: $run),
			default => $this->feedbackData(run: $run),
		};

	}//end rowData()

	/**
	 * A Conversation payload with agentId resolved from the int FK to the agent's uuid,
	 * or null (reported once) when the agent is missing.
	 *
	 * @param AgentDataMigrationRun $run The pass, at the conversation row.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#conversationagentid-is-resolved-from-an-integer-fk-to-the-agents-uuid
	 */
	private function conversationData(AgentDataMigrationRun $run): ?array {
		$data = $this->buildData(row: $run->row, fields: self::CONVERSATION_FIELDS);
		$data['agentId'] = $this->conversationAgentUuid(run: $run);

		// `agentId` is REQUIRED on the Conversation schema — a conversation
		// that runs against no agent is not a conversation. resolveFk()
		// nulls a dangling FK and says "row still migrated", which is right
		// for an optional reference and impossible for this one: the write
		// can only ever fail validation.
		//
		// It did, eight times per repair, as "failed to write conversation
		// <uuid>: Property 'agentId' should be type 'string' but is 'null'"
		// — an error describing the symptom, from a step that had already
		// decided to continue. Skipping says the true thing: these
		// conversations reference agents that no longer exist, so there is
		// nothing to migrate them ONTO.
		if ($data['agentId'] === null) {
			$this->reportUnmigratable(run: $run);
			return null;
		}

		return $data;

	}//end conversationData()

	/**
	 * The uuid of the conversation's agent, or null when unset or missing.
	 *
	 * Resolved directly instead of via resolveFk(): that helper warns "nulled (row
	 * still migrated)" for a dangling id, which is wrong on both counts here — an
	 * agentless conversation is SKIPPED, and reportUnmigratable() does its own
	 * (warn-once) reporting.
	 *
	 * @param AgentDataMigrationRun $run The pass, at the conversation row.
	 *
	 * @return string|null
	 */
	private function conversationAgentUuid(AgentDataMigrationRun $run): ?string {
		$agentSourceId = $this->intOrNull(value: ($run->row['agent_id'] ?? null));
		if ($agentSourceId === null || $agentSourceId === 0) {
			return null;
		}

		return ($run->uuids['agent'][$agentSourceId] ?? null);

	}//end conversationAgentUuid()

	/**
	 * Report a conversation that references a missing agent — ONCE per row: the
	 * source row can never become migratable, so re-warning on every later upgrade
	 * is a permanent false alarm.
	 *
	 * @param AgentDataMigrationRun $run The pass, at the conversation row.
	 *
	 * @return void
	 */
	private function reportUnmigratable(AgentDataMigrationRun $run): void {
		$uuid = $run->uuid;
		if (in_array($uuid, $run->reportedUnmigratable, true) === true) {
			$this->logger->debug(
				'[hermiq] agent-data-migration: conversation ' . $uuid
				. ' still references a missing agent — already reported, skipped quietly.'
			);
			return;
		}

		$message = sprintf('Conversation %s references a missing agent; not migrated (a Conversation requires an agent).', $uuid);
		$run->output->warning('hermiq: ' . $message);
		$this->logger->warning('[hermiq] agent-data-migration: ' . $message);
		$run->reportedUnmigratable[] = $uuid;

	}//end reportUnmigratable()

	/**
	 * A Message payload with conversationId resolved; `context` is copied verbatim.
	 *
	 * @param AgentDataMigrationRun $run The pass, at the message row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#a-message-with-an-ai-chat-companion-context-snapshot-round-trips-unchanged
	 */
	private function messageData(AgentDataMigrationRun $run): array {
		$data = $this->buildData(row: $run->row, fields: self::MESSAGE_FIELDS);
		$data['conversationId'] = $this->resolveFk(run: $run, label: 'Message.conversationId');

		return $data;

	}//end messageData()

	/**
	 * A Feedback payload with all three int FKs resolved.
	 *
	 * @param AgentDataMigrationRun $run The pass, at the feedback row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#integer-foreign-keys-are-resolved-to-uuid-references-during-migration
	 */
	private function feedbackData(AgentDataMigrationRun $run): array {
		$data = $this->buildData(row: $run->row, fields: self::FEEDBACK_FIELDS);
		$data['messageId'] = $this->resolveFk(run: $run, label: 'Feedback.messageId');
		$data['conversationId'] = $this->resolveFk(run: $run, label: 'Feedback.conversationId');
		$data['agentId'] = $this->resolveFk(run: $run, label: 'Feedback.agentId');

		return $data;

	}//end feedbackData()

	/**
	 * Resolve an integer foreign key of the row being migrated to the referenced row's uuid.
	 *
	 * A null/zero source id means "no reference" (returns null, not counted). A non-zero id
	 * that is absent from the map is a dangling reference: it is logged, counted, and nulled,
	 * but the owning row is still migrated (Decisions — Ruben 2026-07-06).
	 *
	 * @param AgentDataMigrationRun $run   The pass, at the row being migrated.
	 * @param string                $label Human label of the FK field (a key of FOREIGN_KEYS).
	 *
	 * @return string|null The resolved uuid, or null when unset/dangling.
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-unresolvable-foreign-key-is-skipped-not-fatal
	 */
	private function resolveFk(AgentDataMigrationRun $run, string $label): ?string {
		[$column, $schema] = self::FOREIGN_KEYS[$label];
		$sourceId = $this->intOrNull(value: ($run->row[$column] ?? null));
		if ($sourceId === null || $sourceId === 0) {
			return null;
		}

		$resolved = ($run->uuids[$schema][$sourceId] ?? null);
		if ($resolved === null) {
			$this->reportDanglingFk(run: $run, reference: $label . '=' . $sourceId);
		}

		return $resolved;
	}//end resolveFk()

	/**
	 * Count, log and report a dangling foreign key of the row being migrated.
	 *
	 * @param AgentDataMigrationRun $run       The pass, at the row being migrated.
	 * @param string                $reference The FK label and its source id (`Label=id`).
	 *
	 * @return void
	 */
	private function reportDanglingFk(AgentDataMigrationRun $run, string $reference): void {
		$run->danglingCount++;
		$message = sprintf('Dangling %s on %s — nulled (row still migrated).', $reference, $run->uuid);
		$run->output->warning($message);
		$this->logger->warning('[hermiq] agent-data-migration: ' . $message);

	}//end reportDanglingFk()

	/**
	 * Persist one migrated object through ObjectService, preserving uuid, owner, organisation.
	 *
	 * The source `owner` is preserved by impersonating that user during the save (the same
	 * IUserSession pattern ScheduleService uses); `organisation`/`created` ride along as
	 * `@self` metadata. Normalises before the single write and never re-reads afterwards
	 * (roundtrip-save gotcha). RBAC/tenancy are bypassed — this is a system data copy.
	 *
	 * @param ObjectService $objectService The OpenRegister object write-path.
	 * @param string $schema The target schema slug.
	 * @param string $uuid The source uuid, preserved as the object identity.
	 * @param array<string, mixed> $data The mapped object payload.
	 * @param array<string, mixed> $row The source DB row (for owner/organisation/created).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-agent-row-is-migrated-with-its-uuid-preserved
	 */
	private function persist(ObjectService $objectService, string $schema, string $uuid, array $data, array $row): void {
		$self = [];
		$organisation = $this->stringOrNull(value: ($row['organisation'] ?? null));
		if ($organisation !== null) {
			$self['organisation'] = $organisation;
		}

		$created = $this->stringOrNull(value: ($row['created'] ?? null));
		if ($created !== null) {
			$self['created'] = $created;
		}

		if ($self !== []) {
			$data['@self'] = $self;
		}

		$owner = $this->stringOrNull(value: ($row['owner'] ?? null));
		$user = null;
		if ($owner !== null) {
			$user = $this->userManager->get($owner);
			if ($user === null) {
				$this->logger->warning(
					'[hermiq] agent-data-migration: owner "' . $owner . '" for ' . $schema . ' ' . $uuid
					. ' no longer exists — saved under the system identity.'
				);
			}
		}

		$priorUser = $this->userSession->getUser();
		if ($user instanceof IUser) {
			$this->userSession->setUser($user);
		}

		try {
			$objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[hermiq] agent-data-migration: failed to write ' . $schema . ' ' . $uuid . ': ' . $e->getMessage()
			);
		} finally {
			if ($user instanceof IUser) {
				$this->userSession->setUser($priorUser);
			}
		}//end try

	}//end persist()

	/**
	 * Whether a hermiq object with this uuid already exists (idempotency check).
	 *
	 * @param ObjectService $objectService The OpenRegister object read-path.
	 * @param string $schema The target schema slug.
	 * @param string $uuid The uuid to probe.
	 *
	 * @return bool True when the object is already present.
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#re-running-the-repair-step-is-a-no-op-on-migrated-records
	 */
	private function objectExists(ObjectService $objectService, string $schema, string $uuid): bool {
		try {
			$found = $objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return false;
		}

		return $found instanceof ObjectEntity;
	}//end objectExists()

	/**
	 * Read a whole OR table page by page (design.md — never load a full table into memory).
	 *
	 * @param string $table The (unprefixed) OR table name.
	 *
	 * @return iterable<int, array<string, mixed>> The source rows, oldest id first.
	 */
	private function readTable(string $table): iterable {
		$offset = 0;
		while (true) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($table)
				->orderBy('id', 'ASC')
				->setFirstResult($offset)
				->setMaxResults(self::PAGE_SIZE);

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();

			foreach ($rows as $row) {
				yield $row;
			}

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}

			$offset += self::PAGE_SIZE;
		}//end while

	}//end readTable()

	/**
	 * Skip a table gracefully when it does not exist (fresh install with no OR chat data).
	 *
	 * @param string $table The (unprefixed) OR table name.
	 * @param IOutput $output Repair output channel.
	 *
	 * @return bool True when the table exists and should be read.
	 */
	private function guardTable(string $table, IOutput $output): bool {
		try {
			$exists = $this->db->tableExists($table);
		} catch (Throwable $e) {
			$output->warning('Could not probe ' . $table . ' — skipping (' . $e->getMessage() . ').');
			return false;
		}

		if ($exists === false) {
			$output->info('Table ' . $table . ' absent — nothing to migrate for ' . self::SCHEMA_SLUG[$table] . '.');
			return false;
		}

		return true;
	}//end guardTable()

	/**
	 * Build the schema-property payload from a source row via a column → type map.
	 *
	 * Null columns are omitted (so schema defaults apply); JSON columns are decoded so the
	 * migrated object holds the structured value, not an escaped string.
	 *
	 * @param array<string, mixed> $row The source DB row.
	 * @param array<string, string> $fields Column → type map (string|int|float|bool|json).
	 *
	 * @return array<string, mixed> The mapped object payload keyed by schema property
	 *                              (camelCase derivation, or an explicit
	 *                              `PROPERTY_OVERRIDES` entry when the target property
	 *                              name diverges from the column name).
	 */
	private function buildData(array $row, array $fields): array {
		$data = [];
		foreach ($fields as $column => $type) {
			if (array_key_exists($column, $row) === false) {
				continue;
			}

			$value = $this->convertValue(value: $row[$column], type: $type);
			if ($value === null) {
				continue;
			}

			$property = (self::PROPERTY_OVERRIDES[$column] ?? $this->camelCase(column: $column));
			$data[$property] = $value;
		}

		return $data;
	}//end buildData()

	/**
	 * Convert a raw DB value to the schema-declared type (null-safe).
	 *
	 * @param mixed $value The raw DB value.
	 * @param string $type One of string|int|float|bool|json.
	 *
	 * @return mixed The converted value, or null when the source is null / undecodable JSON.
	 */
	private function convertValue(mixed $value, string $type): mixed {
		if ($value === null) {
			return null;
		}

		return match ($type) {
			'int' => (int)$value,
			'float' => (float)$value,
			'bool' => (bool)$value,
			'json' => $this->decodeJson(value: $value),
			default => (string)$value,
		};

	}//end convertValue()

	/**
	 * Decode a JSON column value to its structured form.
	 *
	 * @param mixed $value The raw column value (JSON string, or already-decoded array).
	 *
	 * @return mixed The decoded array/scalar, or null when empty/undecodable.
	 */
	private function decodeJson(mixed $value): mixed {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return json_decode($value, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

	}//end decodeJson()

	/**
	 * The preserved uuid of a source row, or null when absent (row skipped).
	 *
	 * @param array<string, mixed> $row The source DB row.
	 *
	 * @return string|null The uuid, or null when the row has none.
	 */
	private function rowUuid(array $row): ?string {
		return $this->stringOrNull(value: ($row['uuid'] ?? null));
	}//end rowUuid()

	/**
	 * Normalise a raw value to a non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The trimmed non-empty string, or null.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$string = (string)$value;
		if ($string === '') {
			return null;
		}

		return $string;
	}//end stringOrNull()

	/**
	 * Normalise a raw value to an integer, or null when unset/empty.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return int|null The integer value, or null.
	 */
	private function intOrNull(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * Convert a snake_case DB column to its camelCase schema-property name.
	 *
	 * @param string $column The snake_case column name.
	 *
	 * @return string The camelCase property name.
	 */
	private function camelCase(string $column): string {
		return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
	}//end camelCase()

	/**
	 * The conversation uuids already reported as unmigratable in earlier runs.
	 *
	 * Stored as a JSON array in the `agent-data-migration.unmigratable` app-config key.
	 * Anything unreadable (missing key, invalid JSON, non-array, non-string entries)
	 * degrades to "not reported yet" — the worst case is one repeated warning, never a
	 * silently swallowed one.
	 *
	 * @return array<int, string> The previously reported uuids.
	 */
	private function loadReportedUnmigratable(): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, self::UNMIGRATABLE_KEY, '[]');
		if ($raw === '') {
			return [];
		}

		try {
			$decoded = json_decode($raw, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}

		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_string'));
	}//end loadReportedUnmigratable()

	/**
	 * Whether the in-app agent engine feature flag (`hermiq`.`engine.enabled`) is on.
	 *
	 * @return bool True when the migration must run.
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#a-repair-step-migrates-existing-or-agent-engine-data-into-hermiq-register-objects
	 */
	private function isEngineEnabled(): bool {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, self::ENGINE_FLAG_KEY, 'false') === 'true';
	}//end isEngineEnabled()
}//end class
