<?php

/**
 * Unit tests for the MigrateAgentData repair step (agent-data-migration).
 *
 * Drives the repair step against a fake IDBConnection returning canned OR rows and a mocked
 * ObjectService, asserting the behavioural contract without a live server: int-FK → uuid
 * resolution, idempotent skip by preserved uuid, dangling-FK null + count, engine-flag-off
 * skip, and missing-table skip. Owner preservation (impersonation) and byte-for-byte
 * `Message.context` round-trip are covered too.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-data-migration/tasks.md#7-idempotency-and-verification
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\MigrateAgentData;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-data-migration repair step.
 *
 * @spec openspec/changes/agent-data-migration/tasks.md#7-idempotency-and-verification
 */
class MigrateAgentDataTest extends TestCase {

	/**
	 * Captured saveObject() calls: list of [schema, uuid, data].
	 *
	 * @var array<int, array{schema: string, uuid: string, data: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Captured output->info() lines.
	 *
	 * @var array<int, string>
	 */
	private array $infos = [];

	/**
	 * Warning lines emitted by the repair step.
	 *
	 * Captured because a SKIPPED record is only visible as a warning: the spec
	 * requires the step to "log the skipped record and continue", so a test that
	 * watches only `info` cannot tell a reported skip from a silent drop.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * uuids that objectExists() must report as already present (idempotency probe).
	 *
	 * @var array<int, string>
	 */
	private array $existing = [];

	/**
	 * setUser() calls that carried a non-null user, by UID (impersonation trace).
	 *
	 * @var array<int, string>
	 */
	private array $impersonated = [];

	/**
	 * Raw JSON the `agent-data-migration.unmigratable` app-config key returns (warn-once memory).
	 *
	 * @var string
	 */
	private string $storedUnmigratable = '[]';

	/**
	 * Values the step persisted back into the unmigratable app-config key, in call order.
	 *
	 * @var array<int, string>
	 */
	private array $persistedUnmigratable = [];

	/**
	 * Reset captured state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->infos = [];
		$this->warnings = [];
		$this->existing = [];
		$this->impersonated = [];
		$this->storedUnmigratable = '[]';
		$this->persistedUnmigratable = [];

	}//end setUp()

	/**
	 * The engine flag off means nothing is read or written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#a-repair-step-migrates-existing-or-agent-engine-data-into-hermiq-register-objects
	 */
	public function testEngineFlagOffSkipsEntirely(): void {
		$db = $this->makeDb(tables: ['openregister_agents' => [$this->agentRow(1, 'agent-uuid-1', 'alice')]]);
		$subject = $this->makeSubject(db: $db, flag: 'false');

		$subject->run($this->makeOutput());

		$this->assertSame([], $this->saved, 'No object may be written when the engine flag is off.');
		$this->assertNotEmpty($this->infos);
		$this->assertStringContainsString('engine flag off', $this->infos[0]);

	}//end testEngineFlagOffSkipsEntirely()

	/**
	 * A source Agent row is migrated with its uuid preserved and its owner impersonated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-agent-row-is-migrated-with-its-uuid-preserved
	 */
	public function testAgentMigratedWithUuidAndOwnerPreserved(): void {
		$db = $this->makeDb(tables: ['openregister_agents' => [$this->agentRow(1, 'agent-uuid-1', 'alice')]]);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$agent = $this->savedFor(schema: 'agent');
		$this->assertCount(1, $agent);
		$this->assertSame('agent-uuid-1', $agent[0]['uuid'], 'The source uuid must be preserved as the object identity.');
		$this->assertSame('Permit assistant', $agent[0]['data']['name']);
		$this->assertSame('org-x', $agent[0]['data']['@self']['organisation'], 'organisation rides in @self metadata.');
		$this->assertContains('alice', $this->impersonated, 'The source owner must be impersonated during the write.');

	}//end testAgentMigratedWithUuidAndOwnerPreserved()

	/**
	 * The legacy `user` DB column retargets onto the renamed `actingUser` schema
	 * property (agent-capability-profile) — the generic snake→camel derivation would
	 * otherwise still write `user`, which no longer exists on the Agent schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-profile/tasks.md#task-2-1
	 */
	public function testLegacyUserColumnMapsToActingUserProperty(): void {
		$row = $this->agentRow(1, 'agent-uuid-1', 'alice');
		$row['user'] = 'svc-bot';
		$db = $this->makeDb(tables: ['openregister_agents' => [$row]]);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$agent = $this->savedFor(schema: 'agent');
		$this->assertCount(1, $agent);
		$this->assertSame(
			'svc-bot',
			$agent[0]['data']['actingUser'],
			'The user column must land on the actingUser property, not user.'
		);
		$this->assertArrayNotHasKey(
			'user',
			$agent[0]['data'],
			'The renamed property must not ALSO be written under its old name.'
		);

	}//end testLegacyUserColumnMapsToActingUserProperty()

