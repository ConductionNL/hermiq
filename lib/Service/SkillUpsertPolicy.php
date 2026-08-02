<?php

/**
 * Hermiq SkillUpsertPolicy
 *
 * Decides what an update from a published bundle is allowed to overwrite.
 *
 * Three rules, each of which exists because its opposite loses something the
 * instance cannot get back:
 *
 *  1. **Content is replaced, curation is not.** A bundle carries `body`,
 *     `frontmatter`, `files` and `description`. Maturity, evidence, agent
 *     installations and acceptance history are decisions THIS instance made; an app
 *     shipping new content has no standing to reset them. The merge therefore names
 *     the keys it writes rather than replacing the payload.
 *
 *  2. **Any content change re-quarantines.** Not "a worse scan verdict" — an
 *     approval is a statement about specific content, and once that content changes
 *     the statement no longer applies whatever a scanner says. The cost of
 *     re-quarantining is an unnecessary review; the cost of the alternative is
 *     unreviewed content executing under an old approval. Those are not comparable.
 *
 *  3. **Local learnings are never overwritten.** ADR-068 §3 gives a skill a
 *     `learnings.md` this instance adds to. When local learnings postdate the last
 *     sync, an incoming `learnings.md` does NOT replace them — the rest of the
 *     update still lands. A warning issued after an overwrite is worthless, and a
 *     confirmation prompt puts the destructive default one click away; keeping the
 *     file makes the loss unreachable instead.
 *
 * The learnings clock is `sourceUpdatedAt`, NOT `publishedAt`.
 * `SkillConsolidationService::isBehind()` compares against `publishedAt`, which is
 * correct for deciding whether to republish — but `publishedAt` is stamped only when
 * this instance publishes TO a remote, so on an instance that only installs it is
 * empty and the comparison is silently always false. Building the guard on it would
 * produce something that reviews as correct and never fires once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Merges bundle content onto an existing skill without destroying local state.
 */
class SkillUpsertPolicy
{

    /**
     * The learnings file a skill accumulates locally (ADR-068 §3).
     *
     * @var string
     */
    public const LEARNINGS_FILE = 'learnings.md';

    /**
     * Quarantine reason recorded when an update changed the content.
     *
     * @var string
     */
    public const REASON_CONTENT_CHANGED = 'Content changed on update from source — re-review required.';

    /**
     * Keys a bundle update is allowed to write.
     *
     * @var array<int,string>
     */
    private const CONTENT_KEYS = ['body', 'frontmatter', 'files', 'description'];

    /**
     * Keys an update must NEVER write. Human decisions and earned history.
     *
     * @var array<int,string>
     */
    private const CURATED_KEYS = [
        'maturityLevel',
        'targetLevel',
        'levelEvidence',
        'installedOn',
        'createdBy',
        'publishedAt',
        'archivedAt',
        'lastAcceptedVersionAt',
    ];

    /**
     * Merge incoming bundle content onto an existing skill.
     *
     * @param array<string,mixed> $existing  The stored skill payload.
     * @param array<string,mixed> $incoming  The parsed bundle skill (content keys).
     * @param string              $sourceUrl The canonical identity url.
     * @param string              $now       ISO-8601 timestamp for this sync.
     *
     * @return array{payload:array<string,mixed>,changed:bool,learningsKept:bool}
     *
     * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-curated-state-survives-an-update
     */
    public function merge(array $existing, array $incoming, string $sourceUrl, string $now): array
    {
        $payload       = $existing;
        $learningsKept = false;

        $incomingFiles = (array) ($incoming['files'] ?? []);
        $existingFiles = (array) ($existing['files'] ?? []);

        if ($this->wouldLoseLearnings(existing: $existing, incomingFiles: $incomingFiles) === true) {
            // Keep the local learnings file, take everything else. The update still
            // lands, so preserving learnings never blocks a fix to the skill body.
            $incoming['files'] = $this->withLocalLearnings(
                incomingFiles: $incomingFiles,
                existingFiles: $existingFiles
            );
            $learningsKept     = true;
        }

        $changed = false;
        foreach (self::CONTENT_KEYS as $key) {
            if (array_key_exists($key, $incoming) === false) {
                continue;
            }

            if (($existing[$key] ?? null) !== $incoming[$key]) {
                $changed = true;
            }

            $payload[$key] = $incoming[$key];
        }

        // Curated keys are carried forward verbatim. Explicit rather than implicit:
        // OpenRegister saveObject is PUT-semantic, so a key merely omitted here would
        // be nulled on write.
        foreach (self::CURATED_KEYS as $key) {
            if (array_key_exists($key, $existing) === true) {
                $payload[$key] = $existing[$key];
            }
        }

        if ($changed === true) {
            $payload['state']            = 'quarantined';
            $payload['quarantineReason'] = self::REASON_CONTENT_CHANGED;
        }

        $payload['sourceUrl']       = $sourceUrl;
        $payload['sourceUpdatedAt'] = $now;
        $payload['lastActivityAt']  = $now;

        return [
            'payload'       => $payload,
            'changed'       => $changed,
            'learningsKept' => $learningsKept,
        ];

    }//end merge()

