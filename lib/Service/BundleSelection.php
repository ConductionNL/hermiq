<?php

/**
 * Hermiq BundleSelection
 *
 * The entries of one kind a bundle write accepts, and the ones it drops with their reason.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Closure;

/**
 * Accepts entries in order up to the kind's cap, once per safe name.
 *
 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
final class BundleSelection {

	/**
	 * The accepted entries, each with its safe name.
	 *
	 * @var list<array{name: string, item: array<string, mixed>}>
	 */
	public array $accepted = [];

	/**
	 * The dropped entries with their reason.
	 *
	 * @var list<array{name: string, reason: string}>
	 */
	public array $dropped = [];

	/**
	 * The safe names already taken.
	 *
	 * @var array<string, true>
	 */
	private array $usedNames = [];

	/**
	 * Start a selection.
	 *
	 * @param BundleKind $kind   The entry kind.
	 * @param Closure    $reject Logs a rejected entry: reject(what, why).
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function __construct(
		private readonly BundleKind $kind,
		private readonly Closure $reject,
	) {
	}//end __construct()

	/**
	 * Accept one entry, or drop it with its reason.
	 *
	 * @param array<string, mixed> $item The entry payload.
	 * @param string|null          $name Its safe name, or null when its name is not valid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function offer(array $item, ?string $name): void {
		$rawName = (string)($item['name'] ?? '');
		$drop = $this->refusal(rawName: $rawName, name: $name);
		if ($drop !== null) {
			$this->dropped[] = $drop;
			return;
		}

		$this->usedNames[(string)$name] = true;
		$this->accepted[] = ['name' => (string)$name, 'item' => $item];
	}//end offer()

	/**
	 * Why an entry is dropped (logged), or null when it is accepted.
	 *
	 * @param string      $rawName The entry's name as given.
	 * @param string|null $name    Its safe name, or null when not valid.
	 *
	 * @return array{name: string, reason: string}|null
	 */
	private function refusal(string $rawName, ?string $name): ?array {
		if ($name === null) {
			($this->reject)($rawName, 'not a valid bundled ' . $this->kind->name . ' name');
			return ['name' => $rawName, 'reason' => 'invalid_name'];
		}

		return $this->capOrDuplicate(rawName: $rawName, name: $name);
	}//end refusal()

	/**
	 * The drop of a valid name once the cap is reached or its path is taken (logged), or null.
	 *
	 * @param string $rawName The entry's name as given.
	 * @param string $name    Its safe name.
	 *
	 * @return array{name: string, reason: string}|null
	 */
	private function capOrDuplicate(string $rawName, string $name): ?array {
		if (count($this->accepted) >= $this->kind->cap) {
			($this->reject)($name, 'bundle ' . $this->kind->name . ' cap of ' . $this->kind->cap . ' reached');
			return ['name' => $name, 'reason' => 'cap_reached'];
		}

		// Sanitising a name to a safe path component can map two different
		// items onto one path. Writing both would silently overwrite the
		// first — a dropped item wearing a successful publish. Reported.
		if (isset($this->usedNames[$name]) === true) {
			($this->reject)($rawName, $this->kind->name . ' path name "' . $name . '" already taken in this bundle');
			return ['name' => $rawName, 'reason' => 'duplicate_directory_name'];
		}

		return null;
	}//end capOrDuplicate()
}//end class
