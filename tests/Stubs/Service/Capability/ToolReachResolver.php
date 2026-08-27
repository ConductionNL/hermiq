<?php

/**
 * DECLARATION-ONLY stub for OpenRegister's ToolReachResolver.
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
class ToolReachResolver {

	public const REACH_SELF = 'self';

	public const REACH_USER = 'user';

	public const REACH_INSTANCE = 'instance';

	public const REACH_EXTERNAL = 'external';

	public const ORDER = [self::REACH_SELF, self::REACH_USER, self::REACH_INSTANCE, self::REACH_EXTERNAL];

	public const REACH_KEY = 'reach';

	public const READ_VERBS = ['search', 'get'];

	/**
	 * Refuses: this stub exists for static analysis only.
	 *
	 * @return never
	 *
	 * @throws LogicException Always.
	 */
	private static function refuse(): never {
		throw new LogicException(
			'OCA\OpenRegister\Service\Capability\ToolReachResolver was resolved from hermiq\'s ANALYSIS STUB '
			. 'rather than from OpenRegister. The real class ships with OpenRegister (ADR-099 §5).'
		);
	}

	/**
	 * @param string     $toolId     The tool id.
	 * @param array|null $descriptor The descriptor.
	 *
	 * @return string The reach.
	 */
	public static function resolve(string $toolId, ?array $descriptor = null): string {
		self::refuse();
	}

	/**
	 * @param string $reach The reach.
	 *
	 * @return integer Its rank.
	 */
	public static function rank(string $reach): int {
		self::refuse();
	}

	/**
	 * @param string $reach     The reach.
	 * @param string $threshold The threshold.
	 *
	 * @return boolean Whether it meets the threshold.
	 */
	public static function atLeast(string $reach, string $threshold): bool {
		self::refuse();
	}

	/**
	 * @param string $left  One reach.
	 * @param string $right The other.
	 *
	 * @return string The greater.
	 */
	public static function max(string $left, string $right): string {
		self::refuse();
	}
}
