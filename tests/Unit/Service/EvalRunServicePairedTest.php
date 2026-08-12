<?php

/**
 * Hermiq EvalRunService paired-baseline unit tests (skill-evals).
 *
 * Covers the paired with-skill vs without-skill orchestration: joint (default/unset)
 * vs per-skill attribution halves, install-state independence (installed ∪ linked),
 * in-memory-only detachment (stored Agent/Skill objects never written, crash-safe
 * between halves), every half's usage in the ONE per-run budget/audit entry,
 * `skillsUsed` recording, the completed-run-only patch-only l5 write-back with the
 * `mode` marker, the empty-skillRefs rejection, and the regression gate fed by the
 * with-half pass rate.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode
 * @spec openspec/specs/agent-evals/spec.md#requirement-baseline-detachment-is-per-run-and-in-memory-only
 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
 * @spec openspec/specs/agent-evals/spec.md#requirement-a-completed-paired-run-is-the-only-writer-of-l5-evidence
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\EvalRunService;
use OCA\Hermiq\Service\EvalScoringService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Recording ObjectService stub: find() serves seeded objects by uuid, findAll()
 * returns the seeded prior runs (regression gate), saveObject() records every
 * write (schema + uuid + payload) so tests can assert exactly which stored
 * objects a paired run touched — the in-memory-detachment contract.
 */
class RecordingObjectService extends ObjectService {

	/**
	 * Every saveObject call: {schema, uuid, object}.
	 *
	 * @var array<int, array{schema: ?string, uuid: ?string, object: array<string,mixed>}>
	 */
	public array $saves = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, ObjectEntity> $objects Seeded objects by uuid (find()).
	 * @param array<int, ObjectEntity> $priorRuns Prior EvalRun objects (findAll()).
	 */
	public function __construct(
		private array $objects = [],
		private array $priorRuns = [],
	) {
	}//end __construct()

	/**
	 * Chainable register context (no-op).
	 *
	 * @param mixed $register Register slug.
	 *
	 * @return static
	 */
	public function setRegister(mixed $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Chainable schema context (no-op).
	 *
	 * @param mixed $schema Schema slug.
	 *
	 * @return static
	 */
	public function setSchema(mixed $schema): static {
		return $this;
	}//end setSchema()

	/**
	 * The seeded prior runs.
	 *
	 * @param array $config Query config.
	 * @param bool $_rbac RBAC toggle.
	 * @param bool $_multitenancy Multitenancy toggle.
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		return $this->priorRuns;
	}//end findAll()

	/**
	 * Serve a seeded object by uuid.
	 *
	 * @param int|string $id Object uuid.
	 * @param array|null $_extend Extend config.
	 * @param bool $files Files toggle.
	 * @param mixed $register Register context.
	 * @param mixed $schema Schema context.
	 * @param bool $_rbac RBAC toggle.
	 * @param bool $_multitenancy Multitenancy toggle.
	 *
	 * @return ObjectEntity|null
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		mixed $register = null,
		mixed $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
	): ?ObjectEntity {
		return ($this->objects[(string)$id] ?? null);
	}//end find()

	/**
	 * Record the write and echo back an entity.
	 *
	 * @param array|ObjectEntity $object The payload.
	 * @param array|null $extend Extend config.
	 * @param mixed $register Register context.
	 * @param mixed $schema Schema context.
	 * @param string|null $uuid Target uuid.
	 * @param bool $_rbac RBAC toggle.
	 * @param bool $_multitenancy Multitenancy toggle.
	 *
	 * @return ObjectEntity
	 */
	public function saveObject(
		array|ObjectEntity $object,
		?array $extend = [],
		mixed $register = null,
		mixed $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		?array $uploadedFiles = null,
		?\OCP\IUser $currentUser = null,
		// Added by openregister#2211 (insert-only saves). A double that does not
		// track the real signature is a FATAL, not a failed assertion: PHP
		// refuses to declare the class, so every test in the suite dies before
		// it runs — which is what took all six PHPUnit matrix jobs down.
		bool $failIfExists = false,
	): ObjectEntity {
		$payload = (is_array($object) === true) ? $object : $object->getObject();
		$this->saves[] = [
			'schema' => (is_string($schema) === true) ? $schema : null,
			'uuid' => $uuid,
			'object' => $payload,
		];

		$entity = new ObjectEntity();
		$entity->setUuid($uuid ?? 'eval-run-uuid');
		$entity->setObject($payload);
		return $entity;
	}//end saveObject()

	/**
	 * The saves against one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array{schema: ?string, uuid: ?string, object: array<string,mixed>}>
	 */
	public function savesFor(string $schema): array {
		return array_values(array_filter($this->saves, static fn (array $save): bool => $save['schema'] === $schema));
	}//end savesFor()
}//end class

/**
 * EvalRunService paired-mode orchestration tests (skill-evals).
 *
 * @spec openspec/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode
 */
class EvalRunServicePairedTest extends TestCase {

