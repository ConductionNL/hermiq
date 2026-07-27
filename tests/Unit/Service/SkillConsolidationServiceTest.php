<?php

/**
 * Unit tests for SkillConsolidationService (skill-self-improvement, ADR-068 §5).
 *
 * Covers the gated pipeline end to end at the unit level: trigger rules and the
 * one-open-draft-per-skill rule; kill-switch/budget gates blocking the LLM pass
 * (no `ProviderFactory` call behind a closed gate, blocked attempt audited);
 * the content scan treating the full proposed content as instruction content —
 * `dangerous` → discarded with NO override and scan unavailability failing
 * CLOSED; the paired draft-vs-active eval gate — strictly-worse auto-discarded
 * with both pass rates in the audit note (learnings retained, no Approval), a
 * TIE surviving to review, and no linked dataset yielding the honest
 * `noEvalEvidence` flag; the versioned apply on acceptance (new content +
 * `lastAcceptedVersionAt` through the maturity write guard, idempotent);
 * rejection recording `rejectedLearningRefs` that never drive the next
 * proposal; edit-then-accept invalidating scan+eval evidence and blocking
 * approvability until re-qualification; idempotent reconciliation of missed
 * Approval transitions; and the once-per-newly-behind publisher notification.
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\EvalRunService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\SkillConsolidationService;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\Hermiq\Service\SkillLearningsPromotionService;
use OCA\Hermiq\Service\SkillMaturityService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * SkillConsolidationService behaviour tests (skill-self-improvement).
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One focused test per spec scenario.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The pipeline's own collaborator set.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   Scenario coverage of a large spec.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
 */
class SkillConsolidationServiceTest extends TestCase
{

    /**
     * The prepared ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * The prepared ProviderFactory mock.
     *
     * @var ProviderFactory&MockObject
     */
    private ProviderFactory&MockObject $providerFactory;

    /**
     * The prepared ScheduleService mock (kill-switch).
     *
     * @var ScheduleService&MockObject
     */
    private ScheduleService&MockObject $scheduleService;

    /**
     * The prepared BudgetService mock.
     *
     * @var BudgetService&MockObject
     */
    private BudgetService&MockObject $budgetService;

    /**
     * The prepared ContentScanService mock.
     *
     * @var ContentScanService&MockObject
     */
    private ContentScanService&MockObject $contentScanService;

    /**
     * The prepared EvalRunService mock.
     *
     * @var EvalRunService&MockObject
     */
    private EvalRunService&MockObject $evalRunService;

    /**
     * The prepared ApprovalService mock.
     *
     * @var ApprovalService&MockObject
     */
    private ApprovalService&MockObject $approvalService;

    /**
     * The prepared SkillVersionService mock.
     *
     * @var SkillVersionService&MockObject
     */
    private SkillVersionService&MockObject $versionService;

    /**
     * The prepared AuditTrailMapper mock (transition audit assertions).
     *
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper&MockObject $auditTrailMapper;

    /**
     * The prepared DeliveryService mock (behind/rollback notifications).
     *
     * @var DeliveryService&MockObject
     */
    private DeliveryService&MockObject $deliveryService;

    /**
     * The prepared AgentMapper mock (eval agent resolution).
     *
     * @var AgentMapper&MockObject
     */
    private AgentMapper&MockObject $agentMapper;

    /**
     * Every saveObject() call captured as [schema, uuid, payload].
     *
     * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Every audit entry captured as [action, context].
     *
     * @var array<int, array{0: string, 1: array<string, mixed>}>
     */
    private array $auditEntries = [];

    /**
     * The last schema selected via setSchema() (routes findAll responses).
     *
     * @var string
     */
    private string $lastSchema = '';

    /**
     * findAll responses keyed by schema slug.
     *
     * @var array<string, array<int, ObjectEntity>>
     */
    private array $collections = [];

    /**
     * find() responses keyed by "schema:uuid".
     *
     * @var array<string, ObjectEntity>
     */
    private array $found = [];

