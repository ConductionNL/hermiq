<?php

/**
 * Hermiq SkillMaturityService.
 *
 * Ports the fleet's L1–L7 skill maturity model (ADR-068) onto `agentskill` objects as a
 * qualification tool. Levels 1–3 are computed MECHANICALLY from the skill's own content
 * (`frontmatter`/`body`/`files` — rule-based content analysis, the justified imperative
 * exception of ADR-031); L4 is read from the human attestation stamped by the action-gated
 * attest endpoint and is NEVER auto-detected; L5–L7 are read from `levelEvidence` fields
 * written by other subsystems (skill-evals / skill-learnings / skill-orchestration) — this
 * service never writes them. `maturityLevel` is the highest CONTIGUOUS level passed, and
 * this service is the ONLY writer of it: the generic skill write paths carry stored values
 * forward via {@see SkillMaturityService::preserveComputedFields()}.
 *
 * Maturity is strictly orthogonal to the curation lifecycle: this service never reads or
 * writes `state`, and it never touches the agentskills.io content plane
 * (`frontmatter`/`body`/`files` are read-only inputs), so the byte-for-byte export
 * round-trip is untouched.
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Computes and persists the L1–L7 maturity level + per-level scorecard for a Skill.
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
 */
class SkillMaturityService
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
     * The highest maturity level (L7, AI workforce).
     *
     * @var int
     */
    public const MAX_LEVEL = 7;

    /**
     * L2 hard cap: a body of this many lines or more fails triggering outright
     * (normative in the spec — a compact, procedural body scores better than a
     * comprehensive-docs monolith).
     *
     * @var int
     */
    private const BODY_MAX_LINES = 500;

    /**
     * L2 progressive-disclosure trigger: a body of this many lines or more must be
     * accompanied by at least one `references/` entry in `files`. Service-owned
     * constant (spec Notes) — tunable without a spec change.
     *
     * @var int
     */
    private const PROGRESSIVE_DISCLOSURE_LINES = 200;

    /**
     * Verb-ish trigger words a well-triggering description starts with (EN + NL —
     * the one place to extend the phrase heuristics, design.md Risks).
     *
     * @var array<int, string>
     */
    private const TRIGGER_START_VERBS = [
        // English imperatives.
        'use',
        'triage',
        'summarise',
        'summarize',
        'draft',
        'guide',
        'analyse',
        'analyze',
        'review',
        'create',
        'generate',
        'write',
        'extract',
        'classify',
        'clean',
        'convert',
        'translate',
        'check',
        'validate',
        'plan',
        'route',
        'answer',
        'help',
        'find',
        'search',
        'fetch',
        'monitor',
        'prepare',
        'compose',
        'format',
        'fix',
        'run',
        'build',
        'assess',
        'audit',
        'detect',
        'redact',
        'anonymise',
        'anonymize',
        // Dutch imperatives.
        'gebruik',
        'beantwoord',
        'stel',
        'maak',
        'genereer',
        'controleer',
        'vat',
        'schrijf',
        'analyseer',
        'vertaal',
        'zoek',
        'verwerk',
        'beoordeel',
        'herschrijf',
        'toets',
    ];

    /**
     * When-to-use phrasings a well-triggering description contains (EN + NL,
     * matched case-insensitively as substrings).
     *
     * @var array<int, string>
     */
    private const WHEN_TO_USE_PHRASES = [
        'use when',
        'use this when',
        'when the user',
        'trigger',
        'gebruik wanneer',
        'gebruik bij',
        'wanneer de gebruiker',
        ' bij ',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OpenRegister object read/write (single write-path).
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Qualify a skill: recompute L1–L7 from content + evidence, persist `maturityLevel`
     * and the refreshed `levelEvidence.l1`–`l3`, and return the seven-level scorecard.
     * All other stored fields (including `state`, the content plane, `l4`–`l7`
     * evidence, and `targetLevel`) are carried forward unchanged — OR saveObject is
     * PUT-semantic, so the full stored payload is re-sent.
     *
     * @param ObjectEntity $skill The stored Skill object (RBAC/owner checks are the caller's job).
     *
     * @return array<string, mixed> The `{skillId, maturityLevel, targetLevel, scorecard}` payload.
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     */
    public function qualify(ObjectEntity $skill): array
    {
        return $this->persistQualification(skill: $skill, data: $skill->getObject());

    }//end qualify()

    /**
     * Stamp the human L4 attestation (`levelEvidence.l4`) and recompute. The ONLY code
     * path that writes `l4` — L4 is never auto-detected; the caller (attest endpoint)
     * is responsible for the `skill.attest-maturity` action gate (ADR-023).
     *
     * @param ObjectEntity $skill      The stored Skill object.
     * @param string       $attestedBy The attesting curator's user id.
     * @param string       $note       Optional curator note.
     *
     * @return array<string, mixed> The refreshed `{skillId, maturityLevel, targetLevel, scorecard}` payload.
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
     */
    public function attestL4(ObjectEntity $skill, string $attestedBy, string $note=''): array
    {
        $data     = $skill->getObject();
        $evidence = $this->evidenceOf(data: $data);

        $evidence['l4'] = [
            'attestedBy' => $attestedBy,
            'attestedAt' => $this->now(),
            'note'       => $note,
        ];

        $data['levelEvidence'] = $evidence;

        return $this->persistQualification(skill: $skill, data: $data);

    }//end attestL4()

    /**
     * Compute the seven-level scorecard + contiguous maturity level for a skill payload.
     * Pure read — never persists, never mutates state.
     *
     * @param array<string, mixed> $data The skill object payload.
     *
     * @return array{maturityLevel: int, scorecard: array<int, array{level: int, passed: bool, reasons: array<int, string>}>}
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l5l7-are-read-from-evidence-written-by-other-subsystems
     */
    public function computeScorecard(array $data): array
    {
        $evidence = $this->evidenceOf(data: $data);

        $checks = [
            1 => $this->checkL1(data: $data),
            2 => $this->checkL2(data: $data),
            3 => $this->checkL3(data: $data),
            4 => $this->checkL4(evidence: $evidence),
            5 => $this->checkL5(evidence: $evidence),
            6 => $this->checkL6(evidence: $evidence),
            7 => $this->checkL7(evidence: $evidence),
        ];

        $scorecard = [];
        foreach ($checks as $level => $check) {
            $scorecard[] = [
                'level'   => $level,
                'passed'  => $check['passed'],
                'reasons' => $check['reasons'],
            ];
        }

        // Contiguous fold: the highest n with ALL of L1..Ln passed — a passed higher
        // check never skips a failed lower level (design.md Decision 4).
        $maturityLevel = 0;
        for ($level = 1; $level <= self::MAX_LEVEL; $level++) {
            if ($checks[$level]['passed'] === false) {
                break;
            }

            $maturityLevel = $level;
        }

        return [
            'maturityLevel' => $maturityLevel,
            'scorecard'     => $scorecard,
        ];

    }//end computeScorecard()

    /**
     * Silent-preserve write guard: return the incoming client payload with the
     * computed maturity fields (`maturityLevel` + `levelEvidence.l1`–`l4`) overwritten
     * by the STORED values — a hand-set value never survives a hermiq write path.
     * `targetLevel` (curator intent) and `l5`–`l7` (owned by other subsystems'
     * write paths) pass through untouched.
     *
     * @param array<string, mixed> $incoming The client-supplied skill payload.
     * @param array<string, mixed> $stored   The currently stored skill payload.
     *
     * @return array<string, mixed> The guarded payload.
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-maturitylevel-and-computed-evidence-are-never-client-writable
     */
    public function preserveComputedFields(array $incoming, array $stored): array
    {
        if (array_key_exists('maturityLevel', $stored) === true) {
            $incoming['maturityLevel'] = $stored['maturityLevel'];
        } else {
            unset($incoming['maturityLevel']);
        }

        $storedEvidence   = $this->evidenceOf(data: $stored);
        $incomingEvidence = $this->evidenceOf(data: $incoming);

        foreach (['l1', 'l2', 'l3', 'l4'] as $key) {
            if (array_key_exists($key, $storedEvidence) === true) {
                $incomingEvidence[$key] = $storedEvidence[$key];
            } else {
                unset($incomingEvidence[$key]);
            }
        }

        if ($incomingEvidence === [] && array_key_exists('levelEvidence', $incoming) === false) {
            return $incoming;
        }

        $incoming['levelEvidence'] = $incomingEvidence;

        return $incoming;

    }//end preserveComputedFields()

    /**
     * Compute, persist (maturityLevel + refreshed l1–l3 evidence, everything else
     * carried forward), and shape the qualify/attest response payload.
     *
     * @param ObjectEntity         $skill The stored Skill object (for uuid + fallback).
     * @param array<string, mixed> $data  The (possibly l4-stamped) payload to persist from.
     *
     * @return array<string, mixed> The `{skillId, maturityLevel, targetLevel, scorecard}` payload.
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     */
    private function persistQualification(ObjectEntity $skill, array $data): array
    {
        $computed  = $this->computeScorecard(data: $data);
        $checkedAt = $this->now();

        $evidence = $this->evidenceOf(data: $data);
        foreach ([1, 2, 3] as $level) {
            $evidence['l'.$level] = [
                'passed'    => $computed['scorecard'][($level - 1)]['passed'],
                'checkedAt' => $checkedAt,
            ];
        }

        $data['levelEvidence'] = $evidence;
        $data['maturityLevel'] = $computed['maturityLevel'];

        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: (string) $skill->getUuid()
        );

        $targetLevel = null;
        if (isset($data['targetLevel']) === true && is_numeric($data['targetLevel']) === true) {
            $targetLevel = (int) $data['targetLevel'];
        }

        return [
            'skillId'       => (string) $skill->getUuid(),
            'maturityLevel' => $computed['maturityLevel'],
            'targetLevel'   => $targetLevel,
            'scorecard'     => $computed['scorecard'],
        ];

    }//end persistQualification()

    /**
     * L1 Anatomy: the frontmatter parses and yields a non-empty `name` and
     * `description`, and the `body` is non-empty (reason bucket: structure).
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return array{passed: bool, reasons: array<int, string>}
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    private function checkL1(array $data): array
    {
        $reasons     = [];
        $frontmatter = $this->stringOf(value: ($data['frontmatter'] ?? ''));

        if (trim($frontmatter) === '') {
            $reasons[] = 'frontmatter is missing or empty';
        } else {
            if ($this->frontmatterField(frontmatter: $frontmatter, field: 'name') === '') {
                $reasons[] = 'frontmatter has no name';
            }

            if ($this->frontmatterField(frontmatter: $frontmatter, field: 'description') === '') {
                $reasons[] = 'frontmatter has no description';
            }
        }

        if (trim($this->stringOf(value: ($data['body'] ?? ''))) === '') {
            $reasons[] = 'body is empty';
        }

        return [
            'passed'  => ($reasons === []),
            'reasons' => $reasons,
        ];

    }//end checkL1()

    /**
     * L2 Triggering: the description reads as a trigger (verb-ish start AND
     * when-to-use phrasing, EN + NL), the body stays under the 500-line cap, and a
     * large body shows progressive disclosure via `references/` entries in `files`
     * (reason bucket: triggering).
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return array{passed: bool, reasons: array<int, string>}
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    private function checkL2(array $data): array
    {
        $reasons     = [];
        $description = trim($this->descriptionOf(data: $data));

        if ($this->startsWithTriggerVerb(description: $description) === false) {
            $reasons[] = 'description does not start with trigger phrasing';
        }

        if ($this->containsWhenToUsePhrase(description: $description) === false) {
            $reasons[] = 'description has no when-to-use phrasing';
        }

        $body      = $this->stringOf(value: ($data['body'] ?? ''));
        $bodyLines = $this->lineCount(text: $body);

        if ($bodyLines >= self::BODY_MAX_LINES) {
            $reasons[] = 'body is 500 lines or more';
        }

        $fileNames     = $this->fileNames(data: $data);
        $hasReferences = $this->hasEntryUnder(fileNames: $fileNames, prefixes: ['references/']);

        if ($bodyLines >= self::PROGRESSIVE_DISCLOSURE_LINES && $hasReferences === false) {
            $reasons[] = 'large body has no references/ entries (no progressive disclosure)';
        }

        return [
            'passed'  => ($reasons === []),
            'reasons' => $reasons,
        ];

    }//end checkL2()

    /**
     * L3 Patterns: the `files` map contains at least one `references/*` or
     * `examples/*` entry (reason bucket: structure).
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return array{passed: bool, reasons: array<int, string>}
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1l3-mechanically-from-skill-content
     */
    private function checkL3(array $data): array
    {
        $fileNames = $this->fileNames(data: $data);
        $passed    = $this->hasEntryUnder(fileNames: $fileNames, prefixes: ['references/', 'examples/']);

        $reasons = [];
        if ($passed === false) {
            $reasons[] = 'no references/ or examples/ entry in files';
        }

        return [
            'passed'  => $passed,
            'reasons' => $reasons,
        ];

    }//end checkL3()

    /**
     * L4 Personalization: passes ONLY on a present human attestation
     * (`levelEvidence.l4.attestedBy` + `attestedAt`) — never auto-detected
     * (reason bucket: human attestation).
     *
     * @param array<string, mixed> $evidence The `levelEvidence` map.
     *
     * @return array{passed: bool, reasons: array<int, string>}
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
     */
    private function checkL4(array $evidence): array
    {
        $l4 = $this->levelEntry(evidence: $evidence, key: 'l4');

        $attestedBy = $this->stringOf(value: ($l4['attestedBy'] ?? ''));
        $attestedAt = $this->stringOf(value: ($l4['attestedAt'] ?? ''));
        $passed     = ($attestedBy !== '' && $attestedAt !== '');

        $reasons = [];
        if ($passed === false) {
            $reasons[] = 'not human-attested';
        }

        return [
            'passed'  => $passed,
            'reasons' => $reasons,
        ];

    }//end checkL4()

    /**
     * L5 Measurement: complete eval evidence in `levelEvidence.l5`
     * (`evalDatasetId` + `passRate` + `baselineDelta` + `lastValidated`) — written by
     * the future skill-evals change, only read here (reason bucket: eval evidence).
     *
     * @param array<string, mixed> $evidence The `levelEvidence` map.
     *
     * @return array{passed: bool, reasons: array<int, string>}
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l5l7-are-read-from-evidence-written-by-other-subsystems
     */
    private function checkL5(array $evidence): array
    {
        $l5 = $this->levelEntry(evidence: $evidence, key: 'l5');

        if ($l5 === []) {
            return [
                'passed'  => false,
                'reasons' => ['no eval evidence (levelEvidence.l5 empty)'],
            ];
        }

        $hasDataset   = ($this->stringOf(value: ($l5['evalDatasetId'] ?? '')) !== '');
        $hasPassRate  = (isset($l5['passRate']) === true && is_numeric($l5['passRate']) === true);
        $hasDelta     = (isset($l5['baselineDelta']) === true && is_numeric($l5['baselineDelta']) === true);
        $hasValidated = ($this->stringOf(value: ($l5['lastValidated'] ?? '')) !== '');
        $passed       = ($hasDataset === true && $hasPassRate === true && $hasDelta === true && $hasValidated === true);

        $reasons = [];
        if ($passed === false) {
            $reasons[] = 'incomplete eval evidence (levelEvidence.l5)';
        }

        return [
            'passed'  => $passed,
            'reasons' => $reasons,
        ];

    }//end checkL5()

    /**
     * L6 Self-Improvement: learnings activity in `levelEvidence.l6`
     * (`learningsCount` > 0 with `lastConsolidatedAt`) — written by the future
     * skill-learnings / skill-self-improvement changes, only read here
     * (reason bucket: learnings activity).
     *
     * @param array<string, mixed> $evidence The `levelEvidence` map.
     *
     * @return array{passed: bool, reasons: array<int, string>}
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l5l7-are-read-from-evidence-written-by-other-subsystems
     */
    private function checkL6(array $evidence): array
    {
        $l6 = $this->levelEntry(evidence: $evidence, key: 'l6');

        $count = 0;
        if (isset($l6['learningsCount']) === true && is_numeric($l6['learningsCount']) === true) {
            $count = (int) $l6['learningsCount'];
        }

        $hasConsolidated = ($this->stringOf(value: ($l6['lastConsolidatedAt'] ?? '')) !== '');
        $passed          = ($count > 0 && $hasConsolidated === true);

        $reasons = [];
        if ($passed === false) {
            $reasons[] = 'no learnings activity';
        }

        return [
            'passed'  => $passed,
            'reasons' => $reasons,
        ];

    }//end checkL6()

    /**
     * L7 AI Workforce: executed-chain evidence in `levelEvidence.l7`
     * (`lastExecutedChainRunId` + `lastExecutedAt`) — a declared-but-never-executed
     * chain is structurally L7, not mature L7. Written by the future
     * skill-orchestration change, only read here (reason bucket: orchestration use).
     *
     * @param array<string, mixed> $evidence The `levelEvidence` map.
     *
     * @return array{passed: bool, reasons: array<int, string>}
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-l5l7-are-read-from-evidence-written-by-other-subsystems
     */
    private function checkL7(array $evidence): array
    {
        $l7 = $this->levelEntry(evidence: $evidence, key: 'l7');

        $hasRunId   = ($this->stringOf(value: ($l7['lastExecutedChainRunId'] ?? '')) !== '');
        $hasRunTime = ($this->stringOf(value: ($l7['lastExecutedAt'] ?? '')) !== '');
        $passed     = ($hasRunId === true && $hasRunTime === true);

        $reasons = [];
        if ($passed === false) {
            $reasons[] = 'no executed chain run';
        }

        return [
            'passed'  => $passed,
            'reasons' => $reasons,
        ];

    }//end checkL7()

    /**
     * Whether the description starts with a verb-ish trigger word (EN + NL).
     *
     * @param string $description The trimmed skill description.
     *
     * @return bool
     */
    private function startsWithTriggerVerb(string $description): bool
    {
        if ($description === '') {
            return false;
        }

        $firstWord = strtolower(strtok($description, " \t\n"));
        $firstWord = trim($firstWord, '.,:;!?"\'');

        return in_array($firstWord, self::TRIGGER_START_VERBS, true);

    }//end startsWithTriggerVerb()

    /**
     * Whether the description contains a when-to-use phrasing (EN + NL).
     *
     * @param string $description The trimmed skill description.
     *
     * @return bool
     */
    private function containsWhenToUsePhrase(string $description): bool
    {
        $haystack = strtolower($description);

        foreach (self::WHEN_TO_USE_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase) === true) {
                return true;
            }
        }

        return false;

    }//end containsWhenToUsePhrase()

    /**
     * The skill's effective description: the frontmatter `description` when present,
     * falling back to the object-level `description` property.
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return string
     */
    private function descriptionOf(array $data): string
    {
        $frontmatter = $this->stringOf(value: ($data['frontmatter'] ?? ''));
        $description = $this->frontmatterField(frontmatter: $frontmatter, field: 'description');

        if ($description !== '') {
            return $description;
        }

        return $this->stringOf(value: ($data['description'] ?? ''));

    }//end descriptionOf()

    /**
     * Extract a scalar `field: value` from a raw frontmatter block (mirrors
     * SkillSerializer's extraction — the block is stored verbatim, never re-dumped).
     *
     * @param string $frontmatter The raw frontmatter block.
     * @param string $field       The field name.
     *
     * @return string The trimmed, unquoted value (empty when absent).
     */
    private function frontmatterField(string $frontmatter, string $field): string
    {
        $pattern = '/^'.preg_quote($field, '/').':\s*(.*)$/m';
        if (preg_match($pattern, $frontmatter, $matches) !== 1) {
            return '';
        }

        $value = trim($matches[1]);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[(strlen($value) - 1)];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return $value;

    }//end frontmatterField()

    /**
     * The auxiliary file names/paths of a skill. Supports both the schema's
     * list-of-`{name, content}` shape and a map keyed by path.
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return array<int, string> The file names (paths).
     */
    private function fileNames(array $data): array
    {
        $files = ($data['files'] ?? []);
        if (is_array($files) === false) {
            return [];
        }

        $names = [];
        foreach ($files as $key => $file) {
            if (is_array($file) === true && isset($file['name']) === true) {
                $names[] = $this->stringOf(value: $file['name']);
                continue;
            }

            if (is_string($key) === true) {
                $names[] = $key;
            }
        }

        return $names;

    }//end fileNames()

    /**
     * Whether any file name starts with one of the given path prefixes.
     *
     * @param array<int, string> $fileNames The file names.
     * @param array<int, string> $prefixes  The path prefixes (e.g. 'references/').
     *
     * @return bool
     */
    private function hasEntryUnder(array $fileNames, array $prefixes): bool
    {
        foreach ($fileNames as $name) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix) === true) {
                    return true;
                }
            }
        }

        return false;

    }//end hasEntryUnder()

    /**
     * Count the lines of a text block (empty text counts as zero lines).
     *
     * @param string $text The text.
     *
     * @return int
     */
    private function lineCount(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        return (substr_count($text, "\n") + 1);

    }//end lineCount()

    /**
     * The `levelEvidence` map of a skill payload (empty array when absent/malformed).
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return array<string, mixed>
     */
    private function evidenceOf(array $data): array
    {
        $evidence = ($data['levelEvidence'] ?? []);
        if (is_array($evidence) === false) {
            return [];
        }

        return $evidence;

    }//end evidenceOf()

    /**
     * One level's evidence sub-object (empty array when absent/malformed).
     *
     * @param array<string, mixed> $evidence The `levelEvidence` map.
     * @param string               $key      The level key (`l1`…`l7`).
     *
     * @return array<string, mixed>
     */
    private function levelEntry(array $evidence, string $key): array
    {
        $entry = ($evidence[$key] ?? []);
        if (is_array($entry) === false) {
            return [];
        }

        return $entry;

    }//end levelEntry()

    /**
     * A scalar value as a string ('' for non-scalars).
     *
     * @param mixed $value The value.
     *
     * @return string
     */
    private function stringOf(mixed $value): string
    {
        if (is_scalar($value) === false) {
            return '';
        }

        return (string) $value;

    }//end stringOf()

    /**
     * The current time as an ISO-8601 (ATOM) string.
     *
     * @return string
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

    }//end now()
}//end class