	/**
	 * Recorded audit contexts (one per writeRunAudit call).
	 *
	 * @var array<int, array<string,mixed>>
	 */
	private array $auditContexts = [];

	/**
	 * A target Agent (OR entity) owned by alice in org-a.
	 *
	 * @return Agent
	 */
	private function agent(): Agent {
		// A real entity, not a mock: the real OpenRegister Agent resolves
		// getUuid()/getOwner()/getOrganisation() via Entity MAGIC accessors,
		// which PHPUnit mocks cannot configure when the real class is loaded
		// (CI runs inside a full server tree with OpenRegister installed).
		$agent = new Agent();
		$agent->setUuid('agent-uuid');
		$agent->setOwner('alice');
		$agent->setOrganisation('org-a');
		return $agent;
	}//end agent()

	/**
	 * An ObjectEntity with the given uuid + payload.
	 *
	 * @param string $uuid The object uuid.
	 * @param array<string,mixed> $payload The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * An EvalDataset entity with the given cases + skillRefs.
	 *
	 * @param array<int, array<string,mixed>> $cases The cases.
	 * @param array<int, string> $skillRefs Linked skill uuids.
	 *
	 * @return ObjectEntity
	 */
	private function dataset(array $cases, array $skillRefs = []): ObjectEntity {
		return $this->entity('dataset-uuid', ['name' => 'demo', 'cases' => $cases, 'skillRefs' => $skillRefs]);
	}//end dataset()

	/**
	 * N contains-cases with prompts a, b, c, ...
	 *
	 * @param int $count The case count.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function cases(int $count): array {
		$cases = [];
		for ($i = 0; $i < $count; $i++) {
			$cases[] = [
				'prompt' => chr((97 + $i)),
				'expectationType' => 'contains',
				'expectedSubstring' => 'MARKER',
			];
		}

		return $cases;
	}//end cases()

	/**
	 * A ScheduleService mock whose runAgentAsOwner() output depends on the
	 * per-run skill-set override (MARKER when $markerSkill is exposed), and
	 * which records every override it saw.
	 *
	 * @param array<int, ?array> $overrides Captured overrides (by reference).
	 * @param string $markerSkill The skill uuid whose exposure flips output.
	 *
	 * @return ScheduleService
	 */
	private function scheduleService(array &$overrides, string $markerSkill = 'skill-a'): ScheduleService {
		$schedule = $this->createMock(ScheduleService::class);
		$schedule->method('isOrganisationEngaged')->willReturn(false);
		$schedule->method('runAgentAsOwner')->willReturnCallback(
			function (
				string $owner,
				string $agentId,
				string $prompt,
				string $organisation = '',
				bool $dryRun = false,
				bool $forceOwner = false,
				?ObjectEntity $anchor = null,
				?array $skillSetOverride = null,
			) use (&$overrides, $markerSkill): string {
				$overrides[] = $skillSetOverride;
				if (in_array($markerSkill, ($skillSetOverride ?? []), true) === true) {
					return 'output MARKER';
				}

				return 'plain output';
			}
		);
		$schedule->method('getLastRunUsage')->willReturn(['promptTokens' => 10, 'completionTokens' => 5]);
		$schedule->method('getLastRunSkillsUsed')->willReturnCallback(
			static function () use (&$overrides): array {
				$last = end($overrides);
				return array_values(array_filter(is_array($last) === true ? $last : []));
			}
		);
		return $schedule;
	}//end scheduleService()

