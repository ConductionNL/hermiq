<?php

/**
 * Hermiq Seed Maturity Example Skills Repair Step.
 *
 * Idempotently seeds THREE example skills at distinct maturity levels (skill-maturity,
 * ADR-068) so a fresh install's catalog dots and SkillDetail scorecard render
 * meaningfully: `meeting-notes-cleanup` (L1 — structurally valid, poorly triggering),
 * `woo-request-triage` (L2 — municipality WOO triage, compact procedural body, no
 * reference files), and `tender-summary` (L4 — consultancy tender summarisation with
 * `references/` + `examples/` files and a seeded L4 attestation; its scorecard shows L5
 * failing with "no eval evidence", pointing at the future skill-evals change).
 *
 * Written through OpenRegister's ObjectService single write-path in system context
 * (`_rbac: false, _multitenancy: false`), matched by name so a re-run never duplicates a
 * seed or overwrites an admin's edit — mirroring `SeedSkillCreator` exactly. Each seed's
 * stored `maturityLevel` MUST equal what `SkillMaturityService` computes for its content;
 * `SkillMaturityServiceTest` asserts this so the seeds can never drift from the rules.
 * Placeholders only (general municipality/consultancy content, no real data).
 *
 * skill-learnings extends the `tender-summary` seed with demo learnings: a five-section
 * `learnings.md` (Consolidated Principles deliberately EMPTY — consolidation has not
 * run), a `learning-candidates.md` with two grammar-exact candidate lines (nil-UUID run
 * ids), and matching `levelEvidence.l6` activity WITHOUT `lastConsolidatedAt` (so the
 * maturity scorecard truthfully shows L6 not yet passed). On an EXISTING install the
 * step adds each learnings artifact only when absent — an admin-edited skill is never
 * overwritten and a re-run never duplicates content.
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the three maturity example skills via ObjectService (idempotent, by name).
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */
class SeedMaturityExampleSkills implements IRepairStep
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for Skill objects (namespaced to avoid a cross-app slug collision).
     *
     * @var string
     */
    private const SKILL_SCHEMA = 'agentskill';

    /**
     * The seeded L4 attestation timestamp (fixed — a seed must be deterministic).
     *
     * @var string
     */
    private const SEED_ATTESTED_AT = '2026-01-15T09:00:00+00:00';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container Server container for lazy ObjectService resolution
     *                                      (OpenRegister may not be installed yet).
     * @param LoggerInterface    $logger    PSR-3 logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
     */
    public function getName(): string
    {
        return 'Seed maturity example skills (skill-maturity)';

    }//end getName()

    /**
     * Seed each example skill that does not already exist (matched by name); an
     * existing seed — including one an admin has since edited — is left untouched.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
     */
    public function run(IOutput $output): void
    {
        try {
            $objectService = $this->container->get(ObjectService::class);
        } catch (Throwable $e) {
            $output->warning('OpenRegister not available — skipping maturity example skills seed.');
            $this->logger->warning('[hermiq] maturity example skills seed skipped: '.$e->getMessage());
            return;
        }

        foreach (self::seedSkills() as $seed) {
            $name = (string) $seed['name'];

            try {
                $existing = $this->findByName(objectService: $objectService, name: $name);
                if ($existing !== null) {
                    if ($name === 'tender-summary') {
                        // Skill-learnings: an upgraded install already carries the
                        // tender-summary seed — add ONLY the missing demo learnings
                        // artifacts (files added only when absent; admin edits and
                        // any real l6 activity are never overwritten).
                        $this->ensureLearningsSeed(objectService: $objectService, skill: $existing, output: $output);
                        continue;
                    }

                    $output->info($name.' seed already present — skipped.');
                    continue;
                }

                // Written DIRECTLY (never via installFromSource): first-party trusted
                // content, lands `active` immediately, never scanned/quarantined.
                $objectService->saveObject(
                    object: $seed,
                    register: self::REGISTER_SLUG,
                    schema: self::SKILL_SCHEMA,
                    _rbac: false,
                    _multitenancy: false
                );
                $output->info($name.' seed complete.');
            } catch (Throwable $e) {
                $output->warning('Could not seed '.$name.' skill: '.$e->getMessage());
                $this->logger->error('[hermiq] '.$name.' seed failed: '.$e->getMessage());
            }//end try
        }//end foreach

    }//end run()

    /**
     * The three seed skill payloads. Public + static so `SkillMaturityServiceTest` can
     * assert each stored `maturityLevel` equals what the service computes for the seed's
     * content (the anti-drift guarantee of the spec).
     *
     * @return array<int, array<string, mixed>> The seed Skill object payloads.
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
     */
    public static function seedSkills(): array
    {
        return [
            self::meetingNotesCleanup(),
            self::wooRequestTriage(),
            self::tenderSummary(),
        ];

    }//end seedSkills()

    /**
     * L1 seed: structurally valid (frontmatter name + description, non-empty body) but
     * poorly triggering — the description is a bare noun phrase, so L2's trigger check
     * fails. `targetLevel: 2`.
     *
     * @return array<string, mixed> The Skill payload.
     */
    private static function meetingNotesCleanup(): array
    {
        $frontmatter = <<<'YAML'
        name: meeting-notes-cleanup
        description: Meeting notes helper
        version: 0.1.0
        YAML;

        $body = <<<'MARKDOWN'
        # Meeting Notes Cleanup

        Tidy up raw meeting notes: fix headings, group action items under an "Actions"
        section with owners and due dates, and remove filler. Keep the original wording
        of decisions intact.
        MARKDOWN;

        return [
            'name'             => 'meeting-notes-cleanup',
            'description'      => 'Meeting notes helper',
            'frontmatter'      => $frontmatter,
            'body'             => $body,
            'files'            => [],
            'state'            => 'active',
            'source'           => 'local',
            'quarantineReason' => null,
            'scanReport'       => null,
            'createdBy'        => '',
            'installedOn'      => [],
            'maturityLevel'    => 1,
            'targetLevel'      => 2,
        ];

    }//end meetingNotesCleanup()

    /**
     * L2 seed (municipality context): clear trigger phrasing + when-to-use, compact
     * procedural body, no reference files — so L3 fails on missing references/examples.
     * `targetLevel: 3`.
     *
     * @return array<string, mixed> The Skill payload.
     */
    private static function wooRequestTriage(): array
    {
        $description = 'Triage an incoming WOO request — use when a new WOO/Woo-verzoek arrives '
            .'and needs routing, deadline and exemption pre-check.';

        $frontmatter = <<<'YAML'
        name: woo-request-triage
        description: Triage an incoming WOO request — use when a new WOO/Woo-verzoek arrives and needs routing, deadline and exemption pre-check.
        version: 0.1.0
        YAML;

        $body = <<<'MARKDOWN'
        # WOO Request Triage

        You triage incoming WOO (Wet open overheid) requests for a Dutch municipality.
        Work through the steps below for every new request; never skip the deadline
        calculation.

        ## 1. Identify the request

        1. Confirm the message is a WOO request: it asks for documents or information
           held by the municipality. If it is a normal service question, route it to the
           service desk instead.
        2. Record the received date — that date starts the statutory clock.
        3. Note the requester's preferred channel (email, letter, portal).

        ## 2. Route it

        1. Determine the responsible department from the subject (permits, social
           domain, finance, council affairs).
        2. Assign a case handler placeholder: `YOUR_CASE_HANDLER_HERE`.
        3. When multiple departments hold documents, mark the request as
           "multi-department" and list every department involved.

        ## 3. Deadline pre-check

        1. The statutory response term is 4 weeks from the received date.
        2. One extension of 2 weeks is possible when the request is extensive —
           flag it now if the scope already looks broad.
        3. Compute both dates and record them on the case.

        ## 4. Exemption pre-check

        1. Scan the requested scope for likely exemption grounds: personal data,
           company-confidential information, internal deliberation documents.
        2. Do NOT decide the exemption here — only flag which grounds the handler
           must assess.
        3. Summarise the flags in one short paragraph for the handler.

        ## 5. Output

        Produce a triage note with: request summary, department routing, handler
        placeholder, both deadline dates, and the exemption flags. Keep it under
        200 words; the handler reads it as a checklist, not prose.

        ## Rules

        - Never fabricate a received date — ask when it is missing.
        - Never name real persons in the triage note; use role names.
        - When in doubt about scope, flag it — a wrong "narrow" call costs the
          deadline.
        MARKDOWN;

        return [
            'name'             => 'woo-request-triage',
            'description'      => $description,
            'frontmatter'      => $frontmatter,
            'body'             => $body,
            'files'            => [],
            'state'            => 'active',
            'source'           => 'local',
            'quarantineReason' => null,
            'scanReport'       => null,
            'createdBy'        => '',
            'installedOn'      => [],
            'maturityLevel'    => 2,
            'targetLevel'      => 3,
        ];

    }//end wooRequestTriage()

    /**
     * L4 seed (consultancy context): trigger-quality description, compact body,
     * `references/` + `examples/` files, and a seeded L4 attestation (synthetic —
     * the note says so; real attestation flows through the action-gated endpoint).
     * `targetLevel: 5` — its scorecard shows L5 failing with "no eval evidence",
     * pointing at the future skill-evals change.
     *
     * @return array<string, mixed> The Skill payload.
     */
    private static function tenderSummary(): array
    {
        $description = 'Summarise a tender publication — use when the user pastes or links a TenderNed/TED notice.';

        $frontmatter = <<<'YAML'
        name: tender-summary
        description: Summarise a tender publication — use when the user pastes or links a TenderNed/TED notice.
        version: 0.1.0
        YAML;

        $body = <<<'MARKDOWN'
        # Tender Summary

        You summarise public-procurement notices (TenderNed / TED) for a consultancy
        that decides whether to bid. Follow the steps; use the reference file for
        exclusion/exemption grounds and the example file for the output shape.

        ## Steps

        1. Extract the essentials: contracting authority, CPV codes, lot structure,
           estimated value, submission deadline, award criteria and their weights.
        2. Flag the go/no-go signals: required certifications, minimum turnover,
           framework vs. one-off, incumbent hints.
        3. Check `references/exemption-grounds.md` for grounds that would exclude
           the client from bidding — list any that might apply.
        4. Produce the summary exactly in the shape of
           `examples/tender-summary-example.md`.

        ## Rules

        - Never invent a deadline or value — quote the notice or write "not stated".
        - Always give the award-criteria weights as published.
        - Keep the summary under one page; the bid team reads it in a stand-up.
        MARKDOWN;

        $referenceContent = <<<'MARKDOWN'
        # Exemption and Exclusion Grounds (NL public procurement)

        Quick-reference list the summary step checks against:

        - Mandatory exclusion: criminal convictions of the tenderer (participation in a
          criminal organisation, corruption, fraud, money laundering).
        - Discretionary exclusion: grave professional misconduct, significant
          deficiencies in a prior public contract, conflict of interest.
        - Self-cleaning: a tenderer may document remedial measures; note it, do not
          judge it.

        This is seed reference content for the example skill — verify against the
        current Aanbestedingswet text before relying on it.
        MARKDOWN;

        $exampleContent = <<<'MARKDOWN'
        # Example: tender summary output shape

        **Notice**: Cloud workplace services — Gemeente Voorbeeld (TenderNed
        00000000-0000-0000-0000-000000000000)

        - **Authority**: Gemeente Voorbeeld
        - **CPV**: 72000000 (IT services)
        - **Value**: EUR 1,200,000 (estimated, 4 years)
        - **Deadline**: 2026-03-01 12:00 CET
        - **Award criteria**: quality 60% / price 40%
        - **Go/no-go flags**: ISO 27001 required; incumbent mentioned in annex A.
        - **Exclusion check**: none of the listed grounds apply on current facts.

        **Advice line**: worth a bid review — quality-weighted award suits us.
        MARKDOWN;

        return [
            'name'             => 'tender-summary',
            'description'      => $description,
            'frontmatter'      => $frontmatter,
            'body'             => $body,
            'files'            => [
                [
                    'name'    => 'references/exemption-grounds.md',
                    'content' => $referenceContent,
                ],
                [
                    'name'    => 'examples/tender-summary-example.md',
                    'content' => $exampleContent,
                ],
                [
                    'name'    => 'learnings.md',
                    'content' => self::seedLearningsContent(),
                ],
                [
                    'name'    => 'learning-candidates.md',
                    'content' => self::seedCandidatesContent(),
                ],
            ],
            'state'            => 'active',
            'source'           => 'local',
            'quarantineReason' => null,
            'scanReport'       => null,
            'createdBy'        => '',
            'installedOn'      => [],
            'maturityLevel'    => 4,
            'targetLevel'      => 5,
            'levelEvidence'    => [
                'l4' => [
                    'attestedBy' => 'admin',
                    'attestedAt' => self::SEED_ATTESTED_AT,
                    'note'       => 'Tuned for NL public-procurement summaries (seeded example attestation).',
                ],
                'l6' => self::seedL6Evidence(),
            ],
        ];

    }//end tenderSummary()

    /**
     * The demo `learnings.md` (skill-learnings): the five fixed sections with six
     * consultancy-context entries; Consolidated Principles deliberately EMPTY —
     * consolidation (`skill-self-improvement`) has not run, so the maturity scorecard
     * stays honest. Entry provenance markers use nil-UUID placeholders only.
     *
     * @return string The demo learnings content.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape
     */
    public static function seedLearningsContent(): string
    {
        $date = self::seedDate();

        $nilRun    = '00000000-0000-0000-0000-000000000000';
        $marker    = '<!-- promoted '.$date.' | runs: '.$nilRun.' -->';
        $markdown  = "# Learnings\n\n";
        $markdown .= "## Patterns That Work\n\n";
        $markdown .= '- Quoting the award-criteria weights verbatim avoids a manual re-check by the bid team. '.$marker."\n";
        $markdown .= '- Leading the summary with the submission deadline speeds the go/no-go call. '.$marker."\n\n";
        $markdown .= "## Mistakes to Avoid\n\n";
        $markdown .= '- Do not infer an estimated value when the notice says "not stated" — flag it instead. '.$marker."\n";
        $markdown .= '- Do not merge lots with different CPV codes into one summary block. '.$marker."\n\n";
        $markdown .= "## Domain Knowledge\n\n";
        $markdown .= '- TED deadlines are CET, not the contracting authority\'s local time. '.$marker."\n\n";
        $markdown .= "## Open Questions\n\n";
        $markdown .= '- Does the bid team want incumbent hints ranked above certification requirements? '.$marker."\n\n";
        $markdown .= "## Consolidated Principles\n";

        return $markdown;

    }//end seedLearningsContent()

    /**
     * The demo `learning-candidates.md` (skill-learnings): two candidate lines in the
     * EXACT normative grammar — one with a single nil-UUID run id, one with two — so
     * the promotion job and the Learnings UI have real input on a fresh install.
     *
     * @return string The demo candidates content.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape
     */
    public static function seedCandidatesContent(): string
    {
        $date = self::seedDate();

        return '- ['.$date.'] {domain} Framework agreements on TenderNed republish under a new notice id for each call-off. '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000 -->'."\n"
            .'- ['.$date.'] {patterns} Checking annex A for an incumbent before summarising saves a full re-read. '
            .'<!-- runs: 00000000-0000-0000-0000-000000000000,00000000-0000-0000-0000-000000000001 -->'."\n";

    }//end seedCandidatesContent()

    /**
     * The demo `levelEvidence.l6` activity matching the seeded files: 2 candidates,
     * 6 promoted learnings, capture/promotion timestamps — deliberately NO
     * `lastConsolidatedAt`, so L6 truthfully reads as not passed.
     *
     * @return array<string, mixed> The l6 evidence.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape
     */
    public static function seedL6Evidence(): array
    {
        $stamp = self::seedDate().'T09:00:00+00:00';

        return [
            'candidateCount' => 2,
            'learningsCount' => 6,
            'lastCaptureAt'  => $stamp,
            'lastPromotedAt' => $stamp,
        ];

    }//end seedL6Evidence()

    /**
     * The seed date for the demo learnings (today, UTC): a candidate seeded with a
     * fixed historic date would be silently expired by the promotion job's 30-day
     * rule on its very first pass, defeating the demo. Idempotency comes from the
     * only-when-absent rule, not from byte-determinism.
     *
     * @return string Today's UTC date (`YYYY-MM-DD`).
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape
     */
    private static function seedDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

    }//end seedDate()

    /**
     * Add the missing demo learnings artifacts to an EXISTING tender-summary seed
     * (upgrade path): each of the two files is added only when absent, and the l6
     * activity is stamped only when no l6 evidence exists yet — an admin's edits and
     * any real learnings activity are never overwritten, and a re-run changes nothing.
     *
     * @param ObjectService $objectService The OpenRegister object service.
     * @param ObjectEntity  $skill         The existing tender-summary Skill.
     * @param IOutput       $output        Repair output channel.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape
     */
    private function ensureLearningsSeed(ObjectService $objectService, ObjectEntity $skill, IOutput $output): void
    {
        $data  = $skill->getObject();
        $files = ($data['files'] ?? []);
        if (is_array($files) === false) {
            $files = [];
        }

        $changed = false;

        if ($this->hasFile(files: $files, name: 'learnings.md') === false) {
            $files[] = [
                'name'    => 'learnings.md',
                'content' => self::seedLearningsContent(),
            ];
            $changed = true;
        }

        if ($this->hasFile(files: $files, name: 'learning-candidates.md') === false) {
            $files[] = [
                'name'    => 'learning-candidates.md',
                'content' => self::seedCandidatesContent(),
            ];
            $changed = true;
        }

        $evidence = ($data['levelEvidence'] ?? []);
        if (is_array($evidence) === false) {
            $evidence = [];
        }

        $l6 = ($evidence['l6'] ?? []);
        if ($changed === true && (is_array($l6) === false || $l6 === [])) {
            $evidence['l6']        = self::seedL6Evidence();
            $data['levelEvidence'] = $evidence;
        }

        if ($changed === false) {
            $output->info('tender-summary seed already present (learnings included) — skipped.');
            return;
        }

        $data['files'] = array_values($files);

        $objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: (string) $skill->getUuid(),
            _rbac: false,
            _multitenancy: false
        );
        $output->info('tender-summary seed gained the demo learnings files.');

    }//end ensureLearningsSeed()

    /**
     * Whether the `files` array already carries an entry with the given name.
     *
     * @param array<int, mixed> $files The skill's `files` array.
     * @param string            $name  The entry name.
     *
     * @return bool True when present.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-one-seeded-skill-demonstrates-the-learnings-shape
     */
    private function hasFile(array $files, string $name): bool
    {
        foreach ($files as $file) {
            if (is_array($file) === true && (string) ($file['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;

    }//end hasFile()

    /**
     * Find the Skill with the given name, when it exists (system context, no RBAC).
     * Formerly a boolean `nameExists()` — skill-learnings needs the entity itself so
     * the upgrade path can add missing learnings artifacts to an existing seed.
     *
     * @param ObjectService $objectService The OpenRegister object service.
     * @param string        $name          The seed skill name (idempotency key).
     *
     * @return ObjectEntity|null The matching Skill, or null when absent.
     */
    private function findByName(ObjectService $objectService, string $name): ?ObjectEntity
    {
        $objects = $objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SKILL_SCHEMA)
            ->findAll(
                config: ['filters' => ['name' => $name], 'limit' => 50],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['name'] ?? '') === $name) {
                return $object;
            }
        }

        return null;

    }//end findByName()
}//end class
