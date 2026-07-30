<?php

/**
 * Hermiq EvalRunService unit tests.
 *
 * Covers the governance gates reused from ScheduleService (kill-switch, budget hard cap),
 * the non-delivering per-case execution + scoring, pass-rate computation, the
 * one-bad-case-does-not-abort contract, and the regression gate (agent-evals).
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
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md
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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * EvalRunService orchestration tests (agent-evals).
 *
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md
 */
class EvalRunServiceTest extends TestCase
{

    /**
     * The IJobList mock the built service enqueues capture jobs on
     * (skill-learnings Decision 8 producer assertions).
     *
     * @var IJobList&MockObject
     */
    private IJobList&MockObject $jobList;

    /**
     * An ObjectService stub: findAll() returns the given prior runs (regression gate),
     * saveObject() echoes back an ObjectEntity carrying a uuid and the saved data.
     *
     * @param array<int, ObjectEntity> $priorRuns Prior EvalRun objects for the regression gate.
     *
     * @return ObjectService
     */
    private function objectService(array $priorRuns=[]): ObjectService
    {
        return new class ($priorRuns) extends ObjectService {
            /**
             * @param array<int, ObjectEntity> $priorRuns Prior runs.
             */
            public function __construct(private array $priorRuns)
            {
            }//end __construct()

            public function setRegister(mixed $register): static
            {
                return $this;
            }//end setRegister()

            public function setSchema(mixed $schema): static
            {
                return $this;
            }//end setSchema()

            public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                return $this->priorRuns;
            }//end findAll()

            public function saveObject(
                ObjectEntity | array $object,
                ?array $extend=[],
                mixed $register=null,
                mixed $schema=null,
                ?string $uuid=null,
                bool $_rbac=true,
                bool $_multitenancy=true,
                bool $silent=false,
                ?array $uploadedFiles=null,
                ?\OCP\IUser $currentUser=null,
                // openregister#2211 (insert-only saves) added this. A double that
                // drifts from the real signature is a FATAL, not a failed
                // assertion: PHP refuses to declare the class and the whole
                // suite dies before it runs.
                bool $failIfExists=false
            ): ObjectEntity {
                $entity = new ObjectEntity();
                $entity->setUuid('eval-run-uuid');
                $entity->setObject(is_array($object) ? $object : $object->getObject());
                return $entity;
            }//end saveObject()
        };

    }//end objectService()

    /**
     * A target Agent owned by $owner in $organisation.
     *
     * @param string $owner        The agent owner uid.
     * @param string $organisation The agent organisation.
     *
     * @return Agent
     */
    private function agent(string $owner='alice', string $organisation='org-a'): Agent
    {
        // A real entity, not a mock: the real OpenRegister Agent resolves
        // getUuid()/getOwner()/getOrganisation() via Entity MAGIC accessors,
        // which PHPUnit mocks cannot configure when the real class is loaded
        // (CI runs inside a full server tree with OpenRegister installed).
        $agent = new Agent();
        $agent->setUuid('agent-uuid');
        $agent->setOwner($owner);
        $agent->setOrganisation($organisation);
        return $agent;

    }//end agent()

    /**
     * An EvalDataset ObjectEntity carrying the given cases.
     *
     * @param array<int, array<string,mixed>> $cases The dataset's cases.
     *
     * @return ObjectEntity
     */
    private function dataset(array $cases): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('dataset-uuid');
        $entity->setObject(['name' => 'demo', 'cases' => $cases]);
        return $entity;

    }//end dataset()

    /**
     * Build the service with the given collaborators; unspecified ones are permissive mocks.
     *
     * @param ObjectService           $objectService   The (stub) object service.
     * @param ScheduleService|null    $scheduleService The schedule service mock.
     * @param BudgetService|null      $budgetService   The budget service mock.
     * @param EvalScoringService|null $scoring         The scoring service mock.
     *
     * @return EvalRunService
     */
    private function service(
        ObjectService $objectService,
        ?ScheduleService $scheduleService=null,
        ?BudgetService $budgetService=null,
        ?EvalScoringService $scoring=null
    ): EvalRunService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default='') => $default
        );

        $redaction = $this->createMock(RedactionService::class);
        $redaction->method('redact')->willReturnArgument(0);

        $this->jobList = $this->createMock(IJobList::class);

        return new EvalRunService(
            objectService: $objectService,
            scheduleService: ($scheduleService ?? $this->createMock(ScheduleService::class)),
            budgetService: ($budgetService ?? $this->createMock(BudgetService::class)),
            scoringService: ($scoring ?? $this->createMock(EvalScoringService::class)),
            auditTrailMapper: $this->createMock(AuditTrailMapper::class),
            redactionService: $redaction,
            appConfig: $appConfig,
            logger: $this->createMock(LoggerInterface::class),
            contextAssembler: $this->createMock(ContextAssembler::class),
            skillVersionService: $this->createMock(SkillVersionService::class),
            jobList: $this->jobList,
        );

    }//end service()

    /**
     * The kill-switch blocks the run before any agent turn; status is blocked_killswitch.
     *
     * @return void
     */
    public function testKillSwitchBlocksTheRun(): void
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('isOrganisationEngaged')->willReturn(true);
        $schedule->expects($this->never())->method('runAgentAsOwner');

        $service = $this->service(
            objectService: $this->objectService(),
            scheduleService: $schedule
        );

        $result = $service->run($this->dataset([['prompt' => 'x', 'expectationType' => 'contains', 'expectedSubstring' => 'y']]), $this->agent());

        $this->assertSame('blocked_killswitch', $result['status']);

    }//end testKillSwitchBlocksTheRun()

    /**
     * A budget hard cap blocks the run before any agent turn; status is blocked_budget.
     *
     * @return void
     */
    public function testBudgetHardCapBlocksTheRun(): void
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('isOrganisationEngaged')->willReturn(false);
        $schedule->expects($this->never())->method('runAgentAsOwner');

        $budget = $this->createMock(BudgetService::class);
        $budget->method('isBlocked')->willReturn(true);

        $service = $this->service(
            objectService: $this->objectService(),
            scheduleService: $schedule,
            budgetService: $budget
        );

        $result = $service->run($this->dataset([['prompt' => 'x', 'expectationType' => 'contains', 'expectedSubstring' => 'y']]), $this->agent());

        $this->assertSame('blocked_budget', $result['status']);

    }//end testBudgetHardCapBlocksTheRun()

    /**
     * A clean two-case run (one pass, one fail) computes a 0.5 pass rate through the
     * agent's real engine path, and never delivers.
     *
     * @return void
     */
    public function testTwoCaseRunComputesPassRate(): void
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('isOrganisationEngaged')->willReturn(false);
        $schedule->expects($this->exactly(2))->method('runAgentAsOwner')->willReturn('agent output');
        $schedule->method('getLastRunUsage')->willReturn(['promptTokens' => 10, 'completionTokens' => 5]);

        $scoring = $this->createMock(EvalScoringService::class);
        $scoring->method('score')->willReturnOnConsecutiveCalls(
            ['passed' => true, 'errorMessage' => null, 'score' => null, 'judgeRationale' => null],
            ['passed' => false, 'errorMessage' => null, 'score' => null, 'judgeRationale' => null]
        );

        $service = $this->service(
            objectService: $this->objectService(),
            scheduleService: $schedule,
            scoring: $scoring
        );

        $result = $service->run(
            $this->dataset(
                    [
                        ['prompt' => 'a', 'expectationType' => 'contains', 'expectedSubstring' => 'x'],
                        ['prompt' => 'b', 'expectationType' => 'contains', 'expectedSubstring' => 'y'],
                    ]
                    ),
            $this->agent()
        );

        $this->assertSame('completed', $result['status']);
        $this->assertSame(0.5, $result['passRate']);
        $this->assertSame('not_applicable', $result['regressionGateResult']);
        $this->assertSame('eval-run-uuid', $result['evalRunId']);

    }//end testTwoCaseRunComputesPassRate()

    /**
     * An agent turn that throws is recorded as a failed case (infraError) and the run
     * is marked failed, but scoring of the OTHER case still happens — one bad case
     * never aborts the run.
     *
     * @return void
     */
    public function testAgentTurnFailureDoesNotAbortRun(): void
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('isOrganisationEngaged')->willReturn(false);
        $schedule->method('getLastRunUsage')->willReturn([]);
        $schedule->method('runAgentAsOwner')->willReturnOnConsecutiveCalls(
            $this->throwException(new RuntimeException('no provider')),
            'second output'
        );

        $scoring = $this->createMock(EvalScoringService::class);
        // Only the second (successful) case reaches scoring.
        $scoring->expects($this->once())->method('score')->willReturn(
            ['passed' => true, 'errorMessage' => null, 'score' => null, 'judgeRationale' => null]
        );

        $service = $this->service(
            objectService: $this->objectService(),
            scheduleService: $schedule,
            scoring: $scoring
        );

        $result = $service->run(
            $this->dataset(
                    [
                        ['prompt' => 'a', 'expectationType' => 'contains', 'expectedSubstring' => 'x'],
                        ['prompt' => 'b', 'expectationType' => 'contains', 'expectedSubstring' => 'y'],
                    ]
                    ),
            $this->agent()
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame(0.5, $result['passRate']);

    }//end testAgentTurnFailureDoesNotAbortRun()

    /**
     * Skill-learnings Decision 8 producer: a FAILING case of a COMPLETED run that
     * exercised skills enqueues the capture job carrying the failed-eval marker
     * `<evalRunUuid>#<caseIndex>` and the run's exercised skills.
     *
     * @return void
     */
    public function testFailingCaseOfCompletedRunEnqueuesEvalFailCapture(): void
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('isOrganisationEngaged')->willReturn(false);
        $schedule->method('runAgentAsOwner')->willReturn('agent output');
        $schedule->method('getLastRunUsage')->willReturn([]);
        $schedule->method('getLastRunSkillsUsed')->willReturn(['skill-1']);

        $scoring = $this->createMock(EvalScoringService::class);
        $scoring->method('score')->willReturnOnConsecutiveCalls(
            ['passed' => true, 'errorMessage' => null, 'score' => null, 'judgeRationale' => null],
            ['passed' => false, 'errorMessage' => 'nope', 'score' => null, 'judgeRationale' => null]
        );

        $service = $this->service(
            objectService: $this->objectService(),
            scheduleService: $schedule,
            scoring: $scoring
        );

        $this->jobList->expects($this->once())->method('add')->with(
            $this->stringContains('SkillLearningsCaptureJob'),
            $this->callback(
                static function (array $payload): bool {
                    return ($payload['evalFail'] ?? '') === 'eval-run-uuid#1'
                        && ($payload['runId'] ?? '') === 'eval-run-uuid'
                        && ($payload['scheduleUuid'] ?? '') === 'eval-run-uuid'
                        && ($payload['skillIds'] ?? []) === ['skill-1']
                        && ($payload['agentId'] ?? '') === 'agent-uuid';
                }
            )
        );

        $result = $service->run(
            $this->dataset(
                    [
                        ['prompt' => 'a', 'expectationType' => 'contains', 'expectedSubstring' => 'x'],
                        ['prompt' => 'b', 'expectationType' => 'contains', 'expectedSubstring' => 'y'],
                    ]
                    ),
            $this->agent()
        );

        $this->assertSame('completed', $result['status']);

    }//end testFailingCaseOfCompletedRunEnqueuesEvalFailCapture()

    /**
     * A passing-only completed run enqueues NO eval-fail capture job — the marker
     * exists only for failing cases.
     *
     * @return void
     */
    public function testPassingOnlyRunDoesNotEnqueueEvalFailCapture(): void
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('isOrganisationEngaged')->willReturn(false);
        $schedule->method('runAgentAsOwner')->willReturn('agent output');
        $schedule->method('getLastRunUsage')->willReturn([]);
        $schedule->method('getLastRunSkillsUsed')->willReturn(['skill-1']);

        $scoring = $this->createMock(EvalScoringService::class);
        $scoring->method('score')->willReturn(
            ['passed' => true, 'errorMessage' => null, 'score' => null, 'judgeRationale' => null]
        );

        $service = $this->service(
            objectService: $this->objectService(),
            scheduleService: $schedule,
            scoring: $scoring
        );

        $this->jobList->expects($this->never())->method('add');

        $result = $service->run(
            $this->dataset([['prompt' => 'a', 'expectationType' => 'contains', 'expectedSubstring' => 'x']]),
            $this->agent()
        );

        $this->assertSame('completed', $result['status']);

    }//end testPassingOnlyRunDoesNotEnqueueEvalFailCapture()

    /**
     * A draft-comparison run NEVER enqueues learnings capture, failing cases and
     * exercised skills notwithstanding — a draft's transient content must never
     * write learnings (skill-self-improvement isolation).
     *
     * @return void
     */
    public function testDraftComparisonRunDoesNotEnqueueEvalFailCapture(): void
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('isOrganisationEngaged')->willReturn(false);
        $schedule->method('runAgentAsOwner')->willReturn('agent output');
        $schedule->method('getLastRunUsage')->willReturn([]);
        $schedule->method('getLastRunSkillsUsed')->willReturn(['skill-1']);

        $scoring = $this->createMock(EvalScoringService::class);
        // Both halves fail their single case — still no capture enqueue.
        $scoring->method('score')->willReturn(
            ['passed' => false, 'errorMessage' => 'nope', 'score' => null, 'judgeRationale' => null]
        );

        $service = $this->service(
            objectService: $this->objectService(),
            scheduleService: $schedule,
            scoring: $scoring
        );

        $this->jobList->expects($this->never())->method('add');

        $result = $service->runDraftComparison(
            dataset: $this->dataset([['prompt' => 'a', 'expectationType' => 'contains', 'expectedSubstring' => 'x']]),
            agent: $this->agent(),
            skillId: 'skill-1',
            draftContent: ['name' => 's', 'description' => 'd', 'body' => 'DRAFT']
        );

        $this->assertSame('draft-comparison', $result['status']);

    }//end testDraftComparisonRunDoesNotEnqueueEvalFailCapture()
}//end class
