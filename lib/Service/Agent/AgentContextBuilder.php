<?php

/**
 * Hermiq AgentContextBuilder.
 *
 * Builds the BOUNDED, FAIL-CLOSED object context an agent surface is allowed to
 * see, from a schema's declarative `x-openregister-agent-context` allowlist. This
 * generalises procest's hand-coded `CaseAssistantService::buildCaseSummary()`
 * whitelist into a reusable, schema-driven decision so no leaf app re-derives its
 * own context-safety rules (agent-object-leaf).
 *
 * The allowlist lives on the SCHEMA (beside `x-openregister-flows`), not in
 * per-app PHP, so the "which fields may reach an agent" decision is reviewable in
 * one declarative place. The rule is fail-closed:
 *   - keyword absent or empty         → EMPTY context (never the whole object);
 *   - a listed property missing on the instance → omitted, not an error;
 *   - a property never listed          → never forwarded.
 *
 * ADR-066 render-and-read boundary: this builder only READS an object; it holds
 * no run authority and dispatches no command.
 *
 * Allowlist shapes accepted on `x-openregister-agent-context`:
 *   - a plain list of property names: `["title", "status", "description"]`
 *   - an associative map of name => caps: `{"description": {"maxLength": 500}}`
 *   - a mixed list where an entry is `{"property": "description", "maxLength": 500}`
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-declarative-bounded-agent-context-allowlist
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

/**
 * Builds a bounded, fail-closed agent context from a schema allowlist.
 *
 * @spec openspec/changes/hermiq-agent-leaf/tasks.md#3-declarative-context-allowlist
 */
class AgentContextBuilder
{

    /**
     * The schema keyword naming the agent-context allowlist.
     *
     * @var string
     */
    public const KEYWORD = 'x-openregister-agent-context';

    /**
     * Build the bounded context from an object's data and its schema configuration.
     *
     * @param array<string,mixed> $objectData          The object's data (its
     *                                                 `jsonSerialize()` output or `getObject()`).
     * @param array<string,mixed> $schemaConfiguration The target schema's configuration array
     *                                                 (`Schema::getConfiguration()`), from which
     *                                                 the `x-openregister-agent-context` allowlist
     *                                                 is read. Anything else in it is ignored.
     *
     * @return array<string,mixed> The bounded context — ONLY allowlisted properties present on the
     *                             instance, capped where declared. Empty when the allowlist is
     *                             absent or empty (fail-closed).
     *
     * @spec openspec/changes/hermiq-agent-leaf/tasks.md#task-3-1
     */
    public function build(array $objectData, array $schemaConfiguration): array
    {
        $allowlist = $this->normaliseAllowlist(spec: ($schemaConfiguration[self::KEYWORD] ?? null));
        if ($allowlist === []) {
            // Fail-closed: no allowlist means an EMPTY context, never the whole object.
            return [];
        }

        $context = [];
        foreach ($allowlist as $property => $caps) {
            if (array_key_exists($property, $objectData) === false) {
                // A listed property absent on the instance is omitted, not an error.
                continue;
            }

            $value = $objectData[$property];
            if ($value === null || $value === '') {
                continue;
            }

            $context[$property] = $this->applyCaps(value: $value, caps: $caps);
        }

        return $context;

    }//end build()

    /**
     * Normalise the raw allowlist spec into a `property => caps[]` map.
     *
     * Accepts a plain list of names, an associative name => caps map, or a list of
     * `{property, ...caps}` entries. Anything else yields an empty allowlist
     * (fail-closed).
     *
     * @param mixed $spec The raw `x-openregister-agent-context` value.
     *
     * @return array<string,array<string,mixed>> Property name => caps map.
     *
     * @spec openspec/changes/hermiq-agent-leaf/tasks.md#task-3-2
     */
    private function normaliseAllowlist(mixed $spec): array
    {
        if (is_array($spec) === false || $spec === []) {
            return [];
        }

        $allowlist = [];
        foreach ($spec as $key => $entry) {
            // Plain list entry: a bare property name string.
            if (is_int($key) === true && is_string($entry) === true && $entry !== '') {
                $allowlist[$entry] = [];
                continue;
            }

            // List of {property, maxLength, ...} entries.
            if (is_int($key) === true && is_array($entry) === true) {
                $name = (string) ($entry['property'] ?? '');
                if ($name !== '') {
                    $allowlist[$name] = $entry;
                }

                continue;
            }

            // Associative name => caps map.
            if (is_string($key) === true && $key !== '') {
                $caps = [];
                if (is_array($entry) === true) {
                    $caps = $entry;
                }

                $allowlist[$key] = $caps;
            }
        }//end foreach

        return $allowlist;

    }//end normaliseAllowlist()

    /**
     * Apply per-field caps (currently `maxLength`, multibyte-safe) to a value.
     *
     * Only string values are truncated; non-strings pass through unchanged so a
     * structured allowlisted field keeps its shape.
     *
     * @param mixed               $value The property value.
     * @param array<string,mixed> $caps  The per-field caps (`maxLength`).
     *
     * @return mixed The capped value.
     *
     * @spec openspec/changes/hermiq-agent-leaf/tasks.md#task-3-2
     */
    private function applyCaps(mixed $value, array $caps): mixed
    {
        $maxLength = $caps['maxLength'] ?? null;
        if (is_int($maxLength) === false || $maxLength <= 0) {
            return $value;
        }

        if (is_string($value) === false) {
            return $value;
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        // Multibyte-safe truncation: a byte-based substr() risks splitting a
        // multi-byte UTF-8 character and corrupting the text sent to the agent.
        return (mb_substr($value, 0, $maxLength).'…');

    }//end applyCaps()
}//end class
