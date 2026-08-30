<?php

/**
 * Hermiq AgentTemplateSerializer.
 *
 * Converts an AgentTemplate's portable fields to a JSON package string and back
 * (agent-template-gallery), the agent-level analogue of SkillSerializer. Unlike a Skill (a
 * markdown body + YAML frontmatter), an AgentTemplate has no natural "body" — only
 * structured fields (provider, model, tools array, skill refs, schedule hint) — so a plain
 * JSON package is the honest fit; no bespoke frontmatter parser is needed.
 *
 * The package deliberately carries ONLY the portable, org-agnostic fields (name,
 * description, category, systemPrompt, suggestedProvider, suggestedModel, tools, skillRefs,
 * suggestedSchedule, version) — never the instance-local lifecycle fields (state, source,
 * quarantineReason, scanReport, createdBy), which are assigned by the IMPORTING side, exactly
 * as SkillSerializer's package never carries Skill.state/source/installedOn/createdBy.
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
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-2-agenttemplateserializer-json-package-deserialisation
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use JsonException;

/**
 * Lossy-by-design (portable-fields-only) JSON package (de)serialiser for AgentTemplate.
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-2-agenttemplateserializer-json-package-deserialisation
 */
class AgentTemplateSerializer {
	/**
	 * Serialise a template's portable fields into a JSON package string.
	 *
	 * Only the AgentTemplate schema's PORTABLE fields are emitted — never state, source,
	 * quarantineReason, scanReport, or createdBy (instance-local lifecycle assigned by the
	 * importing side), and never any tenant/secret field (the schema declares none by
	 * construction — agent-template-gallery Decision 1).
	 *
	 * @param array<string, mixed> $template The template data (portable fields).
	 *
	 * @return string The JSON package string.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function toPackage(array $template): string {
		$portable = [
			'name' => (string)($template['name'] ?? ''),
			'description' => (string)($template['description'] ?? ''),
			'category' => (string)($template['category'] ?? ''),
			'systemPrompt' => (string)($template['systemPrompt'] ?? ''),
			'suggestedProvider' => (string)($template['suggestedProvider'] ?? ''),
			'suggestedModel' => (string)($template['suggestedModel'] ?? ''),
			'tools' => $this->stringList(raw: ($template['tools'] ?? [])),
			'skillRefs' => $this->skillRefs(raw: ($template['skillRefs'] ?? [])),
			'suggestedSchedule' => $this->scheduleHint(raw: ($template['suggestedSchedule'] ?? [])),
			'version' => (string)($template['version'] ?? '0.1.0'),
		];

		try {
			return json_encode($portable, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			// Every value above is already scalar/array-of-scalars — this branch is
			// unreachable in practice, but never let a serialisation failure bubble as a
			// fatal; an empty-fields package is safer than a 500.
			return '{}';
		}

	}//end toPackage()

	/**
	 * Parse a JSON package string into the template's portable fields, tolerant of
	 * missing optional fields (mirrors SkillSerializer::fromPackage()'s empty-string/
	 * empty-array defaults — a package from an older/partial template MUST still import).
	 *
	 * @param string $package The JSON package string.
	 *
	 * @return array<string, mixed> The parsed { name, description, category, systemPrompt,
	 *                              suggestedProvider, suggestedModel, tools, skillRefs,
	 *                              suggestedSchedule, version }.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function fromPackage(string $package): array {
		$decoded = [];
		try {
			$parsed = json_decode($package, associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
			if (is_array($parsed) === true) {
				$decoded = $parsed;
			}
		} catch (JsonException $e) {
			// Malformed/empty input — fall through to tolerant defaults below rather
			// than throwing; the caller (AgentTemplateService::importPackage()) still
			// gets a well-shaped, if empty, template.
			$decoded = [];
		}

		return [
			'name' => (string)($decoded['name'] ?? ''),
			'description' => (string)($decoded['description'] ?? ''),
			'category' => (string)($decoded['category'] ?? ''),
			'systemPrompt' => (string)($decoded['systemPrompt'] ?? ''),
			'suggestedProvider' => (string)($decoded['suggestedProvider'] ?? ''),
			'suggestedModel' => (string)($decoded['suggestedModel'] ?? ''),
			'tools' => $this->stringList(raw: ($decoded['tools'] ?? [])),
			'skillRefs' => $this->skillRefs(raw: ($decoded['skillRefs'] ?? [])),
			'suggestedSchedule' => $this->scheduleHint(raw: ($decoded['suggestedSchedule'] ?? [])),
			'version' => (string)($decoded['version'] ?? '0.1.0'),
		];

	}//end fromPackage()

	/**
	 * Normalise a value into a list of strings (tolerant of a missing/malformed input).
	 *
	 * @param mixed $raw The candidate value.
	 *
	 * @return array<int, string> The normalised list.
	 */
	private function stringList(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		return array_values(array_map('strval', $raw));
	}//end stringList()

	/**
	 * Normalise a value into a list of `{skillId, name}` skill-ref pairs.
	 *
	 * @param mixed $raw The candidate value.
	 *
	 * @return array<int, array{skillId: string, name: string}> The normalised list.
	 */
	private function skillRefs(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$out[] = [
				'skillId' => (string)($entry['skillId'] ?? ''),
				'name' => (string)($entry['name'] ?? ''),
			];
		}

		return $out;
	}//end skillRefs()

	/**
	 * Normalise a value into the suggested-schedule hint shape (tolerant of a missing/
	 * malformed input — an empty hint is a valid "no suggestion" state).
	 *
	 * @param mixed $raw The candidate value.
	 *
	 * @return array<string, mixed> The normalised hint.
	 */
	private function scheduleHint(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$hint = [];
		if (isset($raw['kind']) === true) {
			$hint['kind'] = (string)$raw['kind'];
		}

		if (isset($raw['cronExpr']) === true) {
			$hint['cronExpr'] = (string)$raw['cronExpr'];
		}

		if (isset($raw['intervalMinutes']) === true) {
			$hint['intervalMinutes'] = (int)$raw['intervalMinutes'];
		}

		if (isset($raw['deliver']) === true) {
			$hint['deliver'] = (string)$raw['deliver'];
		}

		return $hint;
	}//end scheduleHint()
}//end class