	/**
	 * Marker-based scoring: a case passes when its output carries MARKER, unless
	 * its prompt is listed in $failEvenWithMarker; a markerless output passes
	 * when its prompt is listed in $passWithoutMarker.
	 *
	 * @param array<int, string> $passWithoutMarker Prompts that pass the without-half.
	 * @param array<int, string> $failEvenWithMarker Prompts that fail even with MARKER.
	 *
	 * @return EvalScoringService
	 */
	private function scoring(array $passWithoutMarker = [], array $failEvenWithMarker = []): EvalScoringService {
		$scoring = $this->createMock(EvalScoringService::class);
		$scoring->method('score')->willReturnCallback(
			static function (array $case, string $actualOutput, string $organisation) use ($passWithoutMarker, $failEvenWithMarker): array {
				$prompt = (string)($case['prompt'] ?? '');
				$passed = (str_contains($actualOutput, 'MARKER') === true);
				if ($passed === true && in_array($prompt, $failEvenWithMarker, true) === true) {
					$passed = false;
				}

				if ($passed === false && str_contains($actualOutput, 'MARKER') === false && in_array($prompt, $passWithoutMarker, true) === true) {
					$passed = true;
				}

				return [
					'passed' => $passed,
					'errorMessage' => null,
					'score' => null,
					'judgeRationale' => null,
				];
			}
		);
		return $scoring;
	}//end scoring()

	/**
	 * Build the service under test; audit contexts are captured into
	 * $this->auditContexts.
	 *
	 * @param RecordingObjectService $objectService The recording store.
	 * @param ScheduleService $schedule The schedule mock.
	 * @param EvalScoringService|null $scoring The scoring mock.
	 * @param BudgetService|null $budget The budget mock.
	 *
	 * @return EvalRunService
	 */
	private function service(
		RecordingObjectService $objectService,
		ScheduleService $schedule,
		?EvalScoringService $scoring = null,
		?BudgetService $budget = null,
	): EvalRunService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $default
		);

		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnArgument(0);

