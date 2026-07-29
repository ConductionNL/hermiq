<?php

/**
 * Unit tests for SkillLearningsCaptureService (skill-learnings).
 *
 * Covers the per-run-ID idempotency (byte-identical file, zero LLM calls), the budget
 * gate skip, redaction inheritance (a credential is masked before persist;
 * redaction-empty writes nothing at all), confirmation semantics (the run-id list is
 * extended, never duplicated), the pinned candidate-line grammar, per-skill failure
 * isolation, the `body`/`frontmatter`/other-files byte-unchanged guarantee, the l6
 * activity stamp, and the capture-usage accounting entry (`action='run'`,
 * `runType: 'skill-capture'`).
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
 * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * SkillLearningsCaptureService behaviour tests (skill-learnings).
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)  One focused test per spec scenario.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service's own collaborator set.
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
 */
class SkillLearningsCaptureServiceTest extends TestCase
{

    /**
     * The fixed "today" every test-built service stamps (grammar pinning).
     *
     * @var string
     */
    private const TODAY = '2026-07-27';

    /**
     * The run id under capture.
     *
     * @var string
     */
    private const RUN_ID = '11111111-1111-1111-1111-111111111111';

    /**
     * The schedule uuid the run belongs to.
     *
     * @var string
     */
    private const SCHEDULE_UUID = '22222222-2222-2222-2222-222222222222';

    /**
     * The prepared ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * The prepared AuditTrailMapper mock.
     *
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper&MockObject $auditTrailMapper;

    /**
     * The prepared ProviderFactory mock.
     *
     * @var ProviderFactory&MockObject
     */
    private ProviderFactory&MockObject $providerFactory;

    /**
     * The prepared BudgetService mock.
     *
     * @var BudgetService&MockObject
     */
    private BudgetService&MockObject $budgetService;

