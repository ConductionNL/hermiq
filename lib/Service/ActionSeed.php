<?php
/**
 * Action Seed
 *
 * Reads `lib/actions.seed.json`: the shipped ADR-023 action matrix (the values the
 * administrator's screen resets to) and the plain-language label and description of
 * every seeded action.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * The seeded actions and their labels.
 *
 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md
 */
class ActionSeed {
	/**
	 * The seed file shipped with the app.
	 */
	public const DEFAULT_PATH = __DIR__ . '/../actions.seed.json';

	/**
	 * The decoded seed, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $seed = null;

	/**
	 * The seed file this instance reads.
	 *
	 * @var string
	 */
	private string $path = self::DEFAULT_PATH;

	/**
	 * A reader over another seed file, for tests.
	 *
	 * @param string $path The seed file.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md
	 */
	public static function fromFile(string $path): self {
		$seed = new self();
		$seed->path = $path;

		return $seed;
	}//end fromFile()

	/**
	 * The seeded groups per action, in seed order; empty when the seed cannot be read.
	 *
	 * @return array<string, list<string>>
	 *
	 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md#requirement-an-administrator-edits-the-action-matrix-on-the-tenant-management-page-req-actmx-001
	 */
	public function actions(): array {
		$actions = [];
		foreach ((array)($this->seed()['actions'] ?? []) as $action => $groups) {
			if (is_string($action) === true && is_array($groups) === true) {
				$actions[$action] = array_values(array_filter($groups, static fn (mixed $group): bool => is_string($group) === true && $group !== ''));
			}
		}

		return $actions;
	}//end actions()

	/**
	 * The English label and description of an action; the action name itself when the seed names none.
	 *
	 * @param string $action The action.
	 *
	 * @return array{label: string, description: string}
	 *
	 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md#requirement-an-administrator-edits-the-action-matrix-on-the-tenant-management-page-req-actmx-001
	 */
	public function label(string $action): array {
		$entry = ((array)($this->seed()['labels'] ?? []))[$action] ?? null;
		if (is_array($entry) === false) {
			return ['label' => $action, 'description' => ''];
		}

		$result = ['label' => $action, 'description' => ''];
		$label = $entry['label'] ?? null;
		if (is_string($label) === true && $label !== '') {
			$result['label'] = $label;
		}

		$description = $entry['description'] ?? null;
		if (is_string($description) === true) {
			$result['description'] = $description;
		}

		return $result;
	}//end label()

	/**
	 * The decoded seed file, or an empty array when it is missing or malformed.
	 *
	 * @return array<string, mixed>
	 */
	private function seed(): array {
		if ($this->seed !== null) {
			return $this->seed;
		}

		$this->seed = [];
		if (is_readable($this->path) === false) {
			return $this->seed;
		}

		$raw = file_get_contents($this->path);
		$decoded = null;
		if ($raw !== false) {
			$decoded = json_decode($raw, associative: true);
		}

		if (is_array($decoded) === true) {
			$this->seed = $decoded;
		}

		return $this->seed;
	}//end seed()
}//end class
