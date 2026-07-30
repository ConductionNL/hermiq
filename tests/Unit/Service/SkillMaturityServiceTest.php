<?php

/**
 * Unit tests for SkillMaturityService (skill-maturity).
 *
 * Covers every mechanical L1–L3 rule, the contiguous fold, L4-never-auto-detected,
 * L5–L7 read-only evidence folding, the qualify persistence (maturityLevel + refreshed
 * l1–l3 evidence, everything else carried forward, `state` untouched), the attest-L4
 * stamp, the silent-preserve write guard (a hand-set `maturityLevel: 7` / forged `l4`
 * never survives), and the anti-drift guarantee that each seeded example skill's stored
 * `maturityLevel` equals what the service computes for its content.
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Repair\SeedMaturityExampleSkills;
use OCA\Hermiq\Service\SkillMaturityService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * SkillMaturityService level-rule + persistence tests (skill-maturity).
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
 */
class SkillMaturityServiceTest extends TestCase
{

    /**
     * A service over a mocked ObjectService.
     *
     * @param ObjectService|null $objectService Optional prepared mock.
     *
     * @return SkillMaturityService
     */
    private function service(?ObjectService $objectService=null): SkillMaturityService
    {
        if ($objectService === null) {
            $objectService = $this->createMock(ObjectService::class);
        }

        return new SkillMaturityService($objectService);

    }//end service()

    /**
     * A skill payload with a well-triggering description and compact body.
     *
     * @param array<string, mixed> $overrides Payload overrides.
     *
     * @return array<string, mixed>
     */
    private function triggeredSkill(array $overrides=[]): array
    {
        $frontmatter = "name: tender-summary\n"
            ."description: Summarise a tender publication — use when the user pastes a TED notice.\n"
            .'version: 0.1.0';

        return array_merge(
            [
                'name'        => 'tender-summary',
                'description' => 'Summarise a tender publication — use when the user pastes a TED notice.',
                'frontmatter' => $frontmatter,
                'body'        => "# Tender Summary\n\n1. Extract essentials.\n2. Flag go/no-go signals.\n",
                'files'       => [],
                'state'       => 'active',
            ],
            $overrides
        );

    }//end triggeredSkill()

    /**
     * The scorecard entry for one level.
     *
     * @param array<string, mixed> $computed The computeScorecard() result.
     * @param int                  $level    The level (1–7).
     *
     * @return array<string, mixed>
     */
    private function entry(array $computed, int $level): array
    {
        return $computed['scorecard'][($level - 1)];

    }//end entry()

