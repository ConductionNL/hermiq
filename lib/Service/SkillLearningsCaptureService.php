<?php

/**
 * Hermiq SkillLearningsCaptureService.
 *
 * The post-run learnings CAPTURE half of ADR-068 §3 (skill-learnings): after a run
 * whose `skillsUsed` is non-empty, one cheap LLM pass per exercised skill (through the
 * governed `ProviderFactory` chokepoint) extracts dated atomic observations from the
 * persisted run trace and appends them — service-serialized in a fixed machine-parseable
 * grammar, never LLM-written — to the skill's `files` entry `learning-candidates.md`.
 *
 * Hard contracts (spec: openspec/specs/skill-learnings/spec.md):
 *   - Utilization-gated: driven exclusively by the run's recorded `skillsUsed`.
 *   - Idempotent per run ID: a candidates file already carrying the run id skips the
 *     skill entirely (no LLM call, no write).
 *   - Budget-gated AND budget-counted: `BudgetService::isBlocked()` is checked before
 *     any LLM call, and the pass's token usage is recorded through the same
 *     `action='run'` audit-entry channel `BudgetService` aggregates for runs.
 *   - Redaction-inherited: every observation passes `RedactionService::redact()` before
 *     persist; an observation that redacts to empty is dropped; when nothing survives,
 *     nothing is written at all.
 *   - Failure-isolated: every error is caught and logged; one skill's failure never
 *     blocks the next; nothing here can fail, delay, or alter the run.
 *   - Append-only surface: only `learning-candidates.md` is ever touched — never
 *     `body`, `frontmatter`, or any other `files` entry (prompt-injection containment,
 *     ADR-068 threat model). Deliberately NOT exposed as an MCP tool or HTTP endpoint.
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
 * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Post-run, best-effort learnings capture into `learning-candidates.md` (skill-learnings).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Coordinates the run/budget/redaction
 *   substrate services the spec names explicitly (ObjectService, AuditTrailMapper,
 *   ProviderFactory, BudgetService, RedactionService).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class complexity is the sum of
 *   many small defensive guards over LLM-produced / JSON-round-tripped input plus the
 *   normative grammar helpers the promotion service shares — each method stays simple.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Length is dominated by the per-method
 *   contract documentation the spec demands; the executable surface is small helpers.
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
 */
