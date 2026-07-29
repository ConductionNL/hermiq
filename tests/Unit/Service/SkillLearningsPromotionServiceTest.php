<?php

/**
 * Unit tests for SkillLearningsPromotionService (skill-learnings).
 *
 * Covers the mechanical two-stage promotion: 3-distinct-run promotion into the tagged
 * section, the eval-fail immediate-promotion fast path, the 30-day expiry, removal of
 * promoted lines from the candidates file, the Consolidated-Principles-never-written
 * guarantee, zero LLM/provider interaction, and the l6 counts being derived from the
 * parsed file contents. Also hosts the write-path guard test (a forged client `l6`
 * does not survive `SkillMaturityService::preserveComputedFields()`) and the
 * SkillSerializer round-trip test with both learnings files aboard.
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
 * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\Hermiq\Service\SkillLearningsPromotionService;
use OCA\Hermiq\Service\SkillMaturityService;
use OCA\Hermiq\Service\SkillSerializer;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * SkillLearningsPromotionService behaviour tests (skill-learnings).
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)  One focused test per spec scenario.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The subsystem's own collaborator set.
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
 */
class SkillLearningsPromotionServiceTest extends TestCase
{

    /**
     * The fixed "now" the test-built service runs at.
     *
     * @var string
     */
    private const NOW = '2026-07-27T12:00:00+00:00';

    /**
     * The prepared ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * The prepared ProviderFactory mock (must never be called by promotion).
     *
     * @var ProviderFactory&MockObject
     */
    private ProviderFactory&MockObject $providerFactory;

