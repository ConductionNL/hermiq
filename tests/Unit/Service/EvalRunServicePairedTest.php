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
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Mcp\Db\Agent;
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
	 * Called with the schema of every save, so a test can see it in write order.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $onSave = null;

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
		bool $_audit = true,
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
		// Added by openregister@8729969f7 (decline schema re-validation on save),
		// 2026-08-13 00:07Z, INSERTED MID-SIGNATURE — appending it would not have
		// matched. Second such break in 70 minutes; see #2211's note above.
		bool $_validation = true,
		?array $uploadedFiles = null,
		?\OCP\IUser $currentUser = null,
		// Added by openregister#2211 (insert-only saves). A double that does not
		// track the real signature is a FATAL, not a failed assertion: PHP
		// refuses to declare the class, so every test in the suite dies before
		// it runs — which is what took all six PHPUnit matrix jobs down.
		bool $failIfExists = false,
		// Added by openregister (stamp the SYSTEM identity as owner even when a
		// user session exists). Appended, so unlike $_validation above it needed
		// no re-ordering — but it is the third such break, and it only surfaces
		// where the REAL ObjectService is loaded: CI runs this suite against the
		// OCP stubs, so the fatal appears locally (inside a booted Nextcloud)
		// while CI stays green.
		bool $_unowned = false,
		// Added by openregister (duplicate detection), seen 2026-09-16. Appended,
		// so no re-ordering was needed. Note which way round this one broke: the
		// LOCAL suite stayed green, because tests/bootstrap.php resolves
		// ObjectService from hermiq's own stub, while CI installs the real
		// openregister and PHP refused to declare this class, killing all six
		// cells with a fatal before a single test ran.
		bool $_dedupOverride = false,
		?string $_owner = null,
	): ObjectEntity {
		$payload = (is_array($object) === true) ? $object : $object->getObject();
		if ($this->onSave !== null) {
			($this->onSave)((is_string($schema) === true) ? $schema : null);
		}

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
	 * Recorded IJobList::add() calls: {job, argument}.
	 *
	 * @var array<int, array{job: mixed, argument: mixed}>
	 */
	private array $jobs = [];

	/**
	 * Every write in order: `save:<schema>`, `audit`, `job`.
	 *
	 * @var array<int, string>
	 */
	private array $events = [];

	/**
	 * The owner each runAgentAsOwner() call ran as.
	 *
	 * @var array<int, string>
	 */
	private array $owners = [];

	/**
	 * The app setting eval.regressionThresholdPercent answers, or null for the caller's default.
	 *
	 * @var string|null
	 */
	private ?string $configuredThreshold = null;

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
				$this->owners[] = $owner;
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
			fn (string $app, string $key, string $default = ''): string => (
				$this->configuredThreshold !== null && $app === 'hermiq' && $key === 'eval.regressionThresholdPercent'
			) ? $this->configuredThreshold : $default
		);

		$this->events = [];
		$objectService->onSave = function (?string $schema): void {
			$this->events[] = 'save:' . (string)$schema;
		};

		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnArgument(0);

		$this->auditContexts = [];
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event): AuditTrail {
				$this->auditContexts[] = $event->context;
				$this->events[] = 'audit';
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
			jobList: $this->jobList(),
		);

	}//end service()

	/**
	 * An IJobList mock whose add() calls are captured into $this->jobs.
	 *
	 * @return IJobList
	 */
	private function jobList(): IJobList {
		$this->jobs = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument = null): void {
				$this->jobs[] = ['job' => $job, 'argument' => $argument];
				$this->events[] = 'job';
			}
		);
		return $jobList;
	}//end jobList()

	/**
	 * Golden: every paired-run variant (baseline mode, installed and linked skills,
	 * case count, an infra error, a previous run, a threshold override) writes and
	 * returns exactly what was recorded. GOLDEN_RECORD=1 rewrites the fixture.
	 *
	 * @return void
	 */
	public function testPairedRunVariantsMatchTheRecordedFixture(): void {
		$fixture = __DIR__ . '/../../Fixtures/Golden/eval-paired-run.json';
		$actual = [];
		$variant = 0;
		foreach ([null, 'joint', 'per-skill'] as $mode) {
			foreach ([[], ['skill-other'], ['skill-a', 'skill-other']] as $installed) {
				foreach ([['skill-a'], ['skill-a', 'skill-b']] as $linked) {
					// Case count, infra error, previous run and threshold vary together
					// over four variants per combination instead of every product.
					for ($round = 0; $round < 4; $round++) {
						$scenario = [
							'mode' => $mode,
							'installed' => $installed,
							'linked' => $linked,
							'caseCount' => [1, 3][$round % 2],
							'infra' => ($round >= 2),
							'prior' => (($round + $variant) % 2 === 0),
							'threshold' => [null, 0, 50][($round + $variant) % 3],
						];
						$actual[(string)json_encode($scenario)] = $this->pairedRunTrace(scenario: $scenario);
					}

					$variant++;
				}
			}
		}

		$actual = json_decode(json_encode($actual, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped('Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame($expected, $actual);
	}//end testPairedRunVariantsMatchTheRecordedFixture()

	/**
	 * Runs one paired-run scenario and returns everything it wrote and returned,
	 * with timestamps and durations masked.
	 *
	 * @param array<string, mixed> $scenario The scenario values.
	 *
	 * @return array<string, mixed>
	 */
	private function pairedRunTrace(array $scenario): array {
		$agentData = ['name' => 'demo-agent', 'skillInstalls' => $scenario['installed']];
		if ($scenario['mode'] !== null) {
			$agentData['evalBaselineMode'] = $scenario['mode'];
		}

		$priorRuns = [];
		if ($scenario['prior'] === true) {
			$previous = $this->entity(
				'previous-run',
				['datasetId' => 'dataset-uuid', 'agentId' => 'agent-uuid', 'status' => 'completed', 'passRate' => 0.6]
			);
			$previous->setCreated(new \DateTime('2026-07-01T00:00:00+00:00'));
			$priorRuns[] = $previous;
		}

		$objects = ['agent-uuid' => $this->entity('agent-uuid', $agentData)];
		foreach (['skill-a', 'skill-b'] as $skill) {
			$objects[$skill] = $this->entity($skill, ['name' => $skill, 'state' => 'active', 'body' => 'B', 'maturityLevel' => 4]);
		}

		$objectService = new RecordingObjectService(objects: $objects, priorRuns: $priorRuns);
		$overrides = [];
		$schedule = $this->scheduleService($overrides);
		if ($scenario['infra'] === true) {
			$schedule = $this->createMock(ScheduleService::class);
			$schedule->method('isOrganisationEngaged')->willReturn(false);
			$schedule->method('runAgentAsOwner')->willReturnCallback(
				function (string $owner, string $agentId, string $prompt, string $organisation = '', bool $dryRun = false, bool $forceOwner = false, ?ObjectEntity $anchor = null, ?array $skillSetOverride = null) use (&$overrides): string {
					$overrides[] = $skillSetOverride;
					if ($prompt === 'a' && in_array('skill-b', ($skillSetOverride ?? []), true) === false) {
						throw new RuntimeException('no provider');
					}

					return in_array('skill-a', ($skillSetOverride ?? []), true) === true ? 'output MARKER' : 'plain output';
				}
			);
			$schedule->method('getLastRunUsage')->willReturn(['promptTokens' => 7, 'completionTokens' => 3]);
			$schedule->method('getLastRunSkillsUsed')->willReturnCallback(
				static function () use (&$overrides): array {
					$last = end($overrides);
					return array_values(array_filter(is_array($last) === true ? $last : []));
				}
			);
		}//end if

		$service = $this->service(objectService: $objectService, schedule: $schedule, scoring: $this->scoring(passWithoutMarker: ['b'], failEvenWithMarker: ['c']));
		$result = $service->run(
			$this->dataset($this->cases($scenario['caseCount']), skillRefs: $scenario['linked']),
			$this->agent(),
			agentVersionId: 'version-1',
			regressionThresholdOverride: $scenario['threshold'],
			baseline: true
		);

		return $this->masked(
			value: [
				'result' => $result,
				'overrides' => $overrides,
				'saves' => $objectService->saves,
				'audit' => $this->auditContexts,
				'jobs' => $this->jobs,
			]
		);
	}//end pairedRunTrace()

	/**
	 * Masks wall-clock values (ISO timestamps, durationMs) recursively.
	 *
	 * @param mixed $value The value to mask.
	 *
	 * @return mixed
	 */
	private function masked(mixed $value): mixed {
		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) === 1) {
			return '<time>';
		}

		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			$value[$key] = $this->masked(value: $item);
			if ($key === 'durationMs') {
				$value[$key] = '<duration>';
			}
		}

		return $value;
	}//end masked()

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

	/**
	 * A plain run skips a case that is not an array, records a turn that throws
	 * as a failed infra-error case (stored without the infraError flag), runs
	 * every turn as the agent's owner with no skill-set override, and sums every
	 * case's usage and exposed skills into the one audit entry.
	 *
	 * @return void
	 */
	public function testPlainRunRecordsEachCaseTurnAndItsUsage(): void {
		$turns = [];
		$schedule = $this->createMock(ScheduleService::class);
		$schedule->method('isOrganisationEngaged')->willReturn(false);
		$schedule->method('runAgentAsOwner')->willReturnCallback(
			function (string $owner, string $agentId, string $prompt, string $organisation = '', bool $dryRun = false, bool $forceOwner = false, ?ObjectEntity $anchor = null, ?array $skillSetOverride = null) use (&$turns): string {
				$turns[] = [$owner, $agentId, $prompt, $organisation, $skillSetOverride];
				if ($prompt === 'a') {
					throw new RuntimeException('no provider');
				}

				return 'output MARKER';
			}
		);
		$schedule->method('getLastRunUsage')->willReturnOnConsecutiveCalls(
			['promptTokens' => 4, 'completionTokens' => 2],
			['promptTokens' => 6]
		);
		$schedule->method('getLastRunSkillsUsed')->willReturnOnConsecutiveCalls(['skill-x'], ['skill-y', 'skill-x']);

		$objectService = new RecordingObjectService();
		$service = $this->service(objectService: $objectService, schedule: $schedule, scoring: $this->scoring());
		$cases = $this->cases(3);
		array_splice($cases, 1, 0, ['not-a-case']);

		$result = $service->run($this->dataset($cases), $this->agent());

		$this->assertSame('failed', $result['status']);
		$this->assertSame((2 / 3), $result['passRate']);
		$this->assertSame(
			[
				['alice', 'agent-uuid', 'a', 'org-a', null],
				['alice', 'agent-uuid', 'b', 'org-a', null],
				['alice', 'agent-uuid', 'c', 'org-a', null],
			],
			$turns
		);
		$results = $objectService->savesFor('evalrun')[0]['object']['results'];
		$this->assertSame(
			[
				'caseIndex' => 0,
				'prompt' => 'a',
				'expectationType' => 'contains',
				'actualOutput' => '',
				'passed' => false,
				'errorMessage' => 'Agent run failed: no provider',
				'score' => null,
				'judgeRationale' => null,
			],
			$results[0]
		);
		$this->assertSame([0, 2, 3], array_column($results, 'caseIndex'));
		$this->assertSame(['promptTokens' => 10, 'completionTokens' => 2], $this->auditContexts[0]['usage']);
		$this->assertSame(['skill-x', 'skill-y'], $this->auditContexts[0]['skillsUsed']);

	}//end testPlainRunRecordsEachCaseTurnAndItsUsage()

	/**
	 * A completed joint run writes in this order: the run, its audit entry, the
	 * fail captures (the capture job reads the trace from that entry), then the
	 * l5 evidence. Every turn runs as the agent's owner.
	 *
	 * @return void
	 */
	public function testCompletedJointRunWritesRunAuditCapturesThenEvidence(): void {
		$overrides = [];
		$this->owners = [];
		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => []]),
				'skill-a' => $this->entity('skill-a', ['name' => 'skill-a', 'state' => 'active']),
			]
		);
		$service = $this->service(
			objectService: $objectService,
			schedule: $this->scheduleService($overrides),
			scoring: $this->scoring(failEvenWithMarker: ['b'])
		);

		$result = $service->run($this->dataset($this->cases(2), skillRefs: ['skill-a']), $this->agent(), baseline: true);

		$this->assertSame('completed', $result['status']);
		$this->assertSame(['save:evalrun', 'audit', 'job', 'save:agentskill'], $this->events);
		$this->assertSame(['alice', 'alice', 'alice', 'alice'], $this->owners);

	}//end testCompletedJointRunWritesRunAuditCapturesThenEvidence()

	/**
	 * A completed per-skill run with one failing case writes the run, the audit
	 * entry, the one fail capture, then one l5 write per linked skill.
	 *
	 * @return void
	 */
	public function testCompletedPerSkillRunWritesRunAuditCaptureThenEvidence(): void {
		$overrides = [];
		$this->owners = [];
		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => [], 'evalBaselineMode' => 'per-skill']),
				'skill-m' => $this->entity('skill-m', ['name' => 'marker-skill', 'state' => 'active']),
				'skill-i' => $this->entity('skill-i', ['name' => 'inert-skill', 'state' => 'active']),
			]
		);
		$service = $this->service(
			objectService: $objectService,
			schedule: $this->scheduleService($overrides, markerSkill: 'skill-m'),
			scoring: $this->scoring(failEvenWithMarker: ['b'])
		);

		$result = $service->run($this->dataset($this->cases(2), skillRefs: ['skill-m', 'skill-i']), $this->agent(), baseline: true);

		$this->assertSame('completed', $result['status']);
		$this->assertSame(['save:evalrun', 'audit', 'job', 'save:agentskill', 'save:agentskill'], $this->events);
		$this->assertSame(array_fill(0, 6, 'alice'), $this->owners);

	}//end testCompletedPerSkillRunWritesRunAuditCaptureThenEvidence()

	/**
	 * Without an override, a paired run and a plain run both apply the app's
	 * eval.regressionThresholdPercent setting.
	 *
	 * @return void
	 */
	public function testTheAppThresholdSettingAppliesWithoutAnOverride(): void {
		$this->configuredThreshold = '25';
		$overrides = [];
		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => []]),
				'skill-a' => $this->entity('skill-a', ['name' => 'skill-a', 'state' => 'active']),
			]
		);
		$service = $this->service(objectService: $objectService, schedule: $this->scheduleService($overrides));
		$service->run($this->dataset($this->cases(1), skillRefs: ['skill-a']), $this->agent(), baseline: true);
		$this->assertSame(25, $objectService->savesFor('evalrun')[0]['object']['regressionThresholdPercent']);

		$plainStore = new RecordingObjectService();
		$plain = $this->service(objectService: $plainStore, schedule: $this->scheduleService($overrides));
		$plain->run($this->dataset($this->cases(1)), $this->agent());
		$this->assertSame(25, $plainStore->savesFor('evalrun')[0]['object']['regressionThresholdPercent']);

	}//end testTheAppThresholdSettingAppliesWithoutAnOverride()

	/**
	 * A plain run's override of 0 wins over the app setting.
	 *
	 * @return void
	 */
	public function testAPlainRunKeepsAZeroThresholdOverride(): void {
		$this->configuredThreshold = '25';
		$overrides = [];
		$objectService = new RecordingObjectService();
		$service = $this->service(objectService: $objectService, schedule: $this->scheduleService($overrides));

		$service->run($this->dataset($this->cases(1)), $this->agent(), regressionThresholdOverride: 0);

		$this->assertSame(0, $objectService->savesFor('evalrun')[0]['object']['regressionThresholdPercent']);

	}//end testAPlainRunKeepsAZeroThresholdOverride()

	/**
	 * An infrastructure error in the WITH half alone fails the run: no skill is
	 * written and no l5 evidence lands.
	 *
	 * @return void
	 */
	public function testAnInfraErrorInTheWithHalfFailsTheRunAndWritesNoEvidence(): void {
		$this->owners = [];
		$schedule = $this->createMock(ScheduleService::class);
		$schedule->method('isOrganisationEngaged')->willReturn(false);
		$schedule->method('getLastRunUsage')->willReturn([]);
		$schedule->method('getLastRunSkillsUsed')->willReturn(['skill-a']);
		$schedule->method('runAgentAsOwner')->willReturnCallback(
			function (string $owner, string $agentId, string $prompt, string $organisation = '', bool $dryRun = false, bool $forceOwner = false, ?ObjectEntity $anchor = null, ?array $skillSetOverride = null): string {
				$this->owners[] = $owner;
				if (in_array('skill-a', ($skillSetOverride ?? []), true) === true) {
					throw new RuntimeException('provider down for the with half');
				}

				return 'plain output';
			}
		);

		$objectService = new RecordingObjectService(
			objects: [
				'agent-uuid' => $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => []]),
				'skill-a' => $this->entity('skill-a', ['name' => 'skill-a', 'state' => 'active']),
			]
		);
		$service = $this->service(objectService: $objectService, schedule: $schedule);

		$result = $service->run($this->dataset($this->cases(2), skillRefs: ['skill-a']), $this->agent(), baseline: true);

		$this->assertSame('failed', $result['status']);
		$this->assertSame('failed', $objectService->savesFor('evalrun')[0]['object']['status']);
		$this->assertCount(0, $objectService->savesFor('agentskill'));
		$this->assertNotContains('save:agentskill', $this->events);
		$this->assertSame(['alice', 'alice', 'alice', 'alice'], $this->owners);

	}//end testAnInfraErrorInTheWithHalfFailsTheRunAndWritesNoEvidence()

	/**
	 * Golden: every draft-comparison variant (organisation, kill-switch, budget,
	 * installed skills, case shape, an infra error or a scoring failure in either
	 * half) writes, calls and returns exactly what was recorded. Each scenario is
	 * compared on its own. GOLDEN_RECORD=1 rewrites the fixture.
	 *
	 * @return void
	 */
	public function testDraftComparisonVariantsMatchTheRecordedFixture(): void {
		$fixture = __DIR__ . '/../../Fixtures/Golden/eval-draft-comparison.json';
		$actual = [];
		foreach (['org-a', ''] as $organisation) {
			foreach (['killswitch', 'budget'] as $gate) {
				$scenario = ['organisation' => $organisation, 'gate' => $gate, 'installed' => [], 'cases' => 1, 'fault' => 'none'];
				$actual[(string)json_encode($scenario)] = $this->draftComparisonTrace(scenario: $scenario);
			}

			foreach ([null, [], ['skill-other'], ['skill-1', 'skill-other']] as $installed) {
				foreach ([0, 1, 3, 'scalar', 'mixed'] as $cases) {
					foreach (['none', 'active-infra', 'draft-infra', 'draft-throws'] as $fault) {
						$scenario = ['organisation' => $organisation, 'gate' => 'open', 'installed' => $installed, 'cases' => $cases, 'fault' => $fault];
						$actual[(string)json_encode($scenario)] = $this->draftComparisonTrace(scenario: $scenario);
					}
				}
			}
		}

		$actual = json_decode(json_encode($actual, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped('Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $key => $trace) {
			$this->assertSame($trace, $actual[$key], $key);
		}
	}//end testDraftComparisonVariantsMatchTheRecordedFixture()

	/**
	 * Runs one draft-comparison scenario and returns everything it wrote, called
	 * and returned, in call order, with timestamps and durations masked.
	 *
	 * @param array<string, mixed> $scenario The scenario values.
	 *
	 * @return array<string, mixed>
	 */
	private function draftComparisonTrace(array $scenario): array {
		$calls = [];
		$draftActive = false;

		$objects = [];
		if ($scenario['installed'] !== null) {
			$objects['agent-uuid'] = $this->entity('agent-uuid', ['name' => 'demo-agent', 'skillInstalls' => $scenario['installed']]);
		}

		$objectService = new RecordingObjectService(objects: $objects);
		$objectService->onSave = function (?string $schema) use (&$calls): void {
			$calls[] = ['save' => $schema];
		};

		$fault = $scenario['fault'];
		$lastSkills = [];
		$schedule = $this->createMock(ScheduleService::class);
		$schedule->method('isOrganisationEngaged')->willReturnCallback(
			function (string $organisation) use (&$calls, $scenario): bool {
				$calls[] = ['isOrganisationEngaged' => $organisation];
				return $scenario['gate'] === 'killswitch';
			}
		);
		$schedule->method('runAgentAsOwner')->willReturnCallback(
			function (string $owner, string $agentId, string $prompt, string $organisation = '', bool $dryRun = false, bool $forceOwner = false, ?ObjectEntity $anchor = null, ?array $skillSetOverride = null) use (&$calls, &$draftActive, &$lastSkills, $fault): string {
				$calls[] = ['runAgentAsOwner' => [$owner, $agentId, $prompt, $organisation, $skillSetOverride, $draftActive]];
				$lastSkills = ($skillSetOverride ?? []);
				if ($prompt === 'a' && (($fault === 'active-infra' && $draftActive === false) || ($fault === 'draft-infra' && $draftActive === true))) {
					throw new RuntimeException('no provider');
				}

				$output = in_array('skill-other', $lastSkills, true) === true ? 'MARKER' : 'plain';
				return ($draftActive === true) ? 'DRAFT ' . $output : $output;
			}
		);
		$schedule->method('getLastRunUsage')->willReturnCallback(
			static function () use (&$lastSkills): array {
				return ['promptTokens' => (10 + count($lastSkills)), 'completionTokens' => 3];
			}
		);
		$schedule->method('getLastRunSkillsUsed')->willReturnCallback(
			static function () use (&$lastSkills): array {
				return array_values($lastSkills);
			}
		);

		$budget = $this->createMock(BudgetService::class);
		$budget->method('isBlocked')->willReturnCallback(
			function (string $organisation, string $agentId) use (&$calls, $scenario): bool {
				$calls[] = ['isBlocked' => [$organisation, $agentId]];
				return $scenario['gate'] === 'budget';
			}
		);
		$budget->method('checkAndDeliverWarnings')->willReturnCallback(
			function (string $organisation, string $agentId) use (&$calls): void {
				$calls[] = ['checkAndDeliverWarnings' => [$organisation, $agentId]];
			}
		);

		$scoring = $this->createMock(EvalScoringService::class);
		$scoring->method('score')->willReturnCallback(
			static function (array $case, string $actualOutput, string $organisation) use ($fault): array {
				$prompt = (string)($case['prompt'] ?? '');
				if ($fault === 'draft-throws' && $prompt === 'a' && str_contains($actualOutput, 'DRAFT') === true) {
					throw new RuntimeException('scoring failed');
				}

				return [
					'passed' => (str_contains($actualOutput, 'MARKER') === true || (str_contains($actualOutput, 'DRAFT') === true && $prompt === 'b')),
					'errorMessage' => null,
					'score' => null,
					'judgeRationale' => $organisation,
				];
			}
		);

		$contextAssembler = $this->createMock(ContextAssembler::class);
		$contextAssembler->method('setTransientSkillContentOverride')->willReturnCallback(
			function (?array $override) use (&$calls, &$draftActive): void {
				$calls[] = ['setTransientSkillContentOverride' => $override];
				$draftActive = ($override !== null);
			}
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event) use (&$calls): AuditTrail {
				$calls[] = ['audit' => [$event->object->getUuid(), $event->action, $event->context]];
				$entry = new AuditTrail();
				$entry->setUuid('audit-uuid');
				return $entry;
			}
		);

		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnArgument(0);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument = null) use (&$calls): void {
				$calls[] = ['job' => [$job, $argument]];
			}
		);

		$service = new EvalRunService(
			objectService: $objectService,
			scheduleService: $schedule,
			budgetService: $budget,
			scoringService: $scoring,
			auditTrailMapper: $audit,
			redactionService: $redaction,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			contextAssembler: $contextAssembler,
			skillVersionService: $this->createMock(SkillVersionService::class),
			jobList: $jobList,
		);

		$cases = match ($scenario['cases']) {
			'scalar' => 'not-a-list',
			'mixed' => [$this->cases(1)[0], 'not-a-case', ['prompt' => 'b', 'expectationType' => 'contains', 'expectedSubstring' => 'MARKER']],
			default => $this->cases($scenario['cases']),
		};
		$dataset = $this->entity('dataset-uuid', ['name' => 'demo', 'cases' => $cases]);

		$agent = new Agent();
		$agent->setUuid('agent-uuid');
		$agent->setOwner('alice');
		$agent->setOrganisation($scenario['organisation']);

		try {
			$result = $service->runDraftComparison(
				dataset: $dataset,
				agent: $agent,
				skillId: 'skill-1',
				draftContent: ['name' => 's', 'description' => 'd', 'body' => 'DRAFT']
			);
		} catch (RuntimeException $e) {
			$result = ['exception' => $e->getMessage()];
		}

		return $this->masked(
			value: [
				'result' => $result,
				'calls' => $calls,
				'saves' => $objectService->saves,
			]
		);
	}//end draftComparisonTrace()
}//end class