class SkillLearningsCaptureService
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
     * Schema slug for Schedule objects (the capture-usage audit entry anchors on the
     * originating run's schedule so `BudgetService::currentUsageTokens()` picks it up).
     *
     * @var string
     */
    private const SCHEDULE_SCHEMA = 'schedule';

    /**
     * The candidates file name inside the skill's agentskills.io `files` map.
     *
     * @var string
     */
    public const CANDIDATES_FILE = 'learning-candidates.md';

    /**
     * The valid `learnings.md` target sections a candidate may be tagged with.
     *
     * @var array<int, string>
     */
    public const SECTIONS = ['patterns', 'mistakes', 'domain', 'questions'];

    /**
     * Candidate line grammar (design.md skill-learnings):
     * `- [date] {section} text <!-- runs: id1,id2 | eval-fail: ref -->`.
     *
     * The grammar is normative-by-test — pinned in the capture AND promotion unit
     * tests; the promotion pass parses it purely mechanically.
     *
     * @var string
     */
    public const LINE_PATTERN = '/^- \[(\d{4}-\d{2}-\d{2})\] \{(patterns|mistakes|domain|questions)\} (.+?) '
        .'<!-- runs: ([0-9A-Za-z,\-]+)(?: \| eval-fail: (\S+))? -->$/';

    /**
     * Observation length cap (characters) — atomic statements, never raw conversation
     * transcript (service-owned constant, design.md Decision 1/5).
     *
     * @var int
     */
    private const MAX_OBSERVATION_CHARS = 240;

    /**
     * Maximum NEW observations accepted from one capture pass (noise bound; the
     * two-stage promotion is the quality filter, this only bounds volume).
     *
     * @var int
     */
    private const MAX_OBSERVATIONS_PER_PASS = 5;

    /**
     * Run-trace excerpt cap (characters) fed to the ONE cheap extraction call.
     *
     * @var int
     */
    private const MAX_TRACE_CHARS = 8000;

    /**
     * Heuristic characters-per-token divisor for the usage estimate:
     * `ProviderFactory::generateText()` returns text only (no provider usage bucket),
     * so the recorded capture cost is a conservative 4-chars-per-token estimate —
     * still flowing through the SAME budget window as real run usage.
     *
     * @var int
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Memoized per-call budget verdict (one `isBlocked()` read per capture job run,
     * not one per skill). Null = not yet checked in this pass.
     *
     * @var boolean|null
     */
    private ?bool $budgetBlocked = null;

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OpenRegister object read/write (single write-path,
     *                                           hash-chain audited — no new write channel).
     * @param AuditTrailMapper $auditTrailMapper Reads the run's persisted trace entry; writes the
     *                                           capture-usage accounting entry (`action='run'`).
     * @param ProviderFactory  $providerFactory  The governed LLM chokepoint for the ONE cheap
     *                                           extraction call per exercised skill.
     * @param BudgetService    $budgetService    The same budget authority that gates runs.
     * @param RedactionService $redactionService The agent-memory redaction path, applied verbatim
     *                                           to every observation BEFORE persist.
     * @param LoggerInterface  $logger           PSR-3 logger (every failure is logged, never thrown).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ProviderFactory $providerFactory,
        private readonly BudgetService $budgetService,
        private readonly RedactionService $redactionService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Capture learnings candidates for one completed run — the whole pass is
     * best-effort: every failure is caught and logged, and a failure on one skill
     * never prevents capture for the run's other exercised skills.
     *
     * Argument shape (the queued job's payload): `runId` (the run's own identifier,
     * recorded on the audit entry), `scheduleUuid` (trace + accounting anchor),
     * `agentId`, `skillIds` (the run's `skillsUsed`), `organisation` (budget scope)
     * and optional `evalFail` (`<evalRunUuid>#<caseId>` when the captured run is a
     * failing eval-case run — design.md Decision 8 marker contract).
     *
     * @param array<string, mixed> $args The capture job payload.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The linear defensive-validation
     *   ladder over the JSON-round-tripped job payload; each branch is a trivial guard.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
     * @spec openspec/specs/skill-learnings/spec.md#requirement-the-engine-records-which-skills-were-exercised-in-a-run
     */
    public function captureForRun(array $args): void
    {
        $this->budgetBlocked = null;

        $runId    = trim((string) ($args['runId'] ?? ''));
        $skillIds = ($args['skillIds'] ?? []);
        if ($runId === '' || is_array($skillIds) === false || $skillIds === []) {
            // No run identity or no exercised skills — nothing to capture (a run that
            // injected no skills records none and gets no capture, by contract).
            return;
        }

        $scheduleUuid = trim((string) ($args['scheduleUuid'] ?? ''));
        $agentId      = trim((string) ($args['agentId'] ?? ''));
        $organisation = trim((string) ($args['organisation'] ?? ''));
        $evalFail     = trim((string) ($args['evalFail'] ?? ''));

        $trace = $this->loadRunTrace(scheduleUuid: $scheduleUuid, runId: $runId);
        if ($trace === '') {
            $this->logger->info(
                sprintf('Hermiq learnings capture: no persisted trace for run %s — nothing to extract.', $runId)
            );
            return;
        }

        $promptTokens     = 0;
        $completionTokens = 0;

        foreach ($skillIds as $skillId) {
            if (is_string($skillId) === false || trim($skillId) === '') {
                continue;
            }

            try {
                $usage = $this->captureForSkill(
                    skillId: trim($skillId),
                    runId: $runId,
                    trace: $trace,
                    agentId: $agentId,
                    organisation: $organisation,
                    evalFail: $evalFail
                );

                $promptTokens     += $usage['promptTokens'];
                $completionTokens += $usage['completionTokens'];
            } catch (Throwable $e) {
                // Per-skill isolation: one bad skill never starves the others.
                $this->logger->warning(
                    sprintf('Hermiq learnings capture failed for skill %s (run %s): %s', (string) $skillId, $runId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }//end foreach

        if (($promptTokens + $completionTokens) > 0) {
            $this->recordCaptureUsage(
                scheduleUuid: $scheduleUuid,
                agentId: $agentId,
                runId: $runId,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens
            );
        }

    }//end captureForRun()

    /**
     * Capture candidates for ONE exercised skill: idempotency first (no LLM call when
     * the candidates file already records this run id), then the budget gate, then the
     * single extraction call, redaction, and the mechanical grammar append/update.
     *
     * @param string $skillId      The exercised skill's uuid.
     * @param string $runId        The originating run's id.
     * @param string $trace        The persisted run-trace excerpt.
     * @param string $agentId      The run's agent uuid (budget scope).
     * @param string $organisation The run's organisation (budget + model-policy scope).
     * @param string $evalFail     Optional failed-eval marker ref (`''` = none).
     *
     * @return array{promptTokens: int, completionTokens: int} The pass's estimated usage.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  One linear pipeline — load, gate,
     *   extract, sanitize, serialize, stamp — whose steps the spec orders explicitly
     *   ((a)–(h) in tasks.md); splitting it would scatter the ordered contract.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Same reasoning: sequential guard
     *   returns, not combinatorial branching.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Same reasoning: the ordered
     *   (a)–(h) contract reads best as one auditable sequence.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-idempotent-per-run-id
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-budget-gated-and-budget-counted
     */
    private function captureForSkill(
        string $skillId,
        string $runId,
        string $trace,
        string $agentId,
        string $organisation,
        string $evalFail
    ): array {
        $none = [
            'promptTokens'     => 0,
            'completionTokens' => 0,
        ];

        $skill = $this->objectService->find(
            id: $skillId,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );
        if ($skill === null) {
            $this->logger->info(sprintf('Hermiq learnings capture: skill %s not found — skipped.', $skillId));
            return $none;
        }

        $data  = $skill->getObject();
        $files = ($data['files'] ?? []);
        if (is_array($files) === false) {
            $files = [];
        }

        $candidatesContent = $this->fileContent(files: $files, name: self::CANDIDATES_FILE);

        // (a) IDEMPOTENCY FIRST: a re-delivered/double-enqueued job costs nothing and
        // duplicates nothing — checked BEFORE the LLM call, per the spec.
        if ($this->contentContainsRunId(content: $candidatesContent, runId: $runId) === true) {
            $this->logger->info(
                sprintf('Hermiq learnings capture: run %s already recorded on skill %s — idempotent skip.', $runId, $skillId)
            );
            return $none;
        }

        // (b) BUDGET GATE: a run that exhausted the budget gets no capture pass.
        if ($this->isBudgetBlocked(organisation: $organisation, agentId: $agentId) === true) {
            $this->logger->info(
                sprintf('Hermiq learnings capture: budget blocked for org "%s" — skipping skill %s (no LLM call).', $organisation, $skillId)
            );
            return $none;
        }

        $parsedCandidates = $this->parseCandidates(content: $candidatesContent);

        // Tenant-model-policy scope: an organisation-less run opts out of the
        // per-organisation policy check (mirrors EvalScoringService's judge call).
        $policyScope = $organisation;
        if ($policyScope === '') {
            $policyScope = null;
        }

        // (c) ONE cheap extraction call per exercised skill via the governed chokepoint.
        $prompt   = $this->buildExtractionPrompt(data: $data, candidates: $parsedCandidates, trace: $trace);
        $response = $this->providerFactory->generateText(
            prompt: $prompt,
            userId: null,
            allowNextcloud: true,
            organisation: $policyScope
        );

        $usage = [
            'promptTokens'     => (int) ceil(strlen($prompt) / self::CHARS_PER_TOKEN),
            'completionTokens' => (int) ceil(strlen($response) / self::CHARS_PER_TOKEN),
        ];

        $extraction = $this->parseExtraction(response: $response);

        // (d)+(e) Redact every observation, drop empties, cap length. The LLM never
        // writes the file — the service serializes every line itself.
        $observations = $this->sanitizeObservations(raw: $extraction['observations']);

        $confirmations = $this->validConfirmationIndexes(
            raw: $extraction['confirmations'],
            candidateCount: count($parsedCandidates)
        );

        if ($observations === [] && $confirmations === []) {
            // Redaction-empty (or nothing extracted) → nothing is written at all: no
            // empty lines, no l6 activity stamp.
            $this->logger->info(
                sprintf('Hermiq learnings capture: no persistable observation for skill %s (run %s) — no write.', $skillId, $runId)
            );
            return $usage;
        }

        $today = $this->today();

        // (f) Confirmations extend the existing line's run-id list + refresh its date;
        // never a duplicate line.
        foreach ($confirmations as $index) {
            $candidate = $parsedCandidates[$index];
            if (in_array($runId, $candidate['runs'], true) === false) {
                $candidate['runs'][] = $runId;
            }

            $candidate['date'] = $today;

            // (g) A failing eval-case run marks every candidate it touched.
            if ($evalFail !== '' && $candidate['evalFail'] === '') {
                $candidate['evalFail'] = $evalFail;
            }

            $parsedCandidates[$index] = $candidate;
        }

        foreach ($observations as $observation) {
            $parsedCandidates[] = [
                'date'     => $today,
                'section'  => $observation['section'],
                'text'     => $observation['text'],
                'runs'     => [$runId],
                'evalFail' => $evalFail,
                'raw'      => null,
            ];
        }

        $newContent = $this->serializeCandidates(candidates: $parsedCandidates);

        // (h) Persist through the unchanged ObjectService write path and stamp the l6
        // activity — the ONLY fields this subsystem touches besides the candidates
        // file. `body`/`frontmatter`/all other files entries stay byte-identical
        // because only the CANDIDATES_FILE entry is replaced in the carried-forward
        // payload (OR saveObject is PUT-semantic — the full object is written back).
        $data['files'] = $this->withFileContent(files: $files, name: self::CANDIDATES_FILE, content: $newContent);

        $data['levelEvidence'] = $this->stampL6(
            data: $data,
            fields: [
                'candidateCount' => $this->countCandidateLines(content: $newContent),
                'lastCaptureAt'  => $this->nowIso(),
            ]
        );

        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: (string) $skill->getUuid(),
            _rbac: false,
            _multitenancy: false
        );

        return $usage;

    }//end captureForSkill()

    /**
     * Parse a candidates file into structured candidate entries. Unparseable lines are
     * preserved verbatim (`raw` set) so a legacy/hand-edited line is never destroyed by
     * a capture write — it simply ages out via the promotion pass's expiry rule.
     *
     * @param string $content The `learning-candidates.md` content ('' = absent).
     *
     * @return array<int, array{date: string, section: string, text: string,
     *               runs: array<int, string>, evalFail: string, raw: string|null}>
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    public function parseCandidates(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $candidates = [];
        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parsed = self::parseCandidateLine(line: $line);
            if ($parsed === null) {
                $candidates[] = [
                    'date'     => '',
                    'section'  => '',
                    'text'     => '',
                    'runs'     => [],
                    'evalFail' => '',
                    'raw'      => $line,
                ];
                continue;
            }

            $candidates[] = $parsed;
        }

        return $candidates;

    }//end parseCandidates()

    /**
     * Parse ONE candidate line against the normative grammar.
     *
     * @param string $line The candidate line.
     *
     * @return array{date: string, section: string, text: string,
     *               runs: array<int, string>, evalFail: string, raw: null}|null
     *         The parsed candidate, or null when the line does not match the grammar.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    public static function parseCandidateLine(string $line): ?array
    {
        if (preg_match(self::LINE_PATTERN, rtrim($line), $matches) !== 1) {
            return null;
        }

        $runs = array_values(
            array_filter(
                array_map('trim', explode(',', $matches[4])),
                static fn (string $id): bool => $id !== ''
            )
        );

        return [
            'date'     => $matches[1],
            'section'  => $matches[2],
            'text'     => $matches[3],
            'runs'     => $runs,
            'evalFail' => (string) ($matches[5] ?? ''),
            'raw'      => null,
        ];

    }//end parseCandidateLine()

    /**
     * Serialize ONE candidate entry back into the normative grammar (or return the
     * preserved raw line verbatim for an unparseable entry).
     *
     * @param array<string, mixed> $candidate The candidate entry
     *                                        ({date, section, text, runs, evalFail, raw}).
     *
     * @return string The serialized line.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    public static function serializeCandidateLine(array $candidate): string
    {
        if (($candidate['raw'] ?? null) !== null) {
            return (string) $candidate['raw'];
        }

        $marker = 'runs: '.implode(',', $candidate['runs']);
        if (($candidate['evalFail'] ?? '') !== '') {
            $marker .= ' | eval-fail: '.$candidate['evalFail'];
        }

        return sprintf('- [%s] {%s} %s <!-- %s -->', $candidate['date'], $candidate['section'], $candidate['text'], $marker);

    }//end serializeCandidateLine()

    /**
     * Count the grammar-parseable candidate lines in a candidates file — the value
     * stamped into `levelEvidence.l6.candidateCount` (derived from the actual parsed
     * file contents at write time, per the spec).
     *
     * @param string $content The `learning-candidates.md` content.
     *
     * @return int The parseable candidate-line count.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-levelevidence-l6-activity-is-written-by-the-learnings-subsystem-only
     */
    public function countCandidateLines(string $content): int
    {
        $count = 0;
        foreach ($this->parseCandidates(content: $content) as $candidate) {
            if ($candidate['raw'] === null) {
                $count++;
            }
        }

        return $count;

    }//end countCandidateLines()

    /**
     * Whether a candidates file already records the given run id in ANY candidate's
     * run-id marker (the per-run-ID idempotency check — done before the LLM call).
     *
     * @param string $content The `learning-candidates.md` content.
     * @param string $runId   The run id to look for.
     *
     * @return bool True when the run id is already recorded.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-idempotent-per-run-id
     */
    public function contentContainsRunId(string $content, string $runId): bool
    {
        foreach ($this->parseCandidates(content: $content) as $candidate) {
            if (in_array($runId, $candidate['runs'], true) === true) {
                return true;
            }
        }

        return false;

    }//end contentContainsRunId()

    /**
     * Serialize the full candidate list back to file content (one line per candidate,
     * trailing newline).
     *
     * @param array<int, array<string, mixed>> $candidates The candidate entries
     *                                                     (parseCandidates() shape).
     *
     * @return string The serialized `learning-candidates.md` content.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    public function serializeCandidates(array $candidates): string
    {
        $lines = [];
        foreach ($candidates as $candidate) {
            $lines[] = self::serializeCandidateLine(candidate: $candidate);
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines)."\n";

    }//end serializeCandidates()

    /**
     * Redact, trim, flatten, cap, and section-validate the LLM's raw observations —
     * the "LLM proposes, service disposes" step. An observation that redacts to empty
     * is dropped; grammar-breaking token sequences are stripped so extracted text can
     * never smuggle a fake marker into the candidates file.
     *
     * @param mixed $raw The `observations` value from the parsed LLM response.
     *
     * @return array<int, array{section: string, text: string}> The persistable observations.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-learnings-writes-inherit-the-agent-memory-redaction-path-and-tool-governance
     */
    private function sanitizeObservations(mixed $raw): array
    {
        if (is_array($raw) === false) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (count($out) >= self::MAX_OBSERVATIONS_PER_PASS) {
                break;
            }

            if (is_array($entry) === false) {
                continue;
            }

            $section = strtolower(trim((string) ($entry['section'] ?? '')));
            if (in_array($section, self::SECTIONS, true) === false) {
                continue;
            }

            // REDACTION-BEFORE-PERSIST (agent-memory path, applied verbatim): no
            // secrets, no personal data may enter the candidates file.
            $text = $this->redactionService->redact((string) ($entry['text'] ?? ''));

            // Atomic one-line statements only; strip grammar/HTML-comment tokens so a
            // prompt-injected observation cannot forge run/eval markers.
            $text = str_replace(['<!--', '-->'], '', $text);
            $text = trim((string) preg_replace('/\s+/', ' ', $text));

            if ($text === '') {
                // Redaction-empty → dropped.
                continue;
            }

            if (mb_strlen($text) > self::MAX_OBSERVATION_CHARS) {
                $text = mb_substr($text, 0, self::MAX_OBSERVATION_CHARS);
            }

            $out[] = [
                'section' => $section,
                'text'    => $text,
            ];
        }//end foreach

        return $out;

    }//end sanitizeObservations()

    /**
     * Validate the LLM's confirmation entries into unique, in-range candidate indexes
     * (only grammar-parseable candidates are confirmable).
     *
     * @param mixed $raw            The `confirmations` value from the parsed LLM response.
     * @param int   $candidateCount The current candidate-list length.
     *
     * @return array<int, int> The valid, unique 0-based candidate indexes.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pure defensive validation of an
     *   LLM-produced value — every branch is a shape/range guard on untrusted input.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    private function validConfirmationIndexes(mixed $raw, int $candidateCount): array
    {
        if (is_array($raw) === false) {
            return [];
        }

        $indexes = [];
        foreach ($raw as $entry) {
            $index = null;
            if (is_array($entry) === true && isset($entry['candidateIndex']) === true && is_numeric($entry['candidateIndex']) === true) {
                $index = (int) $entry['candidateIndex'];
            } else if (is_numeric($entry) === true) {
                $index = (int) $entry;
            }

            if ($index === null || $index < 0 || $index >= $candidateCount) {
                continue;
            }

            $indexes[$index] = $index;
        }

        return array_values($indexes);

    }//end validConfirmationIndexes()

    /**
     * Build the single extraction prompt: the skill's identity, the CURRENT candidate
     * list (so the "same observation?" judgement happens at capture time, where an LLM
     * is already paid for), and the persisted run-trace excerpt.
     *
     * @param array<string, mixed>             $data       The skill payload.
     * @param array<int, array<string, mixed>> $candidates The parsed current candidates.
     * @param string                           $trace      The run-trace excerpt.
     *
     * @return string The extraction prompt.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    private function buildExtractionPrompt(array $data, array $candidates, string $trace): string
    {
        $existing = [];
        foreach ($candidates as $index => $candidate) {
            if (($candidate['raw'] ?? null) !== null) {
                continue;
            }

            $existing[] = [
                'candidateIndex' => $index,
                'section'        => $candidate['section'],
                'text'           => $candidate['text'],
            ];
        }

        return "You review one completed AI agent run to extract reusable LEARNINGS for the skill below.\n\n"
            .'SKILL NAME: '.((string) ($data['name'] ?? 'skill'))."\n"
            .'SKILL DESCRIPTION: '.((string) ($data['description'] ?? ''))."\n\n"
            ."EXISTING CANDIDATE OBSERVATIONS (JSON):\n"
            .((string) json_encode($existing, JSON_UNESCAPED_SLASHES))."\n\n"
            ."RUN TRACE:\n".$trace."\n\n"
            .'Respond with ONLY a JSON object of the exact shape '
            .'{"observations": [{"section": "patterns|mistakes|domain|questions", "text": "<atomic statement>"}], '
            .'"confirmations": [{"candidateIndex": <number>}]}. '
            .'Rules: observations are NEW, dated-worthy, atomic facts learned from this run — extraction, never quotation; '
            .'at most '.self::MAX_OBSERVATIONS_PER_PASS.' observations of at most '.self::MAX_OBSERVATION_CHARS.' characters each; '
            .'never include secrets, credentials, or personal data; '
            .'when the run merely re-confirms an existing candidate, list its candidateIndex under confirmations instead of repeating it; '
            .'return {"observations": [], "confirmations": []} when nothing qualifies. Do not include any other text.';

    }//end buildExtractionPrompt()

    /**
     * Parse the LLM's raw response for the `{observations, confirmations}` JSON object.
     * Tolerant of surrounding prose (first `{` .. last `}`), mirroring
     * `EvalScoringService::parseJudgeResponse()`. An unparseable response yields an
     * empty extraction — never an error.
     *
     * @param string $response The raw LLM response text.
     *
     * @return array{observations: mixed, confirmations: mixed} The parsed extraction.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    private function parseExtraction(string $response): array
    {
        $none = [
            'observations'  => [],
            'confirmations' => [],
        ];

        $start = strpos($response, '{');
        $end   = strrpos($response, '}');
        if ($start === false || $end === false || $end <= $start) {
            return $none;
        }

        $decoded = json_decode(substr($response, $start, ($end - $start + 1)), true);
        if (is_array($decoded) === false) {
            return $none;
        }

        return [
            'observations'  => ($decoded['observations'] ?? []),
            'confirmations' => ($decoded['confirmations'] ?? []),
        ];

    }//end parseExtraction()

    /**
     * Load the persisted run trace (redacted summary + step timeline) for the run
     * being captured from its `action='run'` AuditTrail entry — the SAME entries
     * `RunHistoryService` reads, matched by the context's `runId`.
     *
     * @param string $scheduleUuid The schedule the run belongs to.
     * @param string $runId        The run's own identifier.
     *
     * @return string The trace excerpt ('' when unavailable — capture then does nothing).
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-a-post-run-capture-pass-appends-dated-atomic-candidates-per-exercised-skill
     */
    private function loadRunTrace(string $scheduleUuid, string $runId): string
    {
        if ($scheduleUuid === '') {
            return '';
        }

        try {
            $logs = $this->auditTrailMapper->findAll(
                filters: [
                    'object_uuid' => $scheduleUuid,
                    'action'      => 'run',
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq learnings capture could not read run entries for schedule %s: %s', $scheduleUuid, $e->getMessage()),
                ['exception' => $e]
            );
            return '';
        }

        foreach ($logs as $log) {
            $context = ($log->getChanged() ?? []);
            if ((string) ($context['runId'] ?? '') !== $runId) {
                continue;
            }

            $trace = 'STATUS: '.((string) ($context['status'] ?? 'unknown'))."\n"
                .'SUMMARY: '.((string) ($context['summary'] ?? ''))."\n"
                .'STEPS: '.((string) json_encode(($context['steps'] ?? []), JSON_UNESCAPED_SLASHES));

            if (mb_strlen($trace) > self::MAX_TRACE_CHARS) {
                $trace = mb_substr($trace, 0, self::MAX_TRACE_CHARS);
            }

            return $trace;
        }

        return '';

    }//end loadRunTrace()

    /**
     * Record the capture pass's (estimated) token usage through the SAME audit-entry
     * channel `BudgetService::currentUsageTokens()` aggregates for runs: an
     * `action='run'` entry on the originating run's Schedule, tagged
     * `runType: 'skill-capture'` and carrying the originating `runId` — so capture
     * cost counts against the SAME per-org/per-agent period windows with zero
     * BudgetService changes (design.md Decision 4). Non-fatal by contract.
     *
     * @param string $scheduleUuid     The originating run's schedule uuid.
     * @param string $agentId          The run's agent uuid.
     * @param string $runId            The originating run's id.
     * @param int    $promptTokens     Estimated prompt tokens across the pass.
     * @param int    $completionTokens Estimated completion tokens across the pass.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-budget-gated-and-budget-counted
     */
    private function recordCaptureUsage(
        string $scheduleUuid,
        string $agentId,
        string $runId,
        int $promptTokens,
        int $completionTokens
    ): void {
        try {
            if ($scheduleUuid === '') {
                return;
            }

            $schedule = $this->objectService->find(
                id: $scheduleUuid,
                register: self::REGISTER_SLUG,
                schema: self::SCHEDULE_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
            if ($schedule === null) {
                $this->logger->info(
                    sprintf('Hermiq learnings capture: schedule %s gone — capture usage for run %s not recorded.', $scheduleUuid, $runId)
                );
                return;
            }

            $this->auditTrailMapper->createAuditTrailEntry(
                object: $schedule,
                action: 'run',
                context: [
                    'status'  => 'skill_capture',
                    'runType' => 'skill-capture',
                    'runId'   => $runId,
                    'agentId' => $agentId,
                    'dryRun'  => false,
                    'usage'   => [
                        'promptTokens'     => $promptTokens,
                        'completionTokens' => $completionTokens,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq learnings capture could not record usage for run %s: %s', $runId, $e->getMessage()),
                ['exception' => $e]
            );
        }//end try

    }//end recordCaptureUsage()

    /**
     * The memoized budget verdict for this capture pass (one system-wide read per
     * job execution, not one per skill).
     *
     * @param string $organisation The run's organisation.
     * @param string $agentId      The run's agent uuid.
     *
     * @return bool True when the scope is budget-blocked (no capture LLM call).
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-budget-gated-and-budget-counted
     */
    private function isBudgetBlocked(string $organisation, string $agentId): bool
    {
        if ($this->budgetBlocked === null) {
            $agentScope = $agentId;
            if ($agentScope === '') {
                $agentScope = null;
            }

            $this->budgetBlocked = $this->budgetService->isBlocked(organisation: $organisation, agentId: $agentScope);
        }

        return $this->budgetBlocked;

    }//end isBudgetBlocked()

    /**
     * Read one named entry's content from the skill's `files` array.
     *
     * @param array<int, mixed> $files The skill's `files` array ({name, content} entries).
     * @param string            $name  The entry name.
     *
     * @return string The entry content ('' when absent).
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-learnings-files-live-in-the-files-map-and-travel-with-the-export
     */
    public function fileContent(array $files, string $name): string
    {
        foreach ($files as $file) {
            if (is_array($file) === true && (string) ($file['name'] ?? '') === $name) {
                return (string) ($file['content'] ?? '');
            }
        }

        return '';

    }//end fileContent()

    /**
     * Return the `files` array with one named entry replaced (or appended when
     * absent). Every OTHER entry is passed through untouched — the append-only
     * containment guarantee.
     *
     * @param array<int, mixed> $files   The skill's `files` array.
     * @param string            $name    The entry name to set.
     * @param string            $content The new entry content.
     *
     * @return array<int, mixed> The updated `files` array.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-learnings-files-live-in-the-files-map-and-travel-with-the-export
     */
    public function withFileContent(array $files, string $name, string $content): array
    {
        $replaced = false;
        foreach ($files as $index => $file) {
            if (is_array($file) === true && (string) ($file['name'] ?? '') === $name) {
                $files[$index]['content'] = $content;
                $replaced = true;
                break;
            }
        }

        if ($replaced === false) {
            $files[] = [
                'name'    => $name,
                'content' => $content,
            ];
        }

        return array_values($files);

    }//end withFileContent()

    /**
     * Merge the given l6 activity fields into the skill's stored `levelEvidence`,
     * preserving every other level entry and every OTHER l6 key (notably
     * `lastConsolidatedAt`, which this subsystem never writes).
     *
     * @param array<string, mixed> $data   The skill payload.
     * @param array<string, mixed> $fields The l6 fields to stamp.
     *
     * @return array<string, mixed> The updated `levelEvidence` map.
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-levelevidence-l6-activity-is-written-by-the-learnings-subsystem-only
     */
    public function stampL6(array $data, array $fields): array
    {
        $evidence = ($data['levelEvidence'] ?? []);
        if (is_array($evidence) === false) {
            $evidence = [];
        }

        $levelSix = ($evidence['l6'] ?? []);
        if (is_array($levelSix) === false) {
            $levelSix = [];
        }

        foreach ($fields as $key => $value) {
            $levelSix[$key] = $value;
        }

        $evidence['l6'] = $levelSix;

        return $evidence;

    }//end stampL6()

    /**
     * Today's date (UTC) in the grammar's `[YYYY-MM-DD]` form.
     *
     * @return string The date.
     *
     * @spec exclude Trivial clock accessor; behaviour covered via the grammar tests.
     */
    protected function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

    }//end today()

    /**
     * The current UTC moment in ISO-8601 (for `lastCaptureAt`).
     *
     * @return string The timestamp.
     *
     * @spec exclude Trivial clock accessor; behaviour covered via the l6-stamp tests.
     */
    protected function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    }//end nowIso()
}//end class