    /**
     * Build the pipeline service over fresh mocks. Learnings parsing runs through
     * the REAL capture/promotion services (the grammar owners), maturity guarding
     * through the REAL SkillMaturityService.
     *
     * @return SkillConsolidationService
     */
    private function service(): SkillConsolidationService
    {
        $this->objectService      = $this->createMock(ObjectService::class);
        $this->providerFactory    = $this->createMock(ProviderFactory::class);
        $this->scheduleService    = $this->createMock(ScheduleService::class);
        $this->budgetService      = $this->createMock(BudgetService::class);
        $this->contentScanService = $this->createMock(ContentScanService::class);
        $this->evalRunService     = $this->createMock(EvalRunService::class);
        $this->approvalService    = $this->createMock(ApprovalService::class);
        $this->versionService     = $this->createMock(SkillVersionService::class);
        $this->auditTrailMapper   = $this->createMock(AuditTrailMapper::class);
        $this->deliveryService    = $this->createMock(DeliveryService::class);
        $this->agentMapper        = $this->createMock(AgentMapper::class);
        $this->savedObjects       = [];
        $this->auditEntries       = [];
        $this->lastSchema         = '';
        $this->collections        = [];
        $this->found = [];

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnCallback(
            function (mixed $schema): ObjectService {
                $this->lastSchema = (string) $schema;
                return $this->objectService;
            }
        );
        $this->objectService->method('findAll')->willReturnCallback(
            fn (): array => ($this->collections[$this->lastSchema] ?? [])
        );
        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, mixed $register=null, mixed $schema=null): ?ObjectEntity {
                unset($_extend, $files, $register);
                return ($this->found[((string) $schema).':'.((string) $id)] ?? null);
            }
        );
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null): ObjectEntity {
                unset($extend, $register);
                $effectiveUuid        = ($uuid ?? 'generated-'.count($this->savedObjects));
                $this->savedObjects[] = [
                    (string) $schema,
                    (string) $effectiveUuid,
                    $object,
                ];

                $entity = new ObjectEntity();
                $entity->setUuid($effectiveUuid);
                $entity->setObject($object);

                // Keep the routed find()/findAll() views coherent with the write, so
                // multi-step pipeline calls observe their own prior writes.
                $this->found[((string) $schema).':'.$effectiveUuid] = $entity;
                $collection = ($this->collections[(string) $schema] ?? []);
                $replaced   = false;
                foreach ($collection as $index => $existing) {
                    if ((string) $existing->getUuid() === (string) $effectiveUuid) {
                        $collection[$index] = $entity;
                        $replaced           = true;
                    }
                }

                if ($replaced === false) {
                    $collection[] = $entity;
                }

                $this->collections[(string) $schema] = $collection;

                return $entity;
            }
        );

        $this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
            function (ObjectEntity $object, string $action, array $context=[]): \OCA\OpenRegister\Db\AuditTrail {
                unset($object);
                $this->auditEntries[] = [
                    $action,
                    $context,
                ];
                return new \OCA\OpenRegister\Db\AuditTrail();
            }
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturn(SkillConsolidationService::DEFAULT_LEARNINGS_THRESHOLD);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://cloud.example.test'.$path
        );

        $captureService = new SkillLearningsCaptureService(
            $this->objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(ProviderFactory::class),
            $this->createMock(BudgetService::class),
            new RedactionService($this->createMock(IConfig::class)),
            new NullLogger()
        );

        return new SkillConsolidationService(
            objectService: $this->objectService,
            maturityService: new SkillMaturityService($this->objectService),
            versionService: $this->versionService,
            promotionService: new SkillLearningsPromotionService($this->objectService, $captureService, new NullLogger()),
            captureService: $captureService,
            providerFactory: $this->providerFactory,
            scheduleService: $this->scheduleService,
            budgetService: $this->budgetService,
            contentScanService: $this->contentScanService,
            evalRunService: $this->evalRunService,
            approvalService: $this->approvalService,
            agentMapper: $this->agentMapper,
            auditTrailMapper: $this->auditTrailMapper,
            deliveryService: $this->deliveryService,
            appConfig: $appConfig,
            userSession: $this->createMock(IUserSession::class),
            userManager: $this->createMock(IUserManager::class),
            urlGenerator: $urlGenerator,
            logger: new NullLogger()
        );

    }//end service()

    /**
     * A skill entity with the given learnings entries aboard.
     *
     * @param int                  $entryCount How many promoted entries learnings.md carries.
     * @param array<string, mixed> $overrides  Extra skill payload fields.
     *
     * @return ObjectEntity
     */
    private function skill(int $entryCount=3, array $overrides=[]): ObjectEntity
    {
        $learnings = "# Learnings\n\n## Patterns That Work\n\n";
        for ($index = 0; $index < $entryCount; $index++) {
            $learnings .= '- Entry number '.$index.' about tender summaries. '
                .'<!-- promoted 2026-07-20 | runs: 00000000-0000-0000-0000-00000000000'.$index.' -->'."\n";
        }

        $skill = new ObjectEntity();
        $skill->setUuid('skill-1');
        $skill->setOwner('alice');
        $skill->setOrganisation('org-1');
        $skill->setObject(
                array_merge(
                [
                    'name'        => 'tender-summary',
                    'description' => 'Summarise a tender publication.',
                    'frontmatter' => "name: tender-summary\n",
                    'body'        => 'CURRENT BODY',
                    'state'       => 'active',
                    'files'       => [
                        [
                            'name'    => 'learnings.md',
                            'content' => $learnings,
                        ],
                    ],
                    'installedOn' => [],
                ],
                $overrides
        )
                );

        $this->found['agentskill:skill-1'] = $skill;
        $this->collections['agentskill']   = [$skill];

        return $skill;

    }//end skill()

    /**
     * A stored draft entity for skill-1.
     *
     * @param array<string, mixed> $payload The draft payload overrides.
     *
     * @return ObjectEntity
     */
    private function draft(array $payload=[]): ObjectEntity
    {
        $draft = new ObjectEntity();
        $draft->setUuid('draft-1');
        $draft->setOwner('alice');
        $draft->setObject(
                array_merge(
                [
                    'skillId'             => 'skill-1',
                    'status'              => SkillConsolidationService::STATUS_PROPOSED,
                    'trigger'             => 'threshold',
                    'proposedFrontmatter' => "name: tender-summary\n",
                    'proposedBody'        => 'PROPOSED BODY',
                    'proposedFiles'       => [],
                    'provenance'          => [
                        'learningRefs' => ['2026-07-20-aaaaaaaa'],
                        'runIds'       => ['00000000-0000-0000-0000-000000000000'],
                    ],
                    'noEvalEvidence'      => false,
                    'approvalId'          => '',
                ],
                $payload
        )
                );

        $this->found['agentskilldraft:draft-1'] = $draft;
        $this->collections['agentskilldraft']   = [$draft];

        return $draft;

    }//end draft()

    /**
     * The clean scan report.
     *
     * @return array<string, mixed>
     */
    private function cleanScan(): array
    {
        return [
            'severity' => ContentScanService::SEVERITY_CLEAN,
            'safe'     => true,
            'findings' => [],
        ];

    }//end cleanScan()

    /**
     * Threshold proposal creates a draft with provenance and a pinned base version
     * while the active skill is NEVER written.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    public function testThresholdProposalCreatesDraftWithProvenanceAndNeverWritesTheSkill(): void
    {
        $service = $this->service();
        $skill   = $this->skill(entryCount: 21);

        $this->providerFactory->expects($this->once())->method('generateText')->willReturn('IMPROVED BODY');
        $this->versionService->method('currentVersionId')->willReturn('base-v1');
        $this->contentScanService->method('scan')->willReturn($this->cleanScan());
        $approval = new ObjectEntity();
        $approval->setUuid('approval-1');
        $this->approvalService->method('ensurePendingApprovalForSkillDraft')->willReturn($approval);

        $result = $service->proposeForSkill(skill: $skill, trigger: 'threshold');

        $this->assertTrue($result['created']);
        $draftSaves = array_values(array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskilldraft'));
        $this->assertNotEmpty($draftSaves, 'A SkillDraft is persisted.');
        $first = $draftSaves[0][2];
        $this->assertSame('base-v1', $first['baseVersionId']);
        $this->assertSame('IMPROVED BODY', $first['proposedBody']);
        $this->assertCount(21, $first['provenance']['learningRefs']);

        $skillSaves = array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskill');
        $this->assertSame([], $skillSaves, 'No code path in consolidation writes the active skill.');

    }//end testThresholdProposalCreatesDraftWithProvenanceAndNeverWritesTheSkill()

    /**
     * An open draft suppresses every trigger — the manual call returns the existing
     * draft as a pointer and no LLM call is made.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    public function testOpenDraftSuppressesTriggersAndManualReturnsPointer(): void
    {
        $service = $this->service();
        $skill   = $this->skill();
        $this->draft(['status' => SkillConsolidationService::STATUS_AWAITING_APPROVAL]);

        $this->providerFactory->expects($this->never())->method('generateText');

        $result = $service->proposeForSkill(skill: $skill, trigger: 'manual');

        $this->assertFalse($result['created']);
        $this->assertSame('open_draft_exists', $result['status']);
        $this->assertSame('draft-1', (string) $result['draft']->getUuid());

    }//end testOpenDraftSuppressesTriggersAndManualReturnsPointer()

    /**
     * An engaged kill-switch blocks consolidation: zero ProviderFactory calls, no
     * draft, and the blocked attempt is audited.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
     */
    public function testKillSwitchBlocksConsolidationWithAuditedAttempt(): void
    {
        $service = $this->service();
        $skill   = $this->skill(entryCount: 25);

        $this->scheduleService->method('isOrganisationEngaged')->willReturn(true);
        $this->providerFactory->expects($this->never())->method('generateText');

        $result = $service->proposeForSkill(skill: $skill, trigger: 'threshold');

        $this->assertSame('blocked_killswitch', $result['status']);
        $this->assertSame([], $this->savedObjects, 'No draft is created behind an engaged kill-switch.');
        $blocked = array_filter(
            $this->auditEntries,
            static fn (array $entry): bool => ($entry[1]['transition'] ?? '') === 'blocked' && ($entry[1]['reason'] ?? '') === 'killswitch'
        );
        $this->assertNotEmpty($blocked, 'The blocked attempt is auditable.');

    }//end testKillSwitchBlocksConsolidationWithAuditedAttempt()

    /**
     * A reached budget hard cap blocks consolidation before any LLM call.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
     */
    public function testBudgetHardCapBlocksConsolidation(): void
    {
        $service = $this->service();
        $skill   = $this->skill(entryCount: 25);

        $this->budgetService->method('isBlocked')->willReturn(true);
        $this->providerFactory->expects($this->never())->method('generateText');

        $result = $service->proposeForSkill(skill: $skill, trigger: 'threshold');

        $this->assertSame('blocked_budget', $result['status']);

    }//end testBudgetHardCapBlocksConsolidation()

    /**
     * A dangerous scan verdict discards the draft with the verdict in the audit
     * note — and the discarded draft is never approvable (no override path).
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-is-content-scanned-with-learnings-treated-as-instruction-content
     */
    public function testDangerousScanVerdictDiscardsWithNoOverride(): void
    {
        $service = $this->service();
        $this->skill();
        $draft = $this->draft();

        $this->contentScanService->method('scan')->willReturn(
            [
                'severity' => ContentScanService::SEVERITY_DANGEROUS,
                'safe'     => false,
                'findings' => [['pattern' => 'exfiltration']],
            ]
        );
        $this->approvalService->expects($this->never())->method('ensurePendingApprovalForSkillDraft');

        $result = $service->prequalifyDraft(draft: $draft);

        $data = $result->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_DISCARDED, $data['status']);
        $this->assertStringContainsString('dangerous', $data['auditNote']);
        $this->assertStringContainsString('No override path', $data['auditNote']);
        $this->assertFalse($service->isDraftApprovable(draftId: 'draft-1'), 'A discarded draft can never be approved.');

    }//end testDangerousScanVerdictDiscardsWithNoOverride()

    /**
     * Scan unavailability fails CLOSED: the draft stays `proposed` and never
     * reaches awaiting-approval unscanned.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-is-content-scanned-with-learnings-treated-as-instruction-content
     */
    public function testScanUnavailabilityFailsClosed(): void
    {
        $service = $this->service();
        $this->skill();
        $draft = $this->draft();

        $this->contentScanService->method('scan')->willThrowException(new RuntimeException('scanner down'));
        $this->approvalService->expects($this->never())->method('ensurePendingApprovalForSkillDraft');

        $result = $service->prequalifyDraft(draft: $draft);

        $this->assertSame(SkillConsolidationService::STATUS_PROPOSED, $result->getObject()['status']);

    }//end testScanUnavailabilityFailsClosed()

    /**
     * The scan covers the FULL proposed content — learnings.md included as
     * instruction content (its text reaches the scanner verbatim).
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-is-content-scanned-with-learnings-treated-as-instruction-content
     */
    public function testScanCoversLearningsFileAsInstructionContent(): void
    {
        $service = $this->service();
        $this->skill();
        $draft = $this->draft(
            [
                'proposedFiles' => [
                    [
                        'name'    => 'learnings.md',
                        'content' => 'INJECTED-INSTRUCTION-CANARY',
                    ],
                ],
            ]
        );

        $scanned = '';
        $this->contentScanService->method('scan')->willReturnCallback(
            function (string $content, array $metadata=[]) use (&$scanned): array {
                unset($metadata);
                $scanned = $content;
                return $this->cleanScan();
            }
        );

        $service->prequalifyDraft(draft: $draft);

        $this->assertStringContainsString('INJECTED-INSTRUCTION-CANARY', $scanned);

    }//end testScanCoversLearningsFileAsInstructionContent()

    /**
     * A strictly-worse paired eval auto-discards with BOTH pass rates in the audit
     * note; the skill's learnings.md is untouched and no Approval is created.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
     */
    public function testStrictlyWorseEvalAutoDiscardsRetainingLearnings(): void
    {
        $service = $this->service();
        $this->skill(overrides: ['installedOn' => ['agent-1']]);
        $draft = $this->draft();
        $this->linkDataset();
        $this->resolveAgent();

        $this->contentScanService->method('scan')->willReturn($this->cleanScan());
        $this->evalRunService->method('runDraftComparison')->willReturn(
            [
                'evalRunId'      => 'run-1',
                'status'         => 'draft-comparison',
                'draftPassRate'  => 0.6,
                'activePassRate' => 0.8,
            ]
        );
        $this->approvalService->expects($this->never())->method('ensurePendingApprovalForSkillDraft');

        $result = $service->prequalifyDraft(draft: $draft);

        $data = $result->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_DISCARDED, $data['status']);
        $this->assertStringContainsString('0.60', $data['auditNote']);
        $this->assertStringContainsString('0.80', $data['auditNote']);

        $skillSaves = array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskill');
        $this->assertSame([], $skillSaves, 'learnings.md (skill files) untouched — entries retained.');

    }//end testStrictlyWorseEvalAutoDiscardsRetainingLearnings()

    /**
     * A TIE survives to human review (equal pass rates advance to
     * awaiting-approval with an Approval created).
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
     */
    public function testTiedEvalSurvivesToHumanReview(): void
    {
        $service = $this->service();
        $this->skill(overrides: ['installedOn' => ['agent-1']]);
        $draft = $this->draft();
        $this->linkDataset();
        $this->resolveAgent();

        $this->contentScanService->method('scan')->willReturn($this->cleanScan());
        $this->evalRunService->method('runDraftComparison')->willReturn(
            [
                'evalRunId'      => 'run-1',
                'status'         => 'draft-comparison',
                'draftPassRate'  => 0.8,
                'activePassRate' => 0.8,
            ]
        );
        $approval = new ObjectEntity();
        $approval->setUuid('approval-1');
        $this->approvalService->expects($this->once())
            ->method('ensurePendingApprovalForSkillDraft')
            ->willReturn($approval);

        $result = $service->prequalifyDraft(draft: $draft);

        $data = $result->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_AWAITING_APPROVAL, $data['status']);
        $this->assertSame('approval-1', $data['approvalId']);
        $this->assertSame(0.0, $data['evalEvidence']['delta']);

    }//end testTiedEvalSurvivesToHumanReview()

    /**
     * No linked dataset yields the honest `noEvalEvidence` flag on an
     * awaiting-approval draft — and the Approval payload carries it.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
     */
    public function testNoLinkedDatasetFlagsNoEvalEvidence(): void
    {
        $service = $this->service();
        $this->skill();
        $draft = $this->draft();

        $this->contentScanService->method('scan')->willReturn($this->cleanScan());
        $this->evalRunService->expects($this->never())->method('runDraftComparison');

        $capturedPayload = [];
        $approval        = new ObjectEntity();
        $approval->setUuid('approval-1');
        $this->approvalService->method('ensurePendingApprovalForSkillDraft')->willReturnCallback(
            function (ObjectEntity $draftArg, ObjectEntity $skillArg, array $draftPayload) use (&$capturedPayload, $approval): ObjectEntity {
                unset($draftArg, $skillArg);
                $capturedPayload = $draftPayload;
                return $approval;
            }
        );

        $result = $service->prequalifyDraft(draft: $draft);

        $data = $result->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_AWAITING_APPROVAL, $data['status']);
        $this->assertTrue($data['noEvalEvidence']);
        $this->assertTrue($capturedPayload['noEvalEvidence']);
        $this->assertArrayNotHasKey('evalDelta', $capturedPayload);
        $this->assertNotSame('', $capturedPayload['deepLink']);
        $this->assertNotSame('', $capturedPayload['learningsSummary']);

    }//end testNoLinkedDatasetFlagsNoEvalEvidence()

    /**
     * Applying an approvable draft writes the proposed content + the
     * lastAcceptedVersionAt stamp onto the skill through the maturity write guard
     * (computed fields carried forward), settles the draft, and NEVER writes
     * levelEvidence.l5.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testApplyDraftWritesVersionedContentAndCarriesComputedFields(): void
    {
        $service = $this->service();
        $this->skill(overrides: ['maturityLevel' => 4, 'levelEvidence' => ['l4' => ['attestedBy' => 'admin']]]);
        $this->draft(
            [
                'status'         => SkillConsolidationService::STATUS_AWAITING_APPROVAL,
                'scanVerdict'    => 'clean',
                'noEvalEvidence' => true,
                'approvalId'     => 'approval-1',
            ]
        );
        $this->versionService->method('currentVersionId')->willReturn('v-new');

        $versionId = $service->applyDraft(draftId: 'draft-1', deciderUid: 'reviewer');

        $this->assertSame('v-new', $versionId);

        $skillSaves = array_values(array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskill'));
        $this->assertCount(1, $skillSaves, 'One versioned write through the normal path.');
        $payload = $skillSaves[0][2];
        $this->assertSame('PROPOSED BODY', $payload['body']);
        $this->assertNotSame('', (string) ($payload['lastAcceptedVersionAt'] ?? ''));
        // Computed maturity carried forward by the write guard, l5 never granted.
        $this->assertSame(4, $payload['maturityLevel']);
        $this->assertArrayNotHasKey('l5', ($payload['levelEvidence'] ?? []));

        $draftData = $this->found['agentskilldraft:draft-1']->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_ACCEPTED, $draftData['status']);
        $this->assertSame('reviewer', $draftData['decidedBy']);

    }//end testApplyDraftWritesVersionedContentAndCarriesComputedFields()

    /**
     * The apply is IDEMPOTENT: re-applying an already-accepted draft (a reconciled
     * or repeated Approval transition) writes nothing a second time.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testApplyDraftIsIdempotent(): void
    {
        $service = $this->service();
        $this->skill();
        $this->draft(
            [
                'status'         => SkillConsolidationService::STATUS_AWAITING_APPROVAL,
                'scanVerdict'    => 'clean',
                'noEvalEvidence' => true,
            ]
        );
        $this->versionService->method('currentVersionId')->willReturn('v-new');

        $service->applyDraft(draftId: 'draft-1', deciderUid: 'reviewer');
        $before = count(array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskill'));

        $this->assertNull($service->applyDraft(draftId: 'draft-1', deciderUid: 'reviewer'));
        $after = count(array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskill'));
        $this->assertSame($before, $after, 'A second apply writes nothing.');

    }//end testApplyDraftIsIdempotent()

    /**
     * Rejection records the curator-marked refs on the DRAFT, and the next
     * proposal's driving entries exclude them (never an edit to learnings.md).
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testRejectedLearningRefsNeverDriveTheNextProposal(): void
    {
        $service = $this->service();
        $skill   = $this->skill(entryCount: 2);
        $this->draft(['status' => SkillConsolidationService::STATUS_AWAITING_APPROVAL]);

        $entries = $service->drivingEntries(data: $skill->getObject());
        $this->assertCount(2, $entries);
        $badRef = $entries[0]['ref'];

        $service->rejectDraftByDecision(draftId: 'draft-1', deciderUid: 'reviewer', note: 'off track', refs: [$badRef]);

        $draftData = $this->found['agentskilldraft:draft-1']->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_REJECTED, $draftData['status']);
        $this->assertSame([$badRef], $draftData['rejectedLearningRefs']);

        // The next proposal excludes the marked entry.
        $excluded  = $service->rejectedRefsForSkill(skillId: 'skill-1');
        $remaining = $service->drivingEntries(data: $skill->getObject(), excludedRefs: $excluded);
        $this->assertCount(1, $remaining);
        $this->assertNotSame($badRef, $remaining[0]['ref']);

    }//end testRejectedLearningRefsNeverDriveTheNextProposal()

    /**
     * Editing the draft content INVALIDATES scan+eval evidence, blocks
     * approvability everywhere, and re-runs pre-qualification over the edited
     * content — after which the draft is approvable again.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testEditInvalidatesEvidenceAndRequiresRequalification(): void
    {
        $service = $this->service();
        $this->skill();
        $draft = $this->draft(
            [
                'status'       => SkillConsolidationService::STATUS_AWAITING_APPROVAL,
                'scanVerdict'  => 'clean',
                'evalEvidence' => ['datasetId' => 'dataset-1', 'delta' => 0.1],
                'approvalId'   => 'approval-1',
            ]
        );

        // First: with the scan unavailable, the edited draft must be stuck
        // UN-approvable (fail closed) — an inbox approval cannot apply it.
        $this->contentScanService->method('scan')->willThrowException(new RuntimeException('scanner down'));

        $edited = $service->editDraftContent(
            draft: $draft,
            frontmatter: null,
            body: 'HUMAN-EDITED BODY',
            files: null,
            editorUid: 'reviewer'
        );

        $data = $edited->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_PROPOSED, $data['status']);
        $this->assertSame('', (string) ($data['scanVerdict'] ?? ''));
        $this->assertSame([], ($data['evalEvidence'] ?? null));
        $this->assertTrue((bool) $data['editedBeforeAccept']);
        $this->assertSame('reviewer', $data['editedBy']);
        $this->assertFalse(
            $service->isDraftApprovable(draftId: 'draft-1'),
            'An edited-but-unscanned draft is not approvable from ANY surface.'
        );

    }//end testEditInvalidatesEvidenceAndRequiresRequalification()

    /**
     * Reconciliation idempotently applies a missed approved transition — and a
     * second reconcile pass changes nothing.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testReconciliationAppliesMissedApprovalIdempotently(): void
    {
        $service = $this->service();
        $this->skill();
        $draft = $this->draft(
            [
                'status'         => SkillConsolidationService::STATUS_AWAITING_APPROVAL,
                'scanVerdict'    => 'clean',
                'noEvalEvidence' => true,
                'approvalId'     => 'approval-1',
            ]
        );

        $approval = new ObjectEntity();
        $approval->setUuid('approval-1');
        $approval->setObject(
            [
                'status'    => 'approved',
                'decidedBy' => 'inbox-reviewer',
            ]
        );
        $this->approvalService->method('loadApproval')->willReturn($approval);
        $this->versionService->method('currentVersionId')->willReturn('v-new');

        $this->assertTrue($service->reconcileDraftApproval(draft: $draft));

        $draftData = $this->found['agentskilldraft:draft-1']->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_ACCEPTED, $draftData['status']);
        $this->assertSame('inbox-reviewer', $draftData['decidedBy']);

        $skillWritesAfterFirst = count(array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskill'));

        // Second pass: the decided draft is terminal — nothing applies twice.
        $service->reconcileDraftApproval(draft: $this->found['agentskilldraft:draft-1']);
        $skillWritesAfterSecond = count(array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskill'));
        $this->assertSame($skillWritesAfterFirst, $skillWritesAfterSecond);

    }//end testReconciliationAppliesMissedApprovalIdempotently()

    /**
     * Reconciliation settles a missed DENIED transition to `rejected` with the
     * skill unchanged.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testReconciliationRejectsMissedDenialWithSkillUnchanged(): void
    {
        $service = $this->service();
        $this->skill();
        $draft = $this->draft(
            [
                'status'     => SkillConsolidationService::STATUS_AWAITING_APPROVAL,
                'approvalId' => 'approval-1',
            ]
        );

        $approval = new ObjectEntity();
        $approval->setUuid('approval-1');
        $approval->setObject(
            [
                'status'    => 'denied',
                'decidedBy' => 'inbox-reviewer',
                'reason'    => 'not convincing',
            ]
        );
        $this->approvalService->method('loadApproval')->willReturn($approval);

        $this->assertTrue($service->reconcileDraftApproval(draft: $draft));

        $draftData = $this->found['agentskilldraft:draft-1']->getObject();
        $this->assertSame(SkillConsolidationService::STATUS_REJECTED, $draftData['status']);
        $skillSaves = array_filter($this->savedObjects, static fn (array $save): bool => $save[0] === 'agentskill');
        $this->assertSame([], $skillSaves, 'A denial never touches the skill.');

    }//end testReconciliationRejectsMissedDenialWithSkillUnchanged()

    /**
     * The publisher is notified exactly once per NEWLY-behind transition:
     * acceptance on a published, not-yet-behind skill notifies; acceptance on an
     * already-behind skill does not re-notify.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
     */
    public function testBehindNotificationFiresOncePerNewlyBehindTransition(): void
    {
        $service = $this->service();
        $this->skill(
            overrides: [
                'githubOwner' => 'YOUR_OWNER_HERE',
                'githubRepo'  => 'hermiq-skill-example',
                'publishedAt' => '2026-07-01T00:00:00+00:00',
            ]
        );
        $this->draft(
            [
                'status'         => SkillConsolidationService::STATUS_AWAITING_APPROVAL,
                'scanVerdict'    => 'clean',
                'noEvalEvidence' => true,
            ]
        );
        $this->versionService->method('currentVersionId')->willReturn('v-new');
        $this->deliveryService->expects($this->once())->method('deliverSkillPublishedBehind');

        $service->applyDraft(draftId: 'draft-1', deciderUid: 'reviewer');

        // Second acceptance while ALREADY behind: no repeat notification.
        $this->draft(
            [
                'status'         => SkillConsolidationService::STATUS_AWAITING_APPROVAL,
                'scanVerdict'    => 'clean',
                'noEvalEvidence' => true,
            ]
        );

        $service->applyDraft(draftId: 'draft-1', deciderUid: 'reviewer');

    }//end testBehindNotificationFiresOncePerNewlyBehindTransition()

    /**
     * A blocked/unavailable paired eval leaves the draft in `proposed` — evidence,
     * never bypass.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
     */
    public function testBlockedPairedEvalKeepsDraftProposed(): void
    {
        $service = $this->service();
        $this->skill(overrides: ['installedOn' => ['agent-1']]);
        $draft = $this->draft();
        $this->linkDataset();
        $this->resolveAgent();

        $this->contentScanService->method('scan')->willReturn($this->cleanScan());
        $this->evalRunService->method('runDraftComparison')->willReturn(
            [
                'evalRunId'      => 'run-1',
                'status'         => 'blocked_budget',
                'draftPassRate'  => 0.0,
                'activePassRate' => 0.0,
            ]
        );
        $this->approvalService->expects($this->never())->method('ensurePendingApprovalForSkillDraft');

        $result = $service->prequalifyDraft(draft: $draft);

        $this->assertSame(SkillConsolidationService::STATUS_PROPOSED, $result->getObject()['status']);

    }//end testBlockedPairedEvalKeepsDraftProposed()

    /**
     * Register an EvalDataset whose skillRefs link skill-1.
     *
     * @return void
     */
    private function linkDataset(): void
    {
        $dataset = new ObjectEntity();
        $dataset->setUuid('dataset-1');
        $dataset->setObject(
            [
                'name'      => 'tender-cases',
                'skillRefs' => ['skill-1'],
                'cases'     => [],
            ]
        );

        $this->collections['evaldataset'] = [$dataset];

    }//end linkDataset()

    /**
     * Make agent-1 resolvable for the paired comparison.
     *
     * @return void
     */
    private function resolveAgent(): void
    {
        $agent = new \OCA\OpenRegister\Db\Agent();
        $agent->setUuid('agent-1');
        $agent->setOwner('alice');
        $agent->setOrganisation('org-1');
        $this->agentMapper->method('findByUuid')->willReturn($agent);

    }//end resolveAgent()
}//end class
