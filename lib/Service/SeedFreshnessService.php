<?php

/**
 * Hermiq Seed Freshness Service.
 *
 * Keeps system-seeded skills fresh across repair runs so the daily Curator's
 * age-based staleness (active → stale via `lastActivityAt`) never empties a
 * longer-lived instance's seed catalog — and with it every skill link-picker —
 * merely because nobody ran the demo content.
 *
 * Two rules, both scoped HARD to `__system__`-owned seeds:
 *
 * - `stampFresh()` stamps `lastActivityAt = now` on a seed payload at creation,
 *   so a fresh seed starts its staleness clock at seed time instead of being
 *   born stale (a missing `lastActivityAt` reads as "older than everything").
 * - `refreshedPayload()` returns an updated payload for an EXISTING seed on a
 *   repair re-run: `lastActivityAt` is refreshed, and a `stale` seed flips back
 *   to `active` (dropping `staleSince`). Objects in any other state (`archived`,
 *   `quarantined`) are NEVER touched — those are curator or human decisions and
 *   they win. Objects not owned by `__system__` are NEVER touched — a
 *   human-created skill's lifecycle belongs to its owner.
 *
 * All other fields keep the seeders' only-when-absent semantics; this service
 * never rewrites seed content.
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use Throwable;

/**
 * Seed lifecycle freshness rules for `__system__`-owned seed skills.
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */
class SeedFreshnessService
{
    /**
     * Stamp `lastActivityAt = now` on a seed payload about to be created, so the
     * Curator's staleness clock starts at seed time.
     *
     * @param array<string, mixed> $seed The seed object payload.
     *
     * @return array<string, mixed> The payload with a fresh `lastActivityAt`.
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
     */
    public function stampFresh(array $seed): array
    {
        $seed['lastActivityAt'] = $this->now();

        return $seed;

    }//end stampFresh()

    /**
     * The refreshed payload for an existing seed on a repair re-run, or null when
     * the object MUST NOT be touched.
     *
     * Refreshes `lastActivityAt` and flips a `stale` seed back to `active`
     * (dropping `staleSince`) — but ONLY for `__system__`-owned objects still in
     * state `active` or `stale`. Human-owned objects and curator/human terminal
     * states (`archived`, `quarantined`) return null: those decisions win.
     *
     * @param ObjectEntity $skill The existing seed object.
     *
     * @return array<string, mixed>|null The payload to save, or null when untouchable.
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
     */
    public function refreshedPayload(ObjectEntity $skill): ?array
    {
        if ((string) ($skill->getOwner() ?? '') !== SeedCustodyService::SYSTEM_OWNER) {
            // Human-created (or human-owned) — its lifecycle is never ours to reset.
            return null;
        }

        $data  = $skill->getObject();
        $state = (string) ($data['state'] ?? '');
        if ($state !== 'active' && $state !== 'stale') {
            // Archived / quarantined (or unknown): a curator or human decision — wins.
            return null;
        }

        $data['lastActivityAt'] = $this->now();

        if ($state === 'stale') {
            $data['state'] = 'active';
            // PUT-semantic saveObject: omitting the key nulls the stored value.
            unset($data['staleSince']);
        }

        // OR materialises stored date-times back space-separated ('Y-m-d H:i:s');
        // re-saving that value fails the schema's 'date-time' format. Normalise any
        // remaining lifecycle date field to ISO-8601 (the SkillMarketplaceService
        // save() gotcha). Envelope keys never belong in the payload on a re-save.
        foreach (['staleSince', 'archivedAt'] as $field) {
            if (isset($data[$field]) === true && is_string($data[$field]) === true && $data[$field] !== '') {
                $data[$field] = $this->normaliseDate(value: $data[$field]);
            }
        }

        unset($data['id'], $data['uuid'], $data['@self']);

        return $data;

    }//end refreshedPayload()

    /**
     * Now, as ISO-8601 UTC.
     *
     * @return string The current timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    }//end now()

    /**
     * Normalise a stored date-time string to ISO-8601 (null when unparseable).
     *
     * @param string $value The stored date-time value.
     *
     * @return string|null The ISO-8601 value, or null.
     */
    private function normaliseDate(string $value): ?string
    {
        try {
            return (new DateTimeImmutable($value))->format('c');
        } catch (Throwable $e) {
            return null;
        }

    }//end normaliseDate()
}//end class