    /**
     * A structurally valid skill with a bare-noun-phrase description is L1: the
     * scorecard reports L2 failed with a triggering reason.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    public function testStructurallyValidButPoorlyTriggeringSkillIsL1(): void
    {
        $computed = $this->service()->computeScorecard(
            data: $this->triggeredSkill(
                [
                    'description' => 'Meeting notes helper',
                    'frontmatter' => "name: meeting-notes-cleanup\ndescription: Meeting notes helper\nversion: 0.1.0",
                ]
            )
        );

        $this->assertSame(1, $computed['maturityLevel']);
        $this->assertFalse($this->entry($computed, 2)['passed']);
        $this->assertContains('description does not start with trigger phrasing', $this->entry($computed, 2)['reasons']);

    }//end testStructurallyValidButPoorlyTriggeringSkillIsL1()

    /**
     * Missing frontmatter name/description or an empty body fails L1 with structure
     * reasons, folding to level 0.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    public function testMissingAnatomyFailsL1(): void
    {
        $computed = $this->service()->computeScorecard(
            data: [
                'frontmatter' => 'name: incomplete',
                'body'        => '',
            ]
        );

        $this->assertSame(0, $computed['maturityLevel']);
        $this->assertFalse($this->entry($computed, 1)['passed']);
        $this->assertContains('frontmatter has no description', $this->entry($computed, 1)['reasons']);
        $this->assertContains('body is empty', $this->entry($computed, 1)['reasons']);

    }//end testMissingAnatomyFailsL1()

    /**
     * A compact well-triggering skill without reference files is L2: the scorecard
     * reports L3 failed for missing references/examples.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    public function testCompactTriggeredSkillWithoutReferencesIsL2(): void
    {
        $computed = $this->service()->computeScorecard(data: $this->triggeredSkill());

        $this->assertSame(2, $computed['maturityLevel']);
        $this->assertFalse($this->entry($computed, 3)['passed']);
        $this->assertContains('no references/ or examples/ entry in files', $this->entry($computed, 3)['reasons']);

    }//end testCompactTriggeredSkillWithoutReferencesIsL2()

    /**
     * A skill with references/ + examples/ entries passes L3 (level 3).
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    public function testReferencesAndExamplesFilesGiveL3(): void
    {
        $computed = $this->service()->computeScorecard(
            data: $this->triggeredSkill(
                [
                    'files' => [
                        [
                            'name'    => 'references/grounds.md',
                            'content' => 'reference content',
                        ],
                        [
                            'name'    => 'examples/output.md',
                            'content' => 'example content',
                        ],
                    ],
                ]
            )
        );

        $this->assertSame(3, $computed['maturityLevel']);
        $this->assertTrue($this->entry($computed, 3)['passed']);

    }//end testReferencesAndExamplesFilesGiveL3()

    /**
     * A 500-line monolith fails L2 even with a good description: both the hard cap and
     * the progressive-disclosure reason surface, and the level folds to 1.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    public function testMonolithicBodyFailsL2EvenWithGoodDescription(): void
    {
        $body     = str_repeat("a body line\n", 500);
        $computed = $this->service()->computeScorecard(data: $this->triggeredSkill(['body' => $body]));

        $this->assertSame(1, $computed['maturityLevel']);
        $this->assertFalse($this->entry($computed, 2)['passed']);
        $this->assertContains('body is 500 lines or more', $this->entry($computed, 2)['reasons']);
        $this->assertContains(
            'large body has no references/ entries (no progressive disclosure)',
            $this->entry($computed, 2)['reasons']
        );

    }//end testMonolithicBodyFailsL2EvenWithGoodDescription()

    /**
     * A 200+-line body WITH references/ entries shows progressive disclosure and passes
     * L2 (and L3, since the references also satisfy it).
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    public function testLargeBodyWithReferencesShowsProgressiveDisclosure(): void
    {
        $body     = str_repeat("a body line\n", 250);
        $computed = $this->service()->computeScorecard(
            data: $this->triggeredSkill(
                [
                    'body'  => $body,
                    'files' => [
                        [
                            'name'    => 'references/details.md',
                            'content' => 'the split-out detail',
                        ],
                    ],
                ]
            )
        );

        $this->assertSame(3, $computed['maturityLevel']);
        $this->assertTrue($this->entry($computed, 2)['passed']);

    }//end testLargeBodyWithReferencesShowsProgressiveDisclosure()

    /**
     * Levels are contiguous: a skill failing L2 but holding references/ files (L3's
     * check alone would pass) stays level 1.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    public function testLevelsAreContiguous(): void
    {
        $computed = $this->service()->computeScorecard(
            data: $this->triggeredSkill(
                [
                    'description' => 'Meeting notes helper',
                    'frontmatter' => "name: meeting-notes-cleanup\ndescription: Meeting notes helper\nversion: 0.1.0",
                    'files'       => [
                        [
                            'name'    => 'references/grounds.md',
                            'content' => 'reference content',
                        ],
                    ],
                ]
            )
        );

        $this->assertSame(1, $computed['maturityLevel']);
        $this->assertTrue($this->entry($computed, 3)['passed']);

    }//end testLevelsAreContiguous()

    /**
     * L4 is never auto-detected: a skill with perfect L1–L3 content but no attestation
     * never exceeds level 3, and the scorecard says so.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
     */
    public function testL4IsNeverAutoDetected(): void
    {
        $computed = $this->service()->computeScorecard(
            data: $this->triggeredSkill(
                [
                    'files' => [
                        [
                            'name'    => 'references/grounds.md',
                            'content' => 'reference content',
                        ],
                    ],
                ]
            )
        );

        $this->assertSame(3, $computed['maturityLevel']);
        $this->assertFalse($this->entry($computed, 4)['passed']);
        $this->assertContains('not human-attested', $this->entry($computed, 4)['reasons']);

    }//end testL4IsNeverAutoDetected()