	/**
	 * Conversation.agentId resolves from the integer FK to the Agent's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#conversationagentid-is-resolved-from-an-integer-fk-to-the-agents-uuid
	 */
	public function testConversationAgentIdResolvedToUuid(): void {
		$db = $this->makeDb(
			tables: [
				'openregister_agents' => [$this->agentRow(7, 'agent-uuid-7', 'alice')],
				'openregister_conversations' => [$this->conversationRow(42, 'conv-uuid-42', 7, 'alice')],
			]
		);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$conv = $this->savedFor(schema: 'conversation');
		$this->assertCount(1, $conv);
		$this->assertSame('agent-uuid-7', $conv[0]['data']['agentId'], 'agentId must be the Agent uuid, not the integer 7.');

	}//end testConversationAgentIdResolvedToUuid()

	/**
	 * A conversation whose agent is gone is reported and skipped, not written.
	 *
	 * `agentId` is REQUIRED on the Conversation schema, so nulling a dangling
	 * reference produces a row that can only fail validation — and it did,
	 * eight times per repair, as "Property 'agentId' should be type 'string'
	 * but is 'null'". An error describing the symptom, from a step that had
	 * already decided to carry on.
	 *
	 * This test previously asserted the opposite ("the row must still be
	 * migrated despite the dangling FK"), which is a behaviour the spec never
	 * required: it asks the step to "log the skipped record and continue",
	 * which is what skipping does. The test was pinning the defect.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-unresolvable-foreign-key-is-skipped-not-fatal
	 */
	public function testAConversationWithAMissingAgentIsReportedAndSkipped(): void {
		$db = $this->makeDb(
			tables: [
				'openregister_agents' => [],
				'openregister_conversations' => [$this->conversationRow(42, 'conv-uuid-42', 999, 'alice')],
			]
		);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$this->assertCount(
			0,
			$this->savedFor(schema: 'conversation'),
			'A conversation requires an agent, so one referencing a missing agent must not be written.'
		);

		$this->assertNotEmpty(
			array_filter(
				$this->warnings,
				static fn (string $line): bool => str_contains($line, 'conv-uuid-42') === true
			),
			'The skipped record must be reported by uuid — a silent drop is not a skip.'
		);

	}//end testAConversationWithAMissingAgentIsReportedAndSkipped()

	/**
	 * The first report of an unmigratable conversation is recorded, so the
	 * warning fires once per row instead of once per upgrade forever.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-unresolvable-foreign-key-is-skipped-not-fatal
	 */
	public function testAnUnmigratableConversationIsRecordedForWarnOnce(): void {
		$db = $this->makeDb(
			tables: [
				'openregister_agents' => [],
				'openregister_conversations' => [$this->conversationRow(42, 'conv-uuid-42', 999, 'alice')],
			]
		);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$this->assertNotEmpty($this->persistedUnmigratable, 'The reported uuid must be persisted for later runs.');
		$this->assertContains(
			'conv-uuid-42',
			(json_decode(end($this->persistedUnmigratable), true) ?: []),
			'The persisted record must carry the reported uuid.'
		);

	}//end testAnUnmigratableConversationIsRecordedForWarnOnce()

	/**
	 * An unmigratable conversation that was already reported stays quiet: the
	 * source row can never become migratable (this step never deletes it), so
	 * re-warning on every later upgrade is a permanent false alarm.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-unresolvable-foreign-key-is-skipped-not-fatal
	 */
	public function testAnAlreadyReportedUnmigratableConversationStaysQuiet(): void {
		$this->storedUnmigratable = '["conv-uuid-42"]';
		$db = $this->makeDb(
			tables: [
				'openregister_agents' => [],
				'openregister_conversations' => [$this->conversationRow(42, 'conv-uuid-42', 999, 'alice')],
			]
		);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$this->assertSame(
			[],
			$this->savedFor(schema: 'conversation'),
			'The row is still unmigratable and must not be written.'
		);
		$this->assertSame([], $this->warnings, 'An already-reported row must not warn again.');
		$this->assertSame([], $this->persistedUnmigratable, 'Nothing new to record — the key must not be rewritten.');

	}//end testAnAlreadyReportedUnmigratableConversationStaysQuiet()

