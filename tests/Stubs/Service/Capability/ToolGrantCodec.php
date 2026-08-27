<?php

/**
 * DECLARATION-ONLY stub for OpenRegister's ToolGrantCodec.
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
class ToolGrantCodec {

	/**
	 * Refuses: this stub exists for static analysis only.
	 *
	 * @return never
	 *
	 * @throws LogicException Always.
	 */
	private static function refuse(): never {
		throw new LogicException(
			'OCA\OpenRegister\Service\Capability\ToolGrantCodec was resolved from hermiq\'s ANALYSIS STUB '
			. 'rather than from OpenRegister. The real class ships with OpenRegister (ADR-099 §5).'
		);
	}

	/**
	 * @param string $id   The tool id.
	 * @param array  $args The argument constraints.
	 *
	 * @return string|array The entry.
	 */
	public static function entryFor(string $id, array $args): string|array {
		self::refuse();
	}

	/**
	 * @param string|array $entry The entry.
	 *
	 * @return string The grant string.
	 */
	public static function grantStringFor(string|array $entry): string {
		self::refuse();
	}

	/**
	 * @param string $grant The grant string.
	 *
	 * @return array The coordinates.
	 */
	public static function coordinatesFor(string $grant): array {
		self::refuse();
	}

	/**
	 * @param string $query The constraint query.
	 *
	 * @return array The parsed constraints.
	 */
	public static function parseConstraints(string $query): array {
		self::refuse();
	}

	/**
	 * @param mixed $entry The candidate entry.
	 *
	 * @return string|array|null The sanitised entry, or null.
	 */
	public static function sanitiseEntry(mixed $entry): string|array|null {
		self::refuse();
	}
}
