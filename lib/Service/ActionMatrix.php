<?php

/**
 * Action Matrix
 *
 * The admin-configured ADR-023 action matrix, stored in IAppConfig as JSON under the
 * app's `actions` key: action name => groups allowed to invoke it. Reads normalise
 * (non-array entries and non-string or empty group names are discarded); writes
 * normalise to the same shape. An action without an entry allows ["admin"] only —
 * first-install safe posture.
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
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the action → allowed-groups matrix.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class ActionMatrix {
	private const CONFIG_KEY = 'actions';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig IAppConfig for reading/writing the matrix
	 */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Get the list of groups allowed to perform the action.
	 *
	 * Returns the matrix entry for the action, or ["admin"] as the safe
	 * default when the action is not in the matrix.
	 *
	 * @param string $action Dot-separated action name.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function getAllowedGroups(string $action): array {
		$matrix = $this->getMatrix();
		return $matrix[$action] ?? ['admin'];
	}//end getAllowedGroups()

	/**
	 * Get the full action-to-groups matrix.
	 *
	 * Reads the JSON-encoded matrix from IAppConfig. Missing or malformed
	 * config returns an empty array (default-deny — admin-only for every
	 * action since getAllowedGroups falls back to ["admin"]).
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function getMatrix(): array {
		// Normalize: discard any non-array values + any non-string group entries.
		$matrix = [];
		foreach ($this->storedMatrix() as $action => $groups) {
			if (is_string($action) === true && is_array($groups) === true) {
				$matrix[$action] = $this->groupsOf(groups: $groups);
			}
		}

		return $matrix;
	}//end getMatrix()

	/**
	 * The stored matrix as decoded, or empty when it is unreadable or not an object.
	 *
	 * @return array<array-key, mixed>
	 */
	private function storedMatrix(): array {
		$json = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '{}');

		try {
			$decoded = json_decode($json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return [];
		}

		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end storedMatrix()

	/**
	 * An action's groups: each non-empty string once, in first-seen order.
	 *
	 * @param array<array-key, mixed> $groups The stored group entries.
	 *
	 * @return list<string>
	 */
	private function groupsOf(array $groups): array {
		$clean = [];
		foreach ($groups as $group) {
			if (is_string($group) === true && $group !== '') {
				$clean[] = $group;
			}
		}

		return array_values(array_unique($clean));
	}//end groupsOf()

	/**
	 * Set the full action-to-groups matrix.
	 *
	 * Caller MUST enforce admin-only before invoking (this method does not
	 * gate writes — it's called from an admin-only settings endpoint).
	 *
	 * @param array<string, array<int, string>> $matrix The new matrix.
	 *
	 * @return void
	 *
	 * @throws \JsonException When the matrix cannot be encoded.
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function setMatrix(array $matrix): void {
		// Normalize on write — same shape as getMatrix returns.
		$normalized = [];
		foreach ($matrix as $action => $groups) {
			$clean = [];
			foreach ($groups as $g) {
				if ($g !== '') {
					$clean[] = $g;
				}
			}

			$normalized[$action] = array_values(array_unique($clean));
		}

		$json = json_encode($normalized, flags: JSON_THROW_ON_ERROR);
		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $json);

	}//end setMatrix()

	/**
	 * List all action keys currently in the matrix.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function getActions(): array {
		return array_keys($this->getMatrix());
	}//end getActions()
}//end class