	/**
	 * A NEW unmigratable conversation still warns even when another row was
	 * already reported — the warn-once memory is per row, not per install.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-unresolvable-foreign-key-is-skipped-not-fatal
	 */
	public function testANewUnmigratableConversationStillWarns(): void {
		$this->storedUnmigratable = '["conv-uuid-42"]';
		$db = $this->makeDb(
			tables: [
				'openregister_agents' => [],
				'openregister_conversations' => [
					$this->conversationRow(42, 'conv-uuid-42', 999, 'alice'),
					$this->conversationRow(44, 'conv-uuid-44', 998, 'alice'),
				],
			]
		);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$this->assertCount(1, $this->warnings, 'Exactly the new row must warn.');
		$this->assertStringContainsString('conv-uuid-44', $this->warnings[0]);
		$this->assertEqualsCanonicalizing(
			['conv-uuid-42', 'conv-uuid-44'],
			(json_decode(end($this->persistedUnmigratable), true) ?: []),
			'The record must now carry both uuids.'
		);

	}//end testANewUnmigratableConversationStillWarns()

	/**
	 * A dangling reference does not stop the rest of the migration.
	 *
	 * This is the requirement the spec actually states — "continue processing
	 * remaining records" — and the one worth defending: a repair step that
	 * abandons the run on one bad row leaves an instance half-migrated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#an-unresolvable-foreign-key-is-skipped-not-fatal
	 */
	public function testAMissingAgentDoesNotStopTheRemainingRecords(): void {
		$db = $this->makeDb(
			tables: [
				'openregister_agents' => [$this->agentRow(7, 'agent-uuid-7', 'alice')],
				'openregister_conversations' => [
					$this->conversationRow(42, 'conv-uuid-42', 999, 'alice'),
					$this->conversationRow(43, 'conv-uuid-43', 7, 'alice'),
				],
			]
		);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$conv = $this->savedFor(schema: 'conversation');
		$this->assertCount(1, $conv, 'The resolvable conversation must still be migrated.');
		$this->assertSame('conv-uuid-43', $conv[0]['data']['id'] ?? $conv[0]['uuid'] ?? null);

	}//end testAMissingAgentDoesNotStopTheRemainingRecords()

	/**
	 * A record whose uuid already exists is skipped (idempotent re-run).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#re-running-the-repair-step-is-a-no-op-on-migrated-records
	 */
	public function testAlreadyMigratedRecordIsSkipped(): void {
		$this->existing = ['agent-uuid-1'];
		$db = $this->makeDb(tables: ['openregister_agents' => [$this->agentRow(1, 'agent-uuid-1', 'alice')]]);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$this->assertSame([], $this->savedFor(schema: 'agent'), 'An already-migrated uuid must not be written again.');

	}//end testAlreadyMigratedRecordIsSkipped()

	/**
	 * A missing source table is skipped gracefully (fresh install, no OR chat data).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#a-repair-step-migrates-existing-or-agent-engine-data-into-hermiq-register-objects
	 */
	public function testMissingTableSkippedGracefully(): void {
		$db = $this->makeDb(tables: []);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$this->assertSame([], $this->saved, 'Nothing is written when no OR table exists.');
		$this->assertStringContainsString('complete', $this->summaryLine(), 'The step still finishes cleanly.');

	}//end testMissingTableSkippedGracefully()

	/**
	 * Message.context round-trips as the decoded structure (deep-equal to the source).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#a-message-with-an-ai-chat-companion-context-snapshot-round-trips-unchanged
	 */
	public function testMessageContextPreservedExactly(): void {
		$context = ['appId' => 'files', 'pageKind' => 'detail', 'capturedAt' => '2026-07-06T10:00:00+00:00'];
		$db = $this->makeDb(
			tables: [
				'openregister_conversations' => [$this->conversationRow(42, 'conv-uuid-42', 0, 'alice')],
				'openregister_messages' => [
					[
						'id' => 5,
						'uuid' => 'msg-uuid-5',
						'conversation_id' => 42,
						'role' => 'assistant',
						'content' => 'Here you go.',
						'sources' => null,
						'context' => json_encode($context),
						'created' => '2026-07-06 10:00:00',
					],
				],
			]
		);
		$subject = $this->makeSubject(db: $db, flag: 'true');

		$subject->run($this->makeOutput());

		$msg = $this->savedFor(schema: 'message');
		$this->assertCount(1, $msg);
		$this->assertSame('conv-uuid-42', $msg[0]['data']['conversationId']);
		$this->assertEquals($context, $msg[0]['data']['context'], 'context must deep-equal the source snapshot.');

	}//end testMessageContextPreservedExactly()

