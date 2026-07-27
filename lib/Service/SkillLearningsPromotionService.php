<?php

/**
 * Hermiq SkillLearningsPromotionService.
 *
 * The mechanical PROMOTION half of ADR-068 §3 (skill-learnings): a daily pass that,
 * WITHOUT any LLM call, parses each skill's `learning-candidates.md` grammar and
 *   - promotes every candidate confirmed by 3+ DISTINCT run ids into the five-section
 *     `files['learnings.md']` under its tagged section,
 *   - promotes every candidate carrying an `eval-fail:` marker regardless of
 *     confirmation count ("explains a failed eval case"),
 *   - drops every candidate untouched for 30 days,
 * removes promoted lines from the candidates file, and stamps the skill's
 * `levelEvidence.l6` activity ({candidateCount, learningsCount, lastPromotedAt} —
 * NEVER `lastConsolidatedAt`, which is reserved for `skill-self-improvement`
 * consolidation; the L6 pass rule is deliberately unchanged, so promotion alone never
 * grants L6). Thresholds are service-owned constants (design.md Decision 1); the
 * two-stage RULE is normative. The Consolidated Principles section exists in the file
 * shape from day one but is never written here.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily mechanical two-stage learnings promotion (no LLM — pure line parsing).
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
 */
class SkillLearningsPromotionService
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
     * The learnings file name inside the skill's agentskills.io `files` map.
     *
     * @var string
     */
    public const LEARNINGS_FILE = 'learnings.md';

    /**
     * Distinct confirming run ids required for promotion (service-owned constant —
     * the spec fixes the RULE, the service owns the number; design.md Decision 1).
     *
     * @var int
     */
    public const CONFIRMATION_THRESHOLD = 3;

    /**
     * Days after which an untouched candidate is dropped (service-owned constant).
     *
     * @var int
     */
    public const EXPIRY_DAYS = 30;

    /**
     * The five fixed `learnings.md` sections, keyed by the candidate grammar's
     * section tag. `Consolidated Principles` has NO tag — it is never written by
     * this change (reserved for skill-self-improvement consolidation).
     *
     * @var array<string, string>
     */
    public const SECTION_HEADINGS = [
        'patterns'  => 'Patterns That Work',
        'mistakes'  => 'Mistakes to Avoid',
        'domain'    => 'Domain Knowledge',
        'questions' => 'Open Questions',
    ];

    /**
     * The reserved fifth section heading (present in the file shape, never written here).
     *
     * @var string
     */
    public const CONSOLIDATED_HEADING = 'Consolidated Principles';

    /**
     * Constructor.
     *
     * @param ObjectService                $objectService  OpenRegister object read/write (single
     *                                                     write-path, hash-chain audited).
     * @param SkillLearningsCaptureService $captureService Owns the normative candidate grammar
     *                                                     (parse/serialize/count helpers) so the
     *                                                     grammar has exactly ONE definition.
     * @param LoggerInterface              $logger         PSR-3 logger (per-skill failures logged,
     *                                                     never fatal to the pass).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SkillLearningsCaptureService $captureService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Run one promotion pass over every skill (system context — the daily TimedJob
     * seam). Per-skill isolation: one bad skill never blocks the rest.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function promoteAll(): void
    {
        try {
            $skills = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::SKILL_SCHEMA)
                ->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq learnings promotion could not load skills: '.$e->getMessage(),
                ['exception' => $e]
            );
            return;
        }

        foreach ($skills as $skill) {
            if (($skill instanceof ObjectEntity) === false) {
                continue;
            }

            try {
                $this->promoteSkill(skill: $skill);
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('Hermiq learnings promotion failed for skill %s: %s', (string) $skill->getUuid(), $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }

    }//end promoteAll()

    /**
     * Promote/expire ONE skill's candidates — purely mechanical grammar parsing:
     * count distinct run ids, check the eval-fail marker, compare dates. Persists
     * only when the pass actually changed something (no write churn on a quiet
     * catalog). Never touches `body`, `frontmatter`, other `files` entries, or the
     * Consolidated Principles section.
     *
     * @param ObjectEntity $skill The Skill object.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  One linear promote/expire/keep
     *   fold over the candidate list — the spec's three mechanical rules in order;
     *   splitting it would scatter the normative sequence.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Same reasoning: sequential guard
     *   branches per candidate, not combinatorial paths.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Same reasoning: parse → fold →
     *   write is one auditable sequence.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     * @spec openspec/specs/skill-learnings/spec.md#requirement-levelevidencel6-activity-is-written-by-the-learnings-subsystem-only
     */
    public function promoteSkill(ObjectEntity $skill): void
    {
        $data  = $skill->getObject();
        $files = ($data['files'] ?? []);
        if (is_array($files) === false) {
            $files = [];
        }

        $candidatesContent = $this->captureService->fileContent(
            files: $files,
            name: SkillLearningsCaptureService::CANDIDATES_FILE
        );
        if (trim($candidatesContent) === '') {
            // Nothing captured yet — nothing to promote or expire.
            return;
        }

        $candidates = $this->captureService->parseCandidates(content: $candidatesContent);

        $kept     = [];
        $promoted = [];
        $dropped  = 0;

        foreach ($candidates as $candidate) {
            if ($candidate['raw'] !== null) {
                // Unparseable/legacy line: never destroyed by promotion, but it ages
                // out via the same expiry rule when a loose date is extractable.
                if ($this->rawLineExpired(line: (string) $candidate['raw']) === true) {
                    $dropped++;
                    continue;
                }

                $kept[] = $candidate;
                continue;
            }

            $distinctRuns = count(array_unique($candidate['runs']));

            if ($distinctRuns >= self::CONFIRMATION_THRESHOLD || $candidate['evalFail'] !== '') {
                $promoted[] = $candidate;
                continue;
            }

            if ($this->isExpired(date: $candidate['date']) === true) {
                $dropped++;
                continue;
            }

            $kept[] = $candidate;
        }//end foreach

        if ($promoted === [] && $dropped === 0) {
            // Byte-identical outcome — skip the write entirely.
            return;
        }

        $learningsContent = $this->captureService->fileContent(files: $files, name: self::LEARNINGS_FILE);
        if ($promoted !== []) {
            $learningsContent = $this->appendToLearnings(content: $learningsContent, promoted: $promoted);
            $files            = $this->captureService->withFileContent(
                files: $files,
                name: self::LEARNINGS_FILE,
                content: $learningsContent
            );
        }

        $newCandidatesContent = $this->captureService->serializeCandidates(candidates: $kept);
        $files = $this->captureService->withFileContent(
            files: $files,
            name: SkillLearningsCaptureService::CANDIDATES_FILE,
            content: $newCandidatesContent
        );

        $data['files'] = $files;

        // Stamp the l6 activity from the actual parsed file contents at write time.
        // `lastPromotedAt` only moves when something actually promoted; every other
        // l6 key (notably lastConsolidatedAt) is carried forward untouched.
        $stamp = [
            'candidateCount' => $this->captureService->countCandidateLines(content: $newCandidatesContent),
            'learningsCount' => $this->countLearningsEntries(content: $learningsContent),
        ];
        if ($promoted !== []) {
            $stamp['lastPromotedAt'] = $this->nowIso();
        }

        $data['levelEvidence'] = $this->captureService->stampL6(data: $data, fields: $stamp);

        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: (string) $skill->getUuid(),
            _rbac: false,
            _multitenancy: false
        );

        $this->logger->info(
            sprintf(
                'Hermiq learnings promotion for skill %s: %d promoted, %d expired, %d kept.',
                (string) $skill->getUuid(),
                count($promoted),
                $dropped,
                count($kept)
            )
        );

    }//end promoteSkill()

    /**
     * Append promoted candidates to `learnings.md` under their tagged sections,
     * creating the five-section scaffold when the file is absent. The Consolidated
     * Principles section is preserved verbatim — never written here.
     *
     * @param string                           $content  The current `learnings.md` content ('' = absent).
     * @param array<int, array<string, mixed>> $promoted The promoted candidates.
     *
     * @return string The updated `learnings.md` content.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function appendToLearnings(string $content, array $promoted): string
    {
        if (trim($content) === '') {
            $content = $this->emptyLearningsScaffold();
        }

        $lines = explode("\n", rtrim($content, "\n"));

        foreach ($promoted as $candidate) {
            $heading = (self::SECTION_HEADINGS[(string) $candidate['section']] ?? '');
            if ($heading === '') {
                // Defensive: an unknown tag never lands anywhere near Consolidated
                // Principles — it is simply not promoted into the file.
                continue;
            }

            $provenance = 'promoted '.$this->today().' | runs: '.implode(',', array_unique($candidate['runs']));
            if ($candidate['evalFail'] !== '') {
                $provenance .= ' | eval-fail: '.$candidate['evalFail'];
            }

            $entry = '- '.$candidate['text'].' <!-- '.$provenance.' -->';
            $lines = $this->insertUnderHeading(lines: $lines, heading: '## '.$heading, entry: $entry);
        }

        return implode("\n", $lines)."\n";

    }//end appendToLearnings()

    /**
     * The five fixed sections of a fresh `learnings.md` — Consolidated Principles is
     * present (stable file shape from day one) but empty.
     *
     * @return string The scaffold content.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    public function emptyLearningsScaffold(): string
    {
        $lines = ['# Learnings'];
        foreach (self::SECTION_HEADINGS as $heading) {
            $lines[] = '';
            $lines[] = '## '.$heading;
        }

        $lines[] = '';
        $lines[] = '## '.self::CONSOLIDATED_HEADING;

        return implode("\n", $lines)."\n";

    }//end emptyLearningsScaffold()

    /**
     * Count the promoted-learning entries (`- ` bullet lines) in a `learnings.md` —
     * the value stamped into `levelEvidence.l6.learningsCount` (derived from the
     * actual parsed file contents at write time).
     *
     * @param string $content The `learnings.md` content.
     *
     * @return int The entry count.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-levelevidencel6-activity-is-written-by-the-learnings-subsystem-only
     */
    public function countLearningsEntries(string $content): int
    {
        $count = 0;
        foreach (explode("\n", $content) as $line) {
            if (str_starts_with(trim($line), '- ') === true) {
                $count++;
            }
        }

        return $count;

    }//end countLearningsEntries()

    /**
     * Insert an entry directly under a section heading (after any existing entries of
     * that section), appending the heading at the end when the file lacks it.
     *
     * @param array<int, string> $lines   The file lines.
     * @param string             $heading The exact heading line (e.g. `## Domain Knowledge`).
     * @param string             $entry   The entry line to insert.
     *
     * @return array<int, string> The updated lines.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    private function insertUnderHeading(array $lines, string $heading, string $entry): array
    {
        $headingIndex = null;
        foreach ($lines as $index => $line) {
            if (trim($line) === $heading) {
                $headingIndex = $index;
                break;
            }
        }

        if ($headingIndex === null) {
            // A hand-edited file missing the section: append the heading + entry at
            // the END of the file, never inside another section.
            $lines[] = '';
            $lines[] = $heading;
            $lines[] = $entry;
            return $lines;
        }

        // Walk forward to the end of this section (the next `## ` heading or EOF).
        $insertAt = count($lines);
        $total    = count($lines);
        for ($i = ($headingIndex + 1); $i < $total; $i++) {
            if (str_starts_with(trim($lines[$i]), '## ') === true) {
                $insertAt = $i;
                break;
            }
        }

        // Back up over trailing blank lines so entries stay grouped under the heading.
        while ($insertAt > ($headingIndex + 1) && trim($lines[($insertAt - 1)]) === '') {
            $insertAt--;
        }

        array_splice($lines, $insertAt, 0, [$entry]);

        return $lines;

    }//end insertUnderHeading()

    /**
     * Whether a candidate's last-touched date is beyond the expiry window.
     *
     * @param string $date The candidate's `[YYYY-MM-DD]` date.
     *
     * @return bool True when the candidate is stale.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromFormat() is
     *   PHP's own named constructor for strict date parsing — no injectable seam exists.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    private function isExpired(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false) {
            return false;
        }

        $cutoff = $this->now()->modify(sprintf('-%d days', self::EXPIRY_DAYS));

        return ($parsed < $cutoff);

    }//end isExpired()

    /**
     * Whether an unparseable (legacy/hand-edited) line carries a loosely extractable
     * `[YYYY-MM-DD]` date beyond the expiry window — the aging path for lines the
     * grammar cannot fully parse. A dateless line is kept (only manual edits produce
     * one; destroying it mechanically would be data loss).
     *
     * @param string $line The raw line.
     *
     * @return bool True when the line is stale.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-promotion-is-a-mechanical-two-stage-background-pass
     */
    private function rawLineExpired(string $line): bool
    {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2})\]/', $line, $matches) !== 1) {
            return false;
        }

        return $this->isExpired(date: $matches[1]);

    }//end rawLineExpired()

    /**
     * The current UTC moment (overridable seam for the expiry tests).
     *
     * @return DateTimeImmutable The current UTC time.
     *
     * @spec exclude Trivial clock accessor; expiry behaviour covered via the promotion tests.
     */
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));

    }//end now()

    /**
     * Today's UTC date (`YYYY-MM-DD`) for promotion provenance markers.
     *
     * @return string The date.
     *
     * @spec exclude Trivial clock accessor; behaviour covered via the promotion tests.
     */
    protected function today(): string
    {
        return $this->now()->format('Y-m-d');

    }//end today()

    /**
     * The current UTC moment in ISO-8601 (for `lastPromotedAt`).
     *
     * @return string The timestamp.
     *
     * @spec exclude Trivial clock accessor; behaviour covered via the promotion tests.
     */
    protected function nowIso(): string
    {
        return $this->now()->format('c');

    }//end nowIso()
}//end class