    /**
     * An L4-attested skill without eval evidence caps at 4 with the honest L5 reason.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l5l7-are-read-from-evidence-written-by-other-subsystems
     */
    public function testMissingEvalEvidenceCapsAtL4(): void
    {
        $computed = $this->service()->computeScorecard(data: $this->attestedSkill());

        $this->assertSame(4, $computed['maturityLevel']);
        $this->assertContains('no eval evidence (levelEvidence.l5 empty)', $this->entry($computed, 5)['reasons']);

    }//end testMissingEvalEvidenceCapsAtL4()

    /**
     * Externally-written complete L5 evidence is honoured on the next qualify (level 5);
     * incomplete evidence stays capped with the incomplete reason.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l5l7-are-read-from-evidence-written-by-other-subsystems
     */
    public function testCompleteL5EvidenceGivesLevel5(): void
    {
        $complete = $this->attestedSkill(
            [
                'l5' => [
                    'evalDatasetId' => '00000000-0000-0000-0000-000000000000',
                    'passRate'      => 0.9,
                    'baselineDelta' => 0.15,
                    'lastValidated' => '2026-07-01T00:00:00+00:00',
                ],
            ]
        );

        $incomplete = $this->attestedSkill(
            [
                'l5' => [
                    'evalDatasetId' => '00000000-0000-0000-0000-000000000000',
                ],
            ]
        );

        $service = $this->service();

        $this->assertSame(5, $service->computeScorecard(data: $complete)['maturityLevel']);

        $capped = $service->computeScorecard(data: $incomplete);
        $this->assertSame(4, $capped['maturityLevel']);
        $this->assertContains('incomplete eval evidence (levelEvidence.l5)', $this->entry($capped, 5)['reasons']);

    }//end testCompleteL5EvidenceGivesLevel5()

    /**
     * L6 requires learnings activity and L7 requires an EXECUTED chain — a declared
     * chain alone never passes L7; the full evidence chain folds to 7.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l5l7-are-read-from-evidence-written-by-other-subsystems
     */
    public function testL6AndL7EvidenceFolding(): void
    {
        $l5 = [
            'evalDatasetId' => '00000000-0000-0000-0000-000000000000',
            'passRate'      => 0.9,
            'baselineDelta' => 0.15,
            'lastValidated' => '2026-07-01T00:00:00+00:00',
        ];
        $l6 = [
            'learningsCount'     => 3,
            'lastConsolidatedAt' => '2026-07-02T00:00:00+00:00',
        ];

        $service = $this->service();

        $declaredOnly = $this->attestedSkill(
            [
                'l5' => $l5,
                'l6' => $l6,
                'l7' => ['declaredChain' => ['00000000-0000-0000-0000-000000000000']],
            ]
        );
        $computed     = $service->computeScorecard(data: $declaredOnly);
        $this->assertSame(6, $computed['maturityLevel']);
        $this->assertContains('no executed chain run', $this->entry($computed, 7)['reasons']);

        $executed = $this->attestedSkill(
            [
                'l5' => $l5,
                'l6' => $l6,
                'l7' => [
                    'declaredChain'          => ['00000000-0000-0000-0000-000000000000'],
                    'lastExecutedChainRunId' => 'run-1',
                    'lastExecutedAt'         => '2026-07-03T00:00:00+00:00',
                ],
            ]
        );
        $this->assertSame(7, $service->computeScorecard(data: $executed)['maturityLevel']);

    }//end testL6AndL7EvidenceFolding()