    /**
     * Whether applying the incoming files would discard local learnings.
     *
     * Requires BOTH that learnings were accepted locally since the last sync AND
     * that the incoming file actually differs, so it cannot fire for a skill nobody
     * has taught anything.
     *
     * @param array<string,mixed> $existing      The stored skill payload.
     * @param array<string,mixed> $incomingFiles The incoming aux files.
     *
     * @return bool True when local learnings would be lost.
     *
     * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-local-learnings-are-never-overwritten-by-an-update
     */
    public function wouldLoseLearnings(array $existing, array $incomingFiles): bool
    {
        $localLearnings = $this->learningsOf(files: (array) ($existing['files'] ?? []));
        if ($localLearnings === null) {
            return false;
        }

        $incomingLearnings = $this->learningsOf(files: $incomingFiles);
        if ($incomingLearnings === null || $incomingLearnings === $localLearnings) {
            return false;
        }

        return $this->hasLocalLearningsSinceSync(existing: $existing);

    }//end wouldLoseLearnings()

    /**
     * Whether learnings were accepted locally since the last sync FROM source.
     *
     * @param array<string,mixed> $existing The stored skill payload.
     *
     * @return bool True when local learnings postdate the last sync.
     */
    private function hasLocalLearningsSinceSync(array $existing): bool
    {
        $accepted = (string) ($existing['lastAcceptedVersionAt'] ?? '');
        if ($accepted === '') {
            return false;
        }

        // NOT publishedAt — see the class docblock. An instance that only installs
        // never stamps publishedAt, so a comparison against it never fires.
        $synced = (string) ($existing['sourceUpdatedAt'] ?? '');
        if ($synced === '') {
            // Never synced but learnings HAVE been accepted: local work exists and
            // there is no evidence it came from the bundle, so protect it.
            return true;
        }

        try {
            return new DateTimeImmutable($accepted) > new DateTimeImmutable($synced);
        } catch (Throwable) {
            // An unparseable clock must not silently authorise a destructive write.
            return true;
        }

    }//end hasLocalLearningsSinceSync()

    /**
     * Replace the incoming learnings file with the locally held one.
     *
     * @param array<string,mixed> $incomingFiles The incoming aux files.
     * @param array<string,mixed> $existingFiles The stored aux files.
     *
     * @return array<string,mixed> The incoming files with local learnings preserved.
     */
    private function withLocalLearnings(array $incomingFiles, array $existingFiles): array
    {
        foreach ($incomingFiles as $index => $file) {
            if ($this->isLearnings(file: $file) === false) {
                continue;
            }

            foreach ($existingFiles as $local) {
                if ($this->isLearnings(file: $local) === true) {
                    $incomingFiles[$index] = $local;
                    break;
                }
            }
        }

        return $incomingFiles;

    }//end withLocalLearnings()

    /**
     * Read the learnings file's contents out of a files collection.
     *
     * Tolerates both shapes hermiq stores: a `{name, contents}` list and a
     * path-keyed map.
     *
     * @param array<string,mixed> $files The files collection.
     *
     * @return string|null The learnings contents, or null when absent.
     */
    private function learningsOf(array $files): ?string
    {
        foreach ($files as $key => $file) {
            if (is_string($key) === true && basename($key) === self::LEARNINGS_FILE && is_string($file) === true) {
                return $file;
            }

            if ($this->isLearnings(file: $file) === true && is_array($file) === true) {
                return (string) ($file['contents'] ?? '');
            }
        }

        return null;

    }//end learningsOf()

    /**
     * Whether a files entry is the learnings file.
     *
     * @param mixed $file The entry.
     *
     * @return bool True when it is learnings.md.
     */
    private function isLearnings(mixed $file): bool
    {
        if (is_array($file) === false) {
            return false;
        }

        return basename((string) ($file['name'] ?? '')) === self::LEARNINGS_FILE;

    }//end isLearnings()
}//end class
