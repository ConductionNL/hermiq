<?php

/**
 * Hermiq Engine Sanitize-For-Save Trait.
 *
 * OpenRegister's `ObjectService::saveObject()` re-validates the WHOLE payload
 * against its JSON schema on every save, but `ObjectEntity::getObject()`'s own
 * read-side shapes can fail that re-validation raw (observed for real on
 * Hermiq's `ScheduleService` — see the reference gotcha this trait exists to
 * codify):
 *
 * 1. Date-time fields can round-trip as `Y-m-d H:i:s` (space-separated) instead
 *    of the ISO-8601 `T`-separated form a `format: date-time` schema property
 *    requires — reformatted via `DateTime::format('c')` before every save.
 * 2. A stored `null` nested object can come back materialized as an assoc array
 *    of all-null sub-fields, which then fails a nested schema's `minimum`/
 *    required constraints — collapsed back to `null` before save. Only
 *    associative arrays are eligible (a `list` array — e.g. `sources`,
 *    `entries` — legitimately contains null-ish entries and must never be
 *    collapsed).
 *
 * None of the four `agent-engine-schemas` schemas (Agent/Conversation/Message/
 * Feedback) currently declare a `format: date-time` property or a nested
 * object with sub-constraints, so neither case is load-bearing for THIS
 * change today — the helper is still applied defensively on every save so a
 * future schema field (or a differently-shaped `metadata`/`configuration`/
 * `context` payload) does not silently corrupt data the way it did for
 * ScheduleService. Every Engine class that calls `ObjectService::saveObject()`
 * MUST route the payload through `sanitizeForSave()` first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use DateTime;
use Exception;

/**
 * Shared save-payload normalisation for Engine classes writing OR objects.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
 */
trait SanitizesForSaveTrait
{
    /**
     * Normalise a payload before `ObjectService::saveObject()`.
     *
     * @param array<string, mixed> $data The object payload about to be saved.
     *
     * @return array<string, mixed> The normalised payload.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     */
    private function sanitizeForSave(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) === true) {
                $data[$key] = $this->reformatSqlDateTime(value: $value);
                continue;
            }

            if (is_array($value) === true) {
                if ($this->isAllNullAssoc(value: $value) === true) {
                    $data[$key] = null;
                    continue;
                }

                $data[$key] = $this->sanitizeForSave(data: $value);
            }
        }

        return $data;

    }//end sanitizeForSave()

    /**
     * Reformat a `Y-m-d H:i:s` (space-separated) date-time string to ISO-8601
     * (`DateTime::format('c')`). Any other string is returned unchanged.
     *
     * @param string $value The candidate value.
     *
     * @return string The reformatted value, or the original when it does not
     *                match the SQL date-time shape.
     */
    private function reformatSqlDateTime(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return $value;
        }

        try {
            $date = new DateTime($value);
        } catch (Exception) {
            return $value;
        }

        return $date->format('c');

    }//end reformatSqlDateTime()

    /**
     * Whether an array is a non-empty associative array whose values are ALL
     * `null` — the shape a stored `null` nested object materializes as on
     * read. Lists (`array_is_list()`) are never collapsed.
     *
     * @param array<array-key, mixed> $value The candidate array.
     *
     * @return bool True when every value is `null` and the array is associative.
     */
    private function isAllNullAssoc(array $value): bool
    {
        if ($value === [] || array_is_list($value) === true) {
            return false;
        }

        foreach ($value as $item) {
            if ($item !== null) {
                return false;
            }
        }

        return true;

    }//end isAllNullAssoc()
}//end trait