	/**
	 * Build the subject under test with all collaborators mocked.
	 *
	 * @param IDBConnection $db The (fake) DB connection.
	 * @param string $flag The engine.enabled flag value ('true'|'false').
	 *
	 * @return MigrateAgentData
	 */
	private function makeSubject(IDBConnection $db, string $flag): MigrateAgentData {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($flag): string {
				if ($key === 'engine.enabled') {
					return $flag;
				}

				if ($key === 'agent-data-migration.unmigratable') {
					return $this->storedUnmigratable;
				}

				return $default;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === 'agent-data-migration.unmigratable') {
					$this->persistedUnmigratable[] = $value;
					$this->storedUnmigratable = $value;
				}

				return true;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed $id, ?array $extend = [], bool $files = false, mixed $register = null, mixed $schema = null) {
				if (in_array((string)$id, $this->existing, true) === true) {
					$entity = new ObjectEntity();
					$entity->setUuid((string)$id);
					return $entity;
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) {
				$this->saved[] = ['schema' => (string)$schema, 'uuid' => (string)$uuid, 'data' => (array)$object];
				return new ObjectEntity();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$userSession->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				if ($user !== null) {
					$this->impersonated[] = $user->getUID();
				}
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);

		return new MigrateAgentData(
			$db,
			$appConfig,
			$userSession,
			$userManager,
			$container,
			$this->createMock(LoggerInterface::class)
		);

	}//end makeSubject()

	/**
	 * A fake IDBConnection whose query builder returns the given per-table rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $tables Table name → rows.
	 *
	 * @return IDBConnection
	 */
	private function makeDb(array $tables): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(
			static fn (string $table): bool => array_key_exists($table, $tables)
		);
		$db->method('getQueryBuilder')->willReturnCallback(
			fn (): IQueryBuilder => $this->makeQueryBuilder(tables: $tables)
		);

		return $db;
	}//end makeDb()

	/**
	 * A query-builder mock that records from() and replays that table's rows once.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $tables Table name → rows.
	 *
	 * @return IQueryBuilder
	 */
	private function makeQueryBuilder(array $tables): IQueryBuilder {
		$captured = new \stdClass();
		$captured->table = null;

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setFirstResult')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('from')->willReturnCallback(
			static function (mixed $from, mixed $alias = null) use ($qb, $captured): IQueryBuilder {
				$captured->table = (string)$from;
				return $qb;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturnCallback(
			static fn (): array => ($tables[$captured->table] ?? [])
		);
		$result->method('closeCursor')->willReturn(true);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}//end makeQueryBuilder()

	/**
	 * An IOutput mock capturing info() lines.
	 *
	 * @return IOutput
	 */
	private function makeOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->infos[] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return $output;
	}//end makeOutput()

	/**
	 * Saved calls for one schema slug.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array{schema: string, uuid: string, data: array<string, mixed>}>
	 */
	private function savedFor(string $schema): array {
		return array_values(
			array_filter(
				$this->saved,
				static fn (array $call): bool => $call['schema'] === $schema
			)
		);

	}//end savedFor()

	/**
	 * The final summary info line ("... migration complete ...").
	 *
	 * @return string
	 */
	private function summaryLine(): string {
		foreach ($this->infos as $line) {
			if (str_contains($line, 'complete') === true) {
				return $line;
			}
		}

		return '';
	}//end summaryLine()

	/**
	 * A canned openregister_agents row.
	 *
	 * @param int $id The row id.
	 * @param string $uuid The row uuid.
	 * @param string $owner The owner UID.
	 *
	 * @return array<string, mixed>
	 */
	private function agentRow(int $id, string $uuid, string $owner): array {
		return [
			'id' => $id,
			'uuid' => $uuid,
			'name' => 'Permit assistant',
			'description' => 'Drafts permits',
			'type' => 'assistant',
			'provider' => 'openai',
			'model' => 'gpt-4',
			'prompt' => 'You help.',
			'temperature' => '0.7',
			'max_tokens' => '2048',
			'active' => '1',
			'owner' => $owner,
			'organisation' => 'org-x',
			'created' => '2026-07-06 09:00:00',
		];

	}//end agentRow()

	/**
	 * A canned openregister_conversations row.
	 *
	 * @param int $id The row id.
	 * @param string $uuid The row uuid.
	 * @param int $agentId The integer agent FK.
	 * @param string $owner The owner UID.
	 *
	 * @return array<string, mixed>
	 */
	private function conversationRow(int $id, string $uuid, int $agentId, string $owner): array {
		return [
			'id' => $id,
			'uuid' => $uuid,
			'title' => 'Permit chat',
			'user_id' => $owner,
			'owner' => $owner,
			'organisation' => 'org-x',
			'agent_id' => $agentId,
			'metadata' => json_encode(['token_count' => 12]),
			'created' => '2026-07-06 09:30:00',
		];

	}//end conversationRow()
}//end class