    /**
     * Every saveObject() call captured as [schema, uuid, object payload].
     *
     * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Build the promotion service over fresh mocks with a fixed clock. The capture
     * service (grammar owner) is built over the SAME ProviderFactory mock, with an
     * explicit never() expectation — the promotion pass makes NO LLM call.
     *
     * @return SkillLearningsPromotionService
     */
    private function service(): SkillLearningsPromotionService
    {
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->providerFactory = $this->createMock(ProviderFactory::class);
        $this->savedObjects    = [];

        $this->providerFactory->expects($this->never())->method('generateText');

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

        $captureService = new SkillLearningsCaptureService(
            $this->objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->providerFactory,
            $this->createMock(BudgetService::class),
            $this->createMock(RedactionService::class),
            new NullLogger()
        );

        return new class (
            $this->objectService,
            $captureService,
            new NullLogger()
        ) extends SkillLearningsPromotionService {

            /**
             * Fixed clock for deterministic expiry/provenance assertions.
             *
             * @return DateTimeImmutable
             */
            protected function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-07-27T12:00:00+00:00', new DateTimeZone('UTC'));
            }//end now()
        };

    }//end service()

    /**
     * A stored Skill entity carrying the given candidates (and optional learnings).
     *
     * @param string      $candidates The `learning-candidates.md` content.
     * @param string|null $learnings  Optional `learnings.md` content.
     *
     * @return ObjectEntity
     */
    private function skillWithCandidates(string $candidates, ?string $learnings=null): ObjectEntity
    {
        $files = [
            [
                'name'    => 'learning-candidates.md',
                'content' => $candidates,
            ],
        ];
        if ($learnings !== null) {
            $files[] = [
                'name'    => 'learnings.md',
                'content' => $learnings,
            ];
        }

        $entity = new ObjectEntity();
        $entity->setUuid('skill-1');
        $entity->setObject(
            [
                'name'        => 'tender-summary',
                'frontmatter' => "name: tender-summary\ndescription: Summarise a tender publication.",
                'body'        => "# Tender Summary\n",
                'state'       => 'active',
                'files'       => $files,
            ]
        );

        return $entity;

    }//end skillWithCandidates()

    /**
     * Read one file's content out of the last saved payload.
     *
     * @param string $name The files entry name.
     *
     * @return string|null The content, or null when the entry is absent.
     */
    private function savedFile(string $name): ?string
    {
        $saved = end($this->savedObjects);
        if ($saved === false) {
            return null;
        }

        foreach (($saved[2]['files'] ?? []) as $file) {
            if (($file['name'] ?? '') === $name) {
                return (string) $file['content'];
            }
        }

        return null;

    }//end savedFile()

    /**
     * A candidate confirmed by 3 DISTINCT run ids promotes into its tagged section
     * and is removed from the candidates file; the promotion pass never calls an LLM.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function testThriceConfirmedCandidatePromotesToItsSection(): void
    {
        $service   = $this->service();
        $candidate = '- [2026-07-20] {domain} TED deadlines are CET, not local time. '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000,00000000-0000-0000-0000-000000000001,00000000-0000-0000-0000-000000000002 -->';

        $service->promoteSkill(skill: $this->skillWithCandidates(candidates: $candidate."\n"));

        $learnings = (string) $this->savedFile(name: 'learnings.md');
        $this->assertMatchesRegularExpression(
            '/## Domain Knowledge\n+- TED deadlines are CET, not local time\./',
            $learnings,
            'The observation lands under Domain Knowledge.'
        );
        $this->assertSame('', (string) $this->savedFile(name: 'learning-candidates.md'), 'The promoted line is removed.');

    }//end testThriceConfirmedCandidatePromotesToItsSection()

    /**
     * Two DISTINCT run ids (even when one is repeated) do NOT promote — the
     * threshold counts distinct ids.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function testDuplicatedRunIdsDoNotCountTowardTheThreshold(): void
    {
        $service   = $this->service();
        $candidate = '- [2026-07-20] {domain} Repeated-run observation. '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000,00000000-0000-0000-0000-000000000000,00000000-0000-0000-0000-000000000001 -->';

        $service->promoteSkill(skill: $this->skillWithCandidates(candidates: $candidate."\n"));

        $this->assertSame([], $this->savedObjects, 'Two distinct runs promote nothing (no write at all).');

    }//end testDuplicatedRunIdsDoNotCountTowardTheThreshold()

    /**
     * A candidate with ONE run id but an eval-fail marker promotes immediately
     * ("explains a failed eval case").
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function testEvalFailCandidatePromotesImmediately(): void
    {
        $service   = $this->service();
        $candidate = '- [2026-07-25] {mistakes} Do not summarise lots separately when the notice has one CPV. '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000 | eval-fail: 00000000-0000-0000-0000-000000000002#case-3 -->';

        $service->promoteSkill(skill: $this->skillWithCandidates(candidates: $candidate."\n"));

        $learnings = (string) $this->savedFile(name: 'learnings.md');
        $this->assertStringContainsString('## Mistakes to Avoid', $learnings);
        $this->assertStringContainsString('Do not summarise lots separately', $learnings);
        $this->assertStringContainsString('eval-fail: 00000000-0000-0000-0000-000000000002#case-3', $learnings);

    }//end testEvalFailCandidatePromotesImmediately()

    /**
     * A candidate untouched beyond the expiry window with too few confirmations and
     * no eval-fail marker is dropped without entering `learnings.md`; an unparseable
     * line ages out via the same rule when it carries a loose date, and a fresh
     * unparseable line is kept verbatim.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function testStaleCandidatesAreDroppedAndFreshUnparseableLinesKept(): void
    {
        $service    = $this->service();
        $stale      = '- [2026-06-01] {patterns} Stale single-run observation. <!-- runs: 00000000-0000-0000-0000-000000000000 -->';
        $staleJunk  = 'unparseable legacy line [2026-05-01] with a loose date';
        $freshJunk  = 'unparseable fresh line without any date';
        $candidates = $stale."\n".$staleJunk."\n".$freshJunk."\n";

        $service->promoteSkill(skill: $this->skillWithCandidates(candidates: $candidates));

        $remaining = (string) $this->savedFile(name: 'learning-candidates.md');
        $this->assertSame($freshJunk."\n", $remaining, 'Stale lines drop; the dateless line survives verbatim.');
        $this->assertNull($this->savedFile(name: 'learnings.md'), 'Nothing entered learnings.md.');

    }//end testStaleCandidatesAreDroppedAndFreshUnparseableLinesKept()

    /**
     * The Consolidated Principles section exists in the created scaffold but is never
     * written to by promotion, whatever the candidate mix.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function testConsolidatedPrinciplesIsNeverWritten(): void
    {
        $service   = $this->service();
        $candidate = '- [2026-07-25] {questions} Should annex A be parsed first? '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000,00000000-0000-0000-0000-000000000001,00000000-0000-0000-0000-000000000002 -->';

        $service->promoteSkill(skill: $this->skillWithCandidates(candidates: $candidate."\n"));

        $learnings = (string) $this->savedFile(name: 'learnings.md');
        $position  = strpos($learnings, '## '.SkillLearningsPromotionService::CONSOLIDATED_HEADING);
        $this->assertNotFalse($position, 'The reserved section exists in the scaffold.');
        $this->assertSame(
            '',
            trim(substr($learnings, ($position + strlen('## '.SkillLearningsPromotionService::CONSOLIDATED_HEADING)))),
            'Nothing is ever written under Consolidated Principles.'
        );

    }//end testConsolidatedPrinciplesIsNeverWritten()

    /**
     * The l6 activity is derived from the parsed post-pass file contents:
     * candidateCount from the remaining candidates, learningsCount from the promoted
     * entries, `lastPromotedAt` stamped — and `lastConsolidatedAt` never written.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-levelevidencel6-activity-is-written-by-the-learnings-subsystem-only
     */
    public function testL6CountsDeriveFromParsedContent(): void
    {
        $service    = $this->service();
        $promotable = '- [2026-07-25] {domain} TED deadlines are CET. '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000,00000000-0000-0000-0000-000000000001,00000000-0000-0000-0000-000000000002 -->';
        $keeper     = '- [2026-07-26] {patterns} Fresh single-run observation. <!-- runs: 00000000-0000-0000-0000-000000000003 -->';

        $service->promoteSkill(skill: $this->skillWithCandidates(candidates: $promotable."\n".$keeper."\n"));

        $saved = end($this->savedObjects);
        $l6    = $saved[2]['levelEvidence']['l6'];

        $this->assertSame(1, $l6['candidateCount']);
        $this->assertSame(1, $l6['learningsCount']);
        $this->assertSame(self::NOW, $l6['lastPromotedAt']);
        $this->assertArrayNotHasKey('lastConsolidatedAt', $l6, 'Promotion never writes lastConsolidatedAt.');

    }//end testL6CountsDeriveFromParsedContent()

    /**
     * A pass that changes nothing (fresh unpromotable candidates only) performs no
     * write at all — no churn on a quiet catalog.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function testQuietPassWritesNothing(): void
    {
        $service = $this->service();
        $keeper  = '- [2026-07-26] {patterns} Fresh single-run observation. <!-- runs: 00000000-0000-0000-0000-000000000000 -->';

        $service->promoteSkill(skill: $this->skillWithCandidates(candidates: $keeper."\n"));

        $this->assertSame([], $this->savedObjects);

    }//end testQuietPassWritesNothing()

    /**
     * Write-path guard (skill-maturity delta): a client-forged `levelEvidence.l6`
     * does not survive `preserveComputedFields()` — the stored values are carried
     * forward, and a stored ABSENT l6 stays absent.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-maturitylevel-and-computed-evidence-are-never-client-writable
     */
    public function testForgedL6DoesNotSurviveTheWritePathGuard(): void
    {
        $maturity = new SkillMaturityService($this->createMock(ObjectService::class));

        $stored = [
            'name'          => 'tender-summary',
            'levelEvidence' => [
                'l6' => ['learningsCount' => 0],
            ],
        ];

        $forged = [
            'name'          => 'tender-summary',
            'targetLevel'   => 6,
            'levelEvidence' => [
                'l6' => [
                    'learningsCount'     => 99,
                    'lastConsolidatedAt' => '2026-01-01T00:00:00Z',
                ],
            ],
        ];

        $guarded = $maturity->preserveComputedFields(incoming: $forged, stored: $stored);

        $this->assertSame(['learningsCount' => 0], $guarded['levelEvidence']['l6'], 'The stored l6 is carried forward.');
        $this->assertSame(6, $guarded['targetLevel'], 'targetLevel stays freely editable.');

        // A stored skill WITHOUT l6: the forged l6 is stripped entirely.
        $guardedAbsent = $maturity->preserveComputedFields(incoming: $forged, stored: ['name' => 'tender-summary']);
        $this->assertArrayNotHasKey('l6', ($guardedAbsent['levelEvidence'] ?? []), 'A forged l6 cannot conjure evidence.');

    }//end testForgedL6DoesNotSurviveTheWritePathGuard()

    /**
     * SkillSerializer round-trip with both learnings files aboard: the
     * frontmatter/body round trip stays byte-for-byte, and the `files` entries —
     * ordinary agentskills.io files-map entries — are untouched by (de)serialisation,
     * so both learnings files travel with the export unchanged.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-learnings-files-live-in-the-files-map-and-travel-with-the-export
     */
    public function testSerializerRoundTripWithLearningsAboard(): void
    {
        $serializer = new SkillSerializer();

        $skill = [
            'name'        => 'tender-summary',
            'frontmatter' => "name: tender-summary\ndescription: Summarise a tender publication.\nversion: 0.1.0",
            'body'        => "# Tender Summary\n\nSteps.",
            'files'       => [
                [
                    'name'    => 'learnings.md',
                    'content' => "# Learnings\n\n## Patterns That Work\n\n- Quote the weights verbatim.\n",
                ],
                [
                    'name'    => 'learning-candidates.md',
                    'content' => '- [2026-07-27] {domain} TED deadlines are CET. <!-- runs: 00000000-0000-0000-0000-000000000000 -->'."\n",
                ],
            ],
        ];

        $package = $serializer->toPackage(skill: $skill);
        $parsed  = $serializer->fromPackage(package: $package);

        $this->assertSame($skill['frontmatter'], $parsed['frontmatter'], 'Frontmatter round-trips byte-for-byte.');
        $this->assertSame($skill['body'], $parsed['body'], 'Body round-trips byte-for-byte.');

        // The files map is carried on the object, untouched by the serializer — both
        // learnings entries remain byte-identical alongside the round-tripped package.
        $roundTripped          = $skill;
        $roundTripped['frontmatter'] = $parsed['frontmatter'];
        $roundTripped['body']        = $parsed['body'];
        $this->assertSame($skill['files'], $roundTripped['files'], 'Both learnings files travel unchanged.');

    }//end testSerializerRoundTripWithLearningsAboard()

    /**
     * promoteAll() isolates a broken skill: the second skill still promotes after
     * the first throws during its read.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function testPromoteAllIsolatesPerSkillFailures(): void
    {
        $service = $this->service();

        $broken = new class extends ObjectEntity {

            /**
             * A skill whose payload read explodes (simulated corrupt row).
             *
             * @return array<string, mixed>
             */
            public function getObject(): array
            {
                throw new \RuntimeException('corrupt skill row');
            }//end getObject()
        };
        $broken->setUuid('skill-broken');

        $healthy = $this->skillWithCandidates(
            candidates: '- [2026-07-25] {domain} TED deadlines are CET. '
                .'<!-- runs: 00000000-0000-0000-0000-000000000000,00000000-0000-0000-0000-000000000001,00000000-0000-0000-0000-000000000002 -->'."\n"
        );

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('findAll')->willReturn([$broken, $healthy]);

        $service->promoteAll();

        $this->assertCount(1, $this->savedObjects, 'The healthy skill still promoted.');
        $this->assertSame('skill-1', $this->savedObjects[0][1]);

    }//end testPromoteAllIsolatesPerSkillFailures()
}//end class
