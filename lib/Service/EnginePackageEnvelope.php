<?php

/**
 * Hermiq EnginePackageEnvelope.
 *
 * Reads the package OpenRegister's FederatedConfigService wire format carries.
 * The engine publishes one JSON file holding `{type, version, skills|templates:
 * [{name, package}]}` (signed with a `provenance` block), where the store's own
 * format is the package itself: `hermiq-skill.md` for a skill and the template
 * JSON in `hermiq-agent-template.json`. During the cutover the store reads both,
 * so a repository the engine published shows and installs like one it published.
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
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\GitHubTemplateCatalogServiceInterface;

/**
 * The package inside an engine-format file, or nothing when the file is not one.
 *
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-1
 */
final class EnginePackageEnvelope {

	/**
	 * The engine-format file a skill repository carries instead of `hermiq-skill.md`.
	 *
	 * @var string
	 */
	public const SKILL_FILE = 'hermiq-skill.json';

	/**
	 * Per kind: the engine type id the envelope names and the list its packages sit in.
	 *
	 * @var array<string, array{type: string, list: string}>
	 */
	private const ENVELOPES = [
		GitHubTemplateCatalogServiceInterface::KIND_SKILL => ['type' => 'hermiq.skill', 'list' => 'skills'],
		GitHubTemplateCatalogServiceInterface::KIND_AGENT_TEMPLATE => ['type' => 'hermiq.agent-template', 'list' => 'templates'],
	];

	/**
	 * The first package an engine-format file of this kind carries.
	 *
	 * Null when the text is not JSON, names another type, or holds no package,
	 * so the caller keeps reading the file as its own format.
	 *
	 * @param string $kind     The store kind (KIND_SKILL|KIND_AGENT_TEMPLATE).
	 * @param string $contents The file's decoded text.
	 *
	 * @return string|null The package text, or null when this is no engine envelope of the kind.
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-1
	 */
	public static function packageOf(string $kind, string $contents): ?string {
		$envelope = self::ENVELOPES[$kind] ?? null;
		if ($envelope === null) {
			return null;
		}

		$decoded = json_decode($contents, true);
		if (is_array($decoded) === false || ($decoded['type'] ?? null) !== $envelope['type']) {
			return null;
		}

		$entries = $decoded[$envelope['list']] ?? null;
		if (is_array($entries) === false) {
			return null;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$package = ($entry['package'] ?? null);
			if (is_string($package) === true && trim($package) !== '') {
				return $package;
			}
		}

		return null;
	}//end packageOf()
}//end class
