<?php

/**
 * DECLARATION-ONLY stub for OpenRegister's ToolGrantSet.
 *
 * 🔴 EVERY BODY THROWS. See ToolGrantResolver.php in this directory for the full
 * argument: this file exists so PHPStan and Psalm can RESOLVE the class in the
 * `php-quality` job, which does not check out OpenRegister. At test time
 * `tests/bootstrap.php` maps the REAL source under a longer PSR-4 prefix, which
 * wins; the throws make an absent real class loud instead of letting a fake
 * answer an authorization question.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Capability;

use LogicException;

/**
 * Analysis-only stand-in. Never executed; every body throws.
 */
class ToolGrantSet {

	/**
	 * Refuses: this stub exists for static analysis only.
	 *
	 * @return never
	 *
	 * @throws LogicException Always.
	 */
	private static function refuse(): never {
		throw new LogicException(
			'OCA\OpenRegister\Service\Capability\ToolGrantSet was resolved from hermiq\'s ANALYSIS STUB '
			. 'rather than from OpenRegister. The real class ships with OpenRegister (ADR-099 §5).'
		);
	}

	/**
	 * @param mixed $stored The stored value.
	 *
	 * @return self The set.
	 */
	public static function fromStored(mixed $stored): self {
		self::refuse();
	}

	/**
	 * @param array $ids The grant strings.
	 *
	 * @return self The set.
	 */
	public static function fromGrantStrings(array $ids): self {
		self::refuse();
	}

	/**
	 * @return boolean Whether the set is empty.
	 */
	public function isEmpty(): bool {
		self::refuse();
	}

	/**
	 * @return array The storable shape.
	 */
	public function toStored(): array {
		self::refuse();
	}

	/**
	 * @return array The grant strings.
	 */
	public function toGrantStrings(): array {
		self::refuse();
	}

	/**
	 * @param string $app     The app.
	 * @param string $subject The subject.
	 * @param string $action  The action.
	 *
	 * @return boolean Whether the grant is present.
	 */
	public function has(string $app, string $subject, string $action): bool {
		self::refuse();
	}

	/**
	 * @param string $app     The app.
	 * @param string $subject The subject.
	 * @param string $action  The action.
	 * @param string $toolId  The tool id.
	 * @param array  $args    The argument constraints.
	 *
	 * @return self The new set.
	 */
	public function with(string $app, string $subject, string $action, string $toolId, array $args = []): self {
		self::refuse();
	}

	/**
	 * @param string $app     The app.
	 * @param string $subject The subject.
	 * @param string $action  The action.
	 *
	 * @return self The new set.
	 */
	public function without(string $app, string $subject, string $action): self {
		self::refuse();
	}

	/**
	 * @return array The tool ids.
	 */
	public function toolIds(): array {
		self::refuse();
	}
}