    /**
     * qualify() persists maturityLevel + refreshed l1–l3 evidence while carrying
     * `state`, the content plane, the l4 attestation and l5–l7 evidence forward
     * unchanged (never writes l5–l7, never touches state), and returns the payload.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     * @spec openspec/specs/skill-maturity/spec.md#requirement-maturity-is-orthogonal-to-the-curation-lifecycle
     */
    public function testQualifyPersistsLevelAndCarriesEverythingForward(): void
    {
        $data = $this->attestedSkill(
            [
                'l5' => ['evalDatasetId' => '00000000-0000-0000-0000-000000000000'],
            ]
        );

        $data['state']       = 'quarantined';
        $data['targetLevel'] = 5;

        $skill = new ObjectEntity();
        $skill->setUuid('skill-uuid-1');
        $skill->setObject($data);

        $saved         = [];
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null) use (&$saved): ObjectEntity {
                    $saved = $object;
                    return new ObjectEntity();
                }
            );

        $result = $this->service(objectService: $objectService)->qualify(skill: $skill);

        // Persisted: computed level + refreshed l1–l3 evidence.
        $this->assertSame(4, $saved['maturityLevel']);
        $this->assertTrue($saved['levelEvidence']['l1']['passed']);
        $this->assertTrue($saved['levelEvidence']['l2']['passed']);
        $this->assertTrue($saved['levelEvidence']['l3']['passed']);
        $this->assertNotSame('', (string) $saved['levelEvidence']['l1']['checkedAt']);

        // Carried forward untouched: lifecycle state, attestation, external evidence.
        $this->assertSame('quarantined', $saved['state']);
        $this->assertSame('admin', $saved['levelEvidence']['l4']['attestedBy']);
        $this->assertSame(
            '00000000-0000-0000-0000-000000000000',
            $saved['levelEvidence']['l5']['evalDatasetId']
        );

        // Response payload shape.
        $this->assertSame('skill-uuid-1', $result['skillId']);
        $this->assertSame(4, $result['maturityLevel']);
        $this->assertSame(5, $result['targetLevel']);
        $this->assertCount(7, $result['scorecard']);

    }//end testQualifyPersistsLevelAndCarriesEverythingForward()

    /**
     * attestL4() stamps attestedBy/attestedAt/note and the recomputed level becomes 4
     * for an L3 skill.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
     */
    public function testAttestL4StampsAttestationAndRecomputes(): void
    {
        $data = $this->triggeredSkill(
            [
                'files' => [
                    [
                        'name'    => 'references/grounds.md',
                        'content' => 'reference content',
                    ],
                ],
            ]
        );

        $skill = new ObjectEntity();
        $skill->setUuid('skill-uuid-2');
        $skill->setObject($data);

        $saved         = [];
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')
            ->willReturnCallback(
                function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null) use (&$saved): ObjectEntity {
                    $saved = $object;
                    return new ObjectEntity();
                }
            );

        $result = $this->service(objectService: $objectService)->attestL4(
            skill: $skill,
            attestedBy: 'noor',
            note: 'Tuned for our WOO workflow'
        );

        $this->assertSame(4, $result['maturityLevel']);
        $this->assertSame('noor', $saved['levelEvidence']['l4']['attestedBy']);
        $this->assertSame('Tuned for our WOO workflow', $saved['levelEvidence']['l4']['note']);
        $this->assertNotSame('', (string) $saved['levelEvidence']['l4']['attestedAt']);

    }//end testAttestL4StampsAttestationAndRecomputes()

    /**
     * The silent-preserve write guard: a hand-set maturityLevel 7 and a forged l4 never
     * survive — stored values win; targetLevel and l5–l7 pass through client-editable.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-maturitylevel-and-computed-evidence-are-never-client-writable
     */
    public function testPreserveComputedFieldsBlocksHandSetValues(): void
    {
        $stored = $this->triggeredSkill(
            [
                'maturityLevel' => 2,
                'targetLevel'   => 2,
                'levelEvidence' => [
                    'l1' => [
                        'passed'    => true,
                        'checkedAt' => '2026-07-01T00:00:00+00:00',
                    ],
                ],
            ]
        );

        $incoming = $this->triggeredSkill(
            [
                'maturityLevel' => 7,
                'targetLevel'   => 4,
                'levelEvidence' => [
                    'l1' => [
                        'passed'    => true,
                        'checkedAt' => 'forged',
                    ],
                    'l4' => [
                        'attestedBy' => 'attacker',
                        'attestedAt' => '2026-07-01T00:00:00+00:00',
                    ],
                    'l5' => ['evalDatasetId' => '00000000-0000-0000-0000-000000000000'],
                ],
            ]
        );

        $guarded = $this->service()->preserveComputedFields(incoming: $incoming, stored: $stored);

        $this->assertSame(2, $guarded['maturityLevel']);
        $this->assertSame('2026-07-01T00:00:00+00:00', $guarded['levelEvidence']['l1']['checkedAt']);
        $this->assertArrayNotHasKey('l4', $guarded['levelEvidence']);
        // Curator intent and other-subsystem evidence stay client-writable.
        $this->assertSame(4, $guarded['targetLevel']);
        $this->assertArrayHasKey('l5', $guarded['levelEvidence']);

    }//end testPreserveComputedFieldsBlocksHandSetValues()

    /**
     * The guard also strips maturity fields on CREATE (no stored payload yet).
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-maturitylevel-and-computed-evidence-are-never-client-writable
     */
    public function testPreserveComputedFieldsStripsOnCreate(): void
    {
        $incoming = $this->triggeredSkill(
            [
                'maturityLevel' => 7,
                'levelEvidence' => [
                    'l4' => [
                        'attestedBy' => 'attacker',
                        'attestedAt' => '2026-07-01T00:00:00+00:00',
                    ],
                ],
            ]
        );

        $guarded = $this->service()->preserveComputedFields(incoming: $incoming, stored: []);

        $this->assertArrayNotHasKey('maturityLevel', $guarded);
        $this->assertArrayNotHasKey('l4', $guarded['levelEvidence']);

    }//end testPreserveComputedFieldsStripsOnCreate()

    /**
     * Anti-drift: every seeded example skill's stored maturityLevel equals what the
     * service computes for its content (L1 / L2 / L4 per the design).
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
     */
    public function testSeedSkillsMatchComputedLevels(): void
    {
        $service = $this->service();

        $expected = [
            'meeting-notes-cleanup' => 1,
            'woo-request-triage'    => 2,
            'tender-summary'        => 4,
        ];

        $seen = [];
        foreach (SeedMaturityExampleSkills::seedSkills() as $seed) {
            $name             = (string) $seed['name'];
            $seen[]           = $name;
            $computed         = $service->computeScorecard(data: $seed);
            $this->assertSame(
                $expected[$name],
                $computed['maturityLevel'],
                sprintf('Seed %s drifted from the maturity rules', $name)
            );
            $this->assertSame(
                $expected[$name],
                (int) $seed['maturityLevel'],
                sprintf('Seed %s stores a maturityLevel differing from the computed one', $name)
            );
        }

        $this->assertSame(array_keys($expected), $seen);

    }//end testSeedSkillsMatchComputedLevels()

    /**
     * An L4-attested skill payload (L1–L3 passing content + attestation), with optional
     * extra levelEvidence entries.
     *
     * @param array<string, mixed> $extraEvidence Extra levelEvidence entries (l5–l7).
     *
     * @return array<string, mixed>
     */
    private function attestedSkill(array $extraEvidence=[]): array
    {
        $evidence = array_merge(
            [
                'l4' => [
                    'attestedBy' => 'admin',
                    'attestedAt' => '2026-01-15T09:00:00+00:00',
                    'note'       => 'seeded',
                ],
            ],
            $extraEvidence
        );

        return $this->triggeredSkill(
            [
                'files'         => [
                    [
                        'name'    => 'references/grounds.md',
                        'content' => 'reference content',
                    ],
                    [
                        'name'    => 'examples/output.md',
                        'content' => 'example content',
                    ],
                ],
                'levelEvidence' => $evidence,
            ]
        );

    }//end attestedSkill()
}//end class