    /**
     * Every saveObject() call captured as [schema, uuid, object payload].
     *
     * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Build the service over fresh mocks with a REAL RedactionService (frozen-on)
     * and a fixed clock.
     *
     * @param RedactionService|null $redaction Optional redaction override.
     *
     * @return SkillLearningsCaptureService
     */
    private function service(?RedactionService $redaction=null): SkillLearningsCaptureService
    {
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->providerFactory  = $this->createMock(ProviderFactory::class);
        $this->budgetService    = $this->createMock(BudgetService::class);
        $this->savedObjects     = [];

        if ($redaction === null) {
            $config = $this->createMock(IConfig::class);
            $config->method('getAppValue')->willReturn('yes');
            $redaction = new RedactionService($config);
        }

        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null): ObjectEntity {
                unset($extend, $register);
                $this->savedObjects[] = [
                    (string) $schema,
                    (string) $uuid,
                    $object,
                ];

                $entity = new ObjectEntity();
                $entity->setUuid($uuid);
                $entity->setObject($object);
                return $entity;
            }
        );

        return new class (
            $this->objectService,
            $this->auditTrailMapper,
            $this->providerFactory,
            $this->budgetService,
            $redaction,
            new NullLogger()
        ) extends SkillLearningsCaptureService {

            /**
             * Fixed capture date for deterministic grammar assertions.
             *
             * @return string
             */
            protected function today(): string
            {
                return '2026-07-27';
            }//end today()

            /**
             * Fixed capture timestamp for deterministic l6 assertions.
             *
             * @return string
             */
            protected function nowIso(): string
            {
                return '2026-07-27T12:00:00+00:00';
            }//end nowIso()
        };

    }//end service()

    /**
     * A stored Skill entity with the given files.
     *
     * @param string                   $uuid  The skill uuid.
     * @param array<int, array<string, string>> $files The files entries.
     * @param array<string, mixed>     $extra Extra payload fields.
     *
     * @return ObjectEntity
     */
    private function skillEntity(string $uuid, array $files=[], array $extra=[]): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject(
            array_merge(
                [
                    'name'        => 'tender-summary',
                    'description' => 'Summarise a tender publication.',
                    'frontmatter' => "name: tender-summary\ndescription: Summarise a tender publication.",
                    'body'        => "# Tender Summary\n\nSteps.",
                    'state'       => 'active',
                    'files'       => $files,
                ],
                $extra
            )
        );

        return $entity;

    }//end skillEntity()

    /**
     * Wire find() to return the given skills by uuid (plus the run's schedule) and
     * the audit read to return the run's trace entry.
     *
     * @param array<string, ObjectEntity> $skills Skills keyed by uuid.
     *
     * @return void
     */
    private function wireLookups(array $skills): void
    {
        $schedule = new ObjectEntity();
        $schedule->setUuid(self::SCHEDULE_UUID);
        $schedule->setObject(['agentId' => 'agent-1']);

        $this->objectService->method('find')->willReturnCallback(
            static function (string $id) use ($skills, $schedule): ?ObjectEntity {
                if ($id === self::SCHEDULE_UUID) {
                    return $schedule;
                }

                return ($skills[$id] ?? null);
            }
        );

        $trace = new AuditTrail();
        $trace->setUuid('33333333-3333-3333-3333-333333333333');
        $trace->setAction('run');
        $trace->setChanged(
            [
                'runId'   => self::RUN_ID,
                'status'  => 'ok',
                'summary' => 'Summarised the notice.',
                'steps'   => [],
            ]
        );

        $this->auditTrailMapper->method('findAll')->willReturn([$trace]);

    }//end wireLookups()

    /**
     * The default capture job payload for one skill.
     *
     * @param array<string, mixed> $overrides Payload overrides.
     *
     * @return array<string, mixed>
     */
    private function args(array $overrides=[]): array
    {
        return array_merge(
            [
                'runId'        => self::RUN_ID,
                'scheduleUuid' => self::SCHEDULE_UUID,
                'agentId'      => 'agent-1',
                'skillIds'     => ['skill-1'],
                'organisation' => 'org-1',
            ],
            $overrides
        );

    }//end args()

    /**
     * A provider JSON response with the given observations/confirmations.
     *
     * @param array<int, array<string, mixed>> $observations  The observations.
     * @param array<int, array<string, mixed>> $confirmations The confirmations.
     *
     * @return string
     */
    private function llmResponse(array $observations=[], array $confirmations=[]): string
    {
        return (string) json_encode(
            [
                'observations'  => $observations,
                'confirmations' => $confirmations,
            ]
        );

    }//end llmResponse()

    /**
     * Re-processing a run id already present in the candidates file is a no-op:
     * zero LLM calls, zero writes (byte-identical file by construction).
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-idempotent-per-run-id
     */
    public function testReprocessingSameRunIdMakesNoLlmCallAndNoWrite(): void
    {
        $service    = $this->service();
        $candidates = '- [2026-07-01] {domain} TED deadlines are CET. <!-- runs: '.self::RUN_ID." -->\n";

        $this->wireLookups(
            skills: [
                'skill-1' => $this->skillEntity(
                    uuid: 'skill-1',
                    files: [
                        [
                            'name'    => 'learning-candidates.md',
                            'content' => $candidates,
                        ],
                    ]
                ),
            ]
        );

        $this->providerFactory->expects($this->never())->method('generateText');
        $this->budgetService->expects($this->never())->method('isBlocked');

        $service->captureForRun(args: $this->args());

        $this->assertSame([], $this->savedObjects, 'An idempotent skip must write nothing.');

    }//end testReprocessingSameRunIdMakesNoLlmCallAndNoWrite()

    /**
     * A budget-blocked scope gets no capture pass: no LLM call, no write.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-budget-gated-and-budget-counted
     */
    public function testBudgetBlockedScopeSkipsCaptureEntirely(): void
    {
        $service = $this->service();
        $this->wireLookups(skills: ['skill-1' => $this->skillEntity(uuid: 'skill-1')]);

        $this->budgetService->method('isBlocked')->willReturn(true);
        $this->providerFactory->expects($this->never())->method('generateText');

        $service->captureForRun(args: $this->args());

        $this->assertSame([], $this->savedObjects, 'A budget-blocked scope must write nothing.');

    }//end testBudgetBlockedScopeSkipsCaptureEntirely()

    /**
     * A recognised credential in an extracted observation is masked by the REAL
     * RedactionService before persist — the raw credential never reaches the file.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-learnings-writes-inherit-the-agent-memory-redaction-path-and-tool-governance
     */
    public function testCredentialInObservationIsMaskedBeforePersist(): void
    {
        $service = $this->service();
        $this->wireLookups(skills: ['skill-1' => $this->skillEntity(uuid: 'skill-1')]);
        $this->budgetService->method('isBlocked')->willReturn(false);

        $secret = 'sk-ABCDEFGHIJKLMNOPQRSTUVWX';
        $this->providerFactory->method('generateText')->willReturn(
            $this->llmResponse(
                observations: [
                    [
                        'section' => 'domain',
                        'text'    => 'The API key '.$secret.' authenticates the portal.',
                    ],
                ]
            )
        );

        $service->captureForRun(args: $this->args());

        $this->assertNotSame([], $this->savedObjects);
        $content = $this->candidatesContentOf(saved: $this->savedObjects[0][2]);
        $this->assertStringNotContainsString($secret, $content, 'The raw credential must never persist.');
        $this->assertStringContainsString('sk-ABC', $content, 'The masked form persists instead.');

    }//end testCredentialInObservationIsMaskedBeforePersist()

    /**
     * When EVERY observation redacts to empty, nothing is written at all: no file
     * change, no l6 stamp — despite the LLM call having happened.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-learnings-writes-inherit-the-agent-memory-redaction-path-and-tool-governance
     */
    public function testRedactionEmptyPassWritesNothing(): void
    {
        $redaction = $this->createMock(RedactionService::class);
        $redaction->method('redact')->willReturn('');

        $service = $this->service(redaction: $redaction);
        $this->wireLookups(skills: ['skill-1' => $this->skillEntity(uuid: 'skill-1')]);
        $this->budgetService->method('isBlocked')->willReturn(false);

        $this->providerFactory->expects($this->once())->method('generateText')->willReturn(
            $this->llmResponse(
                observations: [
                    [
                        'section' => 'domain',
                        'text'    => 'contains only redactable content',
                    ],
                ]
            )
        );

        $service->captureForRun(args: $this->args());

        $skillWrites = array_filter(
            $this->savedObjects,
            static fn (array $write): bool => $write[0] === 'agentskill'
        );
        $this->assertSame([], $skillWrites, 'Redaction-empty means no skill write at all (no empty lines, no l6 stamp).');

    }//end testRedactionEmptyPassWritesNothing()

    /**
     * A confirmation extends the existing candidate's run-id list and refreshes its
     * date — never a duplicate line.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    public function testConfirmationExtendsRunIdListWithoutDuplicating(): void
    {
        $service  = $this->service();
        $original = '- [2026-07-01] {domain} TED deadlines are CET. '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000 -->'."\n";

        $this->wireLookups(
            skills: [
                'skill-1' => $this->skillEntity(
                    uuid: 'skill-1',
                    files: [
                        [
                            'name'    => 'learning-candidates.md',
                            'content' => $original,
                        ],
                    ]
                ),
            ]
        );
        $this->budgetService->method('isBlocked')->willReturn(false);

        $this->providerFactory->method('generateText')->willReturn(
            $this->llmResponse(confirmations: [['candidateIndex' => 0]])
        );

        $service->captureForRun(args: $this->args());

        $content = $this->candidatesContentOf(saved: $this->savedObjects[0][2]);
        $expected = '- ['.self::TODAY.'] {domain} TED deadlines are CET. '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000,'.self::RUN_ID." -->\n";

        $this->assertSame($expected, $content, 'One line: run id appended, date refreshed, no duplicate.');

    }//end testConfirmationExtendsRunIdListWithoutDuplicating()

    /**
     * The serialized grammar is pinned byte-for-byte, including the optional
     * eval-fail marker (design.md's normative grammar).
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    public function testGrammarSerializationIsPinned(): void
    {
        $service = $this->service();
        $this->wireLookups(skills: ['skill-1' => $this->skillEntity(uuid: 'skill-1')]);
        $this->budgetService->method('isBlocked')->willReturn(false);

        $this->providerFactory->method('generateText')->willReturn(
            $this->llmResponse(
                observations: [
                    [
                        'section' => 'mistakes',
                        'text'    => 'Do not summarise lots separately when the notice has one CPV.',
                    ],
                ]
            )
        );

        $evalRef = '44444444-4444-4444-4444-444444444444#case-3';
        $service->captureForRun(args: $this->args(['evalFail' => $evalRef]));

        $content  = $this->candidatesContentOf(saved: $this->savedObjects[0][2]);
        $expected = '- ['.self::TODAY.'] {mistakes} Do not summarise lots separately when the notice has one CPV. '
            .'<!-- runs: '.self::RUN_ID.' | eval-fail: '.$evalRef." -->\n";

        $this->assertSame($expected, $content);
        $this->assertNotNull(
            SkillLearningsCaptureService::parseCandidateLine(line: rtrim($expected)),
            'The pinned serialization round-trips through the parser.'
        );

    }//end testGrammarSerializationIsPinned()

    /**
     * One skill's provider failure never prevents capture for the run's other
     * exercised skills, and captureForRun itself never throws.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
     */
    public function testOneSkillFailureNeverBlocksTheNext(): void
    {
        $service = $this->service();
        $this->wireLookups(
            skills: [
                'skill-1' => $this->skillEntity(uuid: 'skill-1'),
                'skill-2' => $this->skillEntity(uuid: 'skill-2'),
            ]
        );
        $this->budgetService->method('isBlocked')->willReturn(false);

        $calls = 0;
        $this->providerFactory->method('generateText')->willReturnCallback(
            function () use (&$calls): string {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('provider outage');
                }

                return $this->llmResponse(
                    observations: [
                        [
                            'section' => 'patterns',
                            'text'    => 'Leading with the deadline speeds the go/no-go call.',
                        ],
                    ]
                );
            }
        );

        $service->captureForRun(args: $this->args(['skillIds' => ['skill-1', 'skill-2']]));

        $this->assertSame(2, $calls, 'The second skill is still attempted after the first fails.');
        $skillWrites = array_values(
            array_filter($this->savedObjects, static fn (array $write): bool => $write[0] === 'agentskill')
        );
        $this->assertCount(1, $skillWrites);
        $this->assertSame('skill-2', $skillWrites[0][1]);

    }//end testOneSkillFailureNeverBlocksTheNext()

    /**
     * A capture write leaves `body`, `frontmatter`, and every OTHER files entry
     * byte-unchanged — only the candidates entry is touched.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    public function testBodyFrontmatterAndOtherFilesStayByteUnchanged(): void
    {
        $service = $this->service();
        $skill   = $this->skillEntity(
            uuid: 'skill-1',
            files: [
                [
                    'name'    => 'references/exemption-grounds.md',
                    'content' => "# Grounds\n",
                ],
            ]
        );

        $this->wireLookups(skills: ['skill-1' => $skill]);
        $this->budgetService->method('isBlocked')->willReturn(false);
        $this->providerFactory->method('generateText')->willReturn(
            $this->llmResponse(
                observations: [
                    [
                        'section' => 'domain',
                        'text'    => 'TED deadlines are CET.',
                    ],
                ]
            )
        );

        $service->captureForRun(args: $this->args());

        $stored = $skill->getObject();
        $saved  = $this->savedObjects[0][2];

        $this->assertSame($stored['body'], $saved['body']);
        $this->assertSame($stored['frontmatter'], $saved['frontmatter']);
        $this->assertSame(
            [
                'name'    => 'references/exemption-grounds.md',
                'content' => "# Grounds\n",
            ],
            $saved['files'][0],
            'The pre-existing files entry is byte-unchanged.'
        );

    }//end testBodyFrontmatterAndOtherFilesStayByteUnchanged()

    /**
     * Capture stamps `levelEvidence.l6.candidateCount` (from the parsed file) +
     * `lastCaptureAt`, carrying every other l6 key (and l1–l7 entry) forward.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-levelevidencel6-activity-is-written-by-the-learnings-subsystem-only
     */
    public function testCaptureStampsL6ActivityAndPreservesOtherEvidence(): void
    {
        $service = $this->service();
        $skill   = $this->skillEntity(
            uuid: 'skill-1',
            files: [
                [
                    'name'    => 'learning-candidates.md',
                    'content' => '- [2026-07-01] {domain} Existing candidate. <!-- runs: 00000000-0000-0000-0000-000000000000 -->'."\n",
                ],
            ],
            extra: [
                'levelEvidence' => [
                    'l4' => ['attestedBy' => 'admin'],
                    'l6' => ['learningsCount' => 3],
                ],
            ]
        );

        $this->wireLookups(skills: ['skill-1' => $skill]);
        $this->budgetService->method('isBlocked')->willReturn(false);
        $this->providerFactory->method('generateText')->willReturn(
            $this->llmResponse(
                observations: [
                    [
                        'section' => 'questions',
                        'text'    => 'Should call-off notices be summarised at all?',
                    ],
                ]
            )
        );

        $service->captureForRun(args: $this->args());

        $saved = $this->savedObjects[0][2];
        $l6    = $saved['levelEvidence']['l6'];

        $this->assertSame(2, $l6['candidateCount'], 'candidateCount equals the parsed candidate count after the write.');
        $this->assertSame('2026-07-27T12:00:00+00:00', $l6['lastCaptureAt']);
        $this->assertSame(3, $l6['learningsCount'], 'Other l6 keys are carried forward untouched.');
        $this->assertArrayNotHasKey('lastConsolidatedAt', $l6, 'Capture never writes lastConsolidatedAt.');
        $this->assertSame(['attestedBy' => 'admin'], $saved['levelEvidence']['l4'], 'Other level entries survive.');

    }//end testCaptureStampsL6ActivityAndPreservesOtherEvidence()

    /**
     * The pass's token usage is recorded as an `action='run'` entry on the run's
     * Schedule, tagged `runType: 'skill-capture'` with the originating runId — the
     * exact channel `BudgetService::currentUsageTokens()` aggregates.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-budget-gated-and-budget-counted
     */
    public function testCaptureUsageIsRecordedOnTheRunAuditChannel(): void
    {
        $service = $this->service();
        $this->wireLookups(skills: ['skill-1' => $this->skillEntity(uuid: 'skill-1')]);
        $this->budgetService->method('isBlocked')->willReturn(false);
        $this->providerFactory->method('generateText')->willReturn(
            $this->llmResponse(
                observations: [
                    [
                        'section' => 'domain',
                        'text'    => 'TED deadlines are CET.',
                    ],
                ]
            )
        );

        $recorded = null;
        $this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
            static function (ObjectEntity $object, string $action, array $context=[]) use (&$recorded): AuditTrail {
                $recorded = [
                    'objectUuid' => (string) $object->getUuid(),
                    'action'     => $action,
                    'context'    => $context,
                ];

                $entry = new AuditTrail();
                $entry->setAction($action);
                $entry->setChanged($context);
                return $entry;
            }
        );

        $service->captureForRun(args: $this->args());

        $this->assertNotNull($recorded, 'A capture that consumed tokens records a usage entry.');
        $this->assertSame(self::SCHEDULE_UUID, $recorded['objectUuid']);
        $this->assertSame('run', $recorded['action']);
        $this->assertSame('skill-capture', $recorded['context']['runType']);
        $this->assertSame(self::RUN_ID, $recorded['context']['runId']);
        $this->assertGreaterThan(0, $recorded['context']['usage']['promptTokens']);

    }//end testCaptureUsageIsRecordedOnTheRunAuditChannel()

    /**
     * Read the candidates-file content out of a saved skill payload.
     *
     * @param array<string, mixed> $saved The saved payload.
     *
     * @return string The `learning-candidates.md` content.
     */
    private function candidatesContentOf(array $saved): string
    {
        foreach (($saved['files'] ?? []) as $file) {
            if (($file['name'] ?? '') === 'learning-candidates.md') {
                return (string) $file['content'];
            }
        }

        return '';

    }//end candidatesContentOf()
}//end class