		$this->auditContexts = [];
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []): AuditTrail {
				$this->auditContexts[] = $context;
				$entry = new AuditTrail();
				$entry->setUuid('audit-uuid');
				return $entry;
			}
		);

		return new EvalRunService(
			objectService: $objectService,
			scheduleService: $schedule,
			budgetService: ($budget ?? $this->createMock(BudgetService::class)),
			scoringService: ($scoring ?? $this->scoring()),
			auditTrailMapper: $audit,
			redactionService: $redaction,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			contextAssembler: $this->createMock(ContextAssembler::class),
			skillVersionService: $this->createMock(SkillVersionService::class),
			jobList: $this->createMock(IJobList::class),
		);

	}//end service()

	/**
	 * Joint mode (agent's evalBaselineMode UNSET): a paired run over 4 cases and one
	 * linked-but-NOT-installed skill executes exactly two halves — the WITH half at
	 * installed ∪ linked (install state cannot skew: qualification before install),
	 * the WITHOUT half at installed ∖ linked — records both halves + the joint
	 * per-skill delta, aggregates BOTH halves' usage into the one audit entry, and
	 * records the exposed skill uuids as skillsUsed.
	 *
	 * @return void
	 */
	public function testJointPairedRunRecordsBothHalvesAndDelta(): void {
		$overrides = [];
		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => ['skill-other']]),
				'skill-a' => $this->entity('skill-a', ['name' => 'skill-a', 'state' => 'active', 'body' => 'B', 'maturityLevel' => 4]),
			]
		);
		$service = $this->service(
			objectService: $objectService,
			schedule: $this->scheduleService($overrides),
			scoring: $this->scoring(passWithoutMarker: ['a', 'b'])
		);

		$result = $service->run(
			$this->dataset($this->cases(4), skillRefs: ['skill-a']),
			$this->agent(),
			baseline: true
		);

		// 8 turns: 4 WITH + 4 WITHOUT, sequential.
		$this->assertCount(8, $overrides);
		foreach (array_slice($overrides, 0, 4) as $withOverride) {
			$this->assertEqualsCanonicalizing(['skill-other', 'skill-a'], $withOverride);
		}

		foreach (array_slice($overrides, 4, 4) as $withoutOverride) {
			$this->assertSame(['skill-other'], $withoutOverride);
		}

		$this->assertSame('completed', $result['status']);
		$this->assertSame(1.0, $result['passRate']);

		$runs = $objectService->savesFor('evalrun');
		$this->assertCount(1, $runs);
		$run = $runs[0]['object'];
		$this->assertTrue($run['baselineMode']);
		$this->assertSame('joint', $run['attributionMode']);
		$this->assertSame(1.0, $run['passRate']);
		$this->assertSame(0.5, $run['baselinePassRate']);
		$this->assertCount(4, $run['results']);
		$this->assertCount(4, $run['baselineResults']);
		$this->assertSame(
			[
				'skillId' => 'skill-a',
				'passRateWith' => 1.0,
				'passRateWithout' => 0.5,
				'baselineDelta' => 0.5,
			],
			$run['skillResults'][0]
		);

		// EVERY half's usage in the ONE audit entry: 8 turns × (10 + 5) tokens.
		$this->assertCount(1, $this->auditContexts);
		$this->assertSame(['promptTokens' => 80, 'completionTokens' => 40], $this->auditContexts[0]['usage']);
		$this->assertContains('skill-a', $this->auditContexts[0]['skillsUsed']);

	}//end testJointPairedRunRecordsBothHalvesAndDelta()

	/**
	 * Joint mode with TWO linked skills: exactly two halves execute (never one per
	 * skill) and both skillResults entries share the same joint numbers.
	 *
	 * @return void
	 */
	public function testJointModeTwoSkillsShareJointNumbers(): void {
		$overrides = [];
		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => [], 'evalBaselineMode' => 'joint']),
				'skill-a' => $this->entity('skill-a', ['name' => 'skill-a', 'state' => 'active']),
				'skill-b' => $this->entity('skill-b', ['name' => 'skill-b', 'state' => 'active']),
			]
		);
		$service = $this->service(
			objectService: $objectService,
			schedule: $this->scheduleService($overrides)
		);

		$service->run(
			$this->dataset($this->cases(2), skillRefs: ['skill-a', 'skill-b']),
			$this->agent(),
			baseline: true
		);

		// 2 cases × 2 halves = 4 turns — never one half per skill in joint mode.
		$this->assertCount(4, $overrides);

		$run = $objectService->savesFor('evalrun')[0]['object'];
		$this->assertCount(2, $run['skillResults']);
		$first = $run['skillResults'][0];
		$second = $run['skillResults'][1];
		$this->assertSame($first['passRateWith'], $second['passRateWith']);
		$this->assertSame($first['passRateWithout'], $second['passRateWithout']);
		$this->assertSame($first['baselineDelta'], $second['baselineDelta']);

	}//end testJointModeTwoSkillsShareJointNumbers()

	/**
	 * Per-skill mode over 3 cases and two linked skills (one marker-bearing, one
	 * inert): exactly three halves execute (N+1), each entry carries its OWN
	 * marginal delta from its dedicated without-half (marker skill delta 1.0,
	 * inert skill delta 0.0) with that half's case results on the entry's
	 * baselineResults; the top-level baseline fields stay unset; all three
	 * halves' tokens land in the ONE budget sum; l5 carries mode per-skill.
	 *
	 * @return void
	 */
	public function testPerSkillModeRunsOneWithoutHalfPerSkill(): void {
		$overrides = [];
		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => [], 'evalBaselineMode' => 'per-skill']),
				'skill-m' => $this->entity('skill-m', ['name' => 'marker-skill', 'state' => 'active']),
				'skill-i' => $this->entity('skill-i', ['name' => 'inert-skill', 'state' => 'active']),
			]
		);
		$service = $this->service(
			objectService: $objectService,
			schedule: $this->scheduleService($overrides, markerSkill: 'skill-m')
		);

		$result = $service->run(
			$this->dataset($this->cases(3), skillRefs: ['skill-m', 'skill-i']),
			$this->agent(),
			baseline: true
		);

		// 3 cases × 3 halves (WITH + one WITHOUT per skill) = 9 turns.
		$this->assertCount(9, $overrides);
		$this->assertSame('completed', $result['status']);

		$run = $objectService->savesFor('evalrun')[0]['object'];
		$this->assertSame('per-skill', $run['attributionMode']);
		$this->assertTrue($run['baselineMode']);
		$this->assertArrayNotHasKey('baselineResults', $run);
		$this->assertArrayNotHasKey('baselinePassRate', $run);

		$bySkill = [];
		foreach ($run['skillResults'] as $entry) {
			$bySkill[$entry['skillId']] = $entry;
		}

		// Marker skill: WITH passes (marker present), its dedicated WITHOUT half
		// loses the marker → true marginal 1.0.
		$this->assertSame(1.0, $bySkill['skill-m']['passRateWith']);
		$this->assertSame(0.0, $bySkill['skill-m']['passRateWithout']);
		$this->assertSame(1.0, $bySkill['skill-m']['baselineDelta']);
		$this->assertCount(3, $bySkill['skill-m']['baselineResults']);

		// Inert skill: its WITHOUT half still exposes the marker skill → marginal 0.
		$this->assertSame(1.0, $bySkill['skill-i']['passRateWithout']);
		$this->assertSame(0.0, $bySkill['skill-i']['baselineDelta']);
		$this->assertCount(3, $bySkill['skill-i']['baselineResults']);

		// All N+1 halves' tokens in ONE budget sum: 9 turns × (10 + 5).
		$this->assertSame(['promptTokens' => 90, 'completionTokens' => 45], $this->auditContexts[0]['usage']);

		// l5 stamped per skill with its own marginal and the per-skill mode marker.
		$l5Saves = $objectService->savesFor('agentskill');
		$this->assertCount(2, $l5Saves);
		$markerL5 = null;
		foreach ($l5Saves as $save) {
			if ($save['uuid'] === 'skill-m') {
				$markerL5 = $save['object']['levelEvidence']['l5'];
			}
		}

		$this->assertNotNull($markerL5);
		$this->assertSame('per-skill', $markerL5['mode']);
		$this->assertSame(1.0, $markerL5['baselineDelta']);

	}//end testPerSkillModeRunsOneWithoutHalfPerSkill()

	/**
	 * The l5 write-back is patch-only carry-forward: the linked skill's content,
	 * state, files, installedOn, and maturityLevel survive byte-identical; only
	 * levelEvidence.l5 changes (with the mode marker) — and the stored agent
	 * object is never written at all.
	 *
	 * @return void
	 */
	public function testL5WriteBackPatchesOnlyL5AndCarriesEverythingForward(): void {
		$skillPayload = [
			'name' => 'skill-a',
			'frontmatter' => "name: skill-a\ndescription: d",
			'body' => 'the body',
			'files' => [['name' => 'references/r.md', 'content' => 'ref']],
			'state' => 'active',
			'installedOn' => ['agent-uuid'],
			'maturityLevel' => 4,
			'levelEvidence' => [
				'l4' => ['attestedBy' => 'admin', 'attestedAt' => '2026-01-15T09:00:00+00:00'],
			],
		];

		$overrides = [];
		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => ['skill-a']]),
				'skill-a' => $this->entity('skill-a', $skillPayload),
			]
		);
		$service = $this->service(
			objectService: $objectService,
			schedule: $this->scheduleService($overrides)
		);

		$service->run($this->dataset($this->cases(2), skillRefs: ['skill-a']), $this->agent(), baseline: true);

		$l5Saves = $objectService->savesFor('agentskill');
		$this->assertCount(1, $l5Saves);
		$this->assertSame('skill-a', $l5Saves[0]['uuid']);
		$saved = $l5Saves[0]['object'];

		// Patch-only: everything else carried forward verbatim.
		foreach (['name', 'frontmatter', 'body', 'files', 'state', 'installedOn', 'maturityLevel'] as $key) {
			$this->assertSame($skillPayload[$key], $saved[$key]);
		}

		$this->assertSame($skillPayload['levelEvidence']['l4'], $saved['levelEvidence']['l4']);

		$l5 = $saved['levelEvidence']['l5'];
		$this->assertSame('dataset-uuid', $l5['evalDatasetId']);
		$this->assertSame(1.0, $l5['passRate']);
		$this->assertSame(1.0, $l5['baselineDelta']);
		$this->assertSame('joint', $l5['mode']);
		$this->assertNotEmpty($l5['lastValidated']);

		// The stored agent object is NEVER written by a paired run.
		$this->assertCount(0, $objectService->savesFor('agent'));

	}//end testL5WriteBackPatchesOnlyL5AndCarriesEverythingForward()

	/**
	 * A crash in the WITHOUT half (after a clean WITH half) ends the run failed —
	 * and neither the agent's stored skillInstalls nor any skill object was ever
	 * written, and NO l5 evidence lands (failed runs write nothing).
	 *
	 * @return void
	 */
	public function testCrashBetweenHalvesNeverStripsTheAgentAndWritesNoEvidence(): void {
		$overrides = [];
		$schedule = $this->createMock(ScheduleService::class);
		$schedule->method('isOrganisationEngaged')->willReturn(false);
		$schedule->method('getLastRunUsage')->willReturn([]);
		$schedule->method('getLastRunSkillsUsed')->willReturn([]);
		$schedule->method('runAgentAsOwner')->willReturnCallback(
			function (
				string $owner,
				string $agentId,
				string $prompt,
				string $organisation = '',
				bool $dryRun = false,
				bool $forceOwner = false,
				?ObjectEntity $anchor = null,
				?array $skillSetOverride = null,
			) use (&$overrides): string {
				$overrides[] = $skillSetOverride;
				if (in_array('skill-a', ($skillSetOverride ?? []), true) === false) {
					// The WITHOUT half hits an infrastructure error.
					throw new RuntimeException('provider went away between halves');
				}

				return 'output MARKER';
			}
		);

		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => ['skill-a', 'skill-other']]),
				'skill-a' => $this->entity('skill-a', ['name' => 'skill-a', 'state' => 'active']),
			]
		);
		$service = $this->service(objectService: $objectService, schedule: $schedule);

		$result = $service->run($this->dataset($this->cases(2), skillRefs: ['skill-a']), $this->agent(), baseline: true);

		$this->assertSame('failed', $result['status']);

		// In-memory only: no write to the agent object, no write to any skill.
		$this->assertCount(0, $objectService->savesFor('agent'));
		$this->assertCount(0, $objectService->savesFor('agentskill'));

		// The failed run itself is persisted (status=failed), evidence is not.
		$runs = $objectService->savesFor('evalrun');
		$this->assertCount(1, $runs);
		$this->assertSame('failed', $runs[0]['object']['status']);

	}//end testCrashBetweenHalvesNeverStripsTheAgentAndWritesNoEvidence()

	/**
	 * A gate-blocked paired run executes neither half and writes no evidence.
	 *
	 * @return void
	 */
	public function testGateBlockedPairedRunExecutesNeitherHalf(): void {
		$overrides = [];
		$schedule = $this->scheduleService($overrides);

		$budget = $this->createMock(BudgetService::class);
		$budget->method('isBlocked')->willReturn(true);

		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => []]),
			]
		);
		$service = $this->service(objectService: $objectService, schedule: $schedule, budget: $budget);

		$result = $service->run($this->dataset($this->cases(2), skillRefs: ['skill-a']), $this->agent(), baseline: true);

		$this->assertSame('blocked_budget', $result['status']);
		$this->assertCount(0, $overrides);
		$this->assertCount(0, $objectService->savesFor('agentskill'));

	}//end testGateBlockedPairedRunExecutesNeitherHalf()

	/**
	 * Baseline mode on a dataset without linked skills is rejected before any
	 * gate-skip run is persisted (the controller maps this to 400).
	 *
	 * @return void
	 */
	public function testBaselineWithoutLinkedSkillsIsRejected(): void {
		$overrides = [];
		$objectService = new RecordingObjectService();
		$service = $this->service(objectService: $objectService, schedule: $this->scheduleService($overrides));

		$this->expectException(\InvalidArgumentException::class);

		try {
			$service->run($this->dataset($this->cases(1)), $this->agent(), baseline: true);
		} finally {
			$this->assertCount(0, $overrides);
			$this->assertCount(0, $objectService->saves);
		}

	}//end testBaselineWithoutLinkedSkillsIsRejected()

	/**
	 * The regression gate is fed by the WITH-half pass rate and compares against
	 * the immediately preceding completed run — paired or plain: a previous plain
	 * run at 0.90 vs a paired with-half at 0.75 fails the 10pp gate.
	 *
	 * @return void
	 */
	public function testRegressionGateComparesWithHalfAgainstPreviousPlainRun(): void {
		$previous = $this->entity(
			'previous-run',
			[
				'datasetId' => 'dataset-uuid',
				'agentId' => 'agent-uuid',
				'status' => 'completed',
				'passRate' => 0.90,
			]
		);
		$previous->setCreated(new \DateTime('2026-07-01T00:00:00+00:00'));

		$overrides = [];
		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => []]),
				'skill-a' => $this->entity('skill-a', ['name' => 'skill-a', 'state' => 'active']),
			],
			priorRuns: [$previous]
		);
		$service = $this->service(
			objectService: $objectService,
			schedule: $this->scheduleService($overrides),
			// WITH half: prompt 'd' fails even with the marker → 3/4 = 0.75.
			scoring: $this->scoring(failEvenWithMarker: ['d'])
		);

		$result = $service->run($this->dataset($this->cases(4), skillRefs: ['skill-a']), $this->agent(), baseline: true);

		$this->assertSame(0.75, $result['passRate']);
		$this->assertSame('failed', $result['regressionGateResult']);
		$this->assertSame(0.90, $result['previousPassRate']);

	}//end testRegressionGateComparesWithHalfAgainstPreviousPlainRun()

	/**
	 * A PLAIN (non-baseline) run records the exposed skill uuids on its audit
	 * entry too (skillsUsed persists for ALL runs, not just paired ones) and
	 * adds none of the paired fields to the persisted EvalRun.
	 *
	 * @return void
	 */
	public function testPlainRunRecordsSkillsUsedAndNoPairedFields(): void {
		$overrides = [];
		$schedule = $this->createMock(ScheduleService::class);
		$schedule->method('isOrganisationEngaged')->willReturn(false);
		$schedule->method('runAgentAsOwner')->willReturnCallback(
			function () use (&$overrides): string {
				$overrides[] = func_get_args();
				return 'output MARKER';
			}
		);
		$schedule->method('getLastRunUsage')->willReturn([]);
		$schedule->method('getLastRunSkillsUsed')->willReturn(['skill-installed']);

		$objectService = new RecordingObjectService();
		$service = $this->service(objectService: $objectService, schedule: $schedule);

		$result = $service->run($this->dataset($this->cases(1)), $this->agent());

		$this->assertSame('completed', $result['status']);
		$this->assertSame(['skill-installed'], $this->auditContexts[0]['skillsUsed']);

		$run = $objectService->savesFor('evalrun')[0]['object'];
		$this->assertArrayNotHasKey('baselineMode', $run);
		$this->assertArrayNotHasKey('attributionMode', $run);
		$this->assertArrayNotHasKey('skillResults', $run);

	}//end testPlainRunRecordsSkillsUsedAndNoPairedFields()
}//end class
