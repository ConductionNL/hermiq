<?php

/**
 * DECLARATION-ONLY stub for OpenRegister's ToolGrantResolver.
 *
 * 🔴 EVERY BODY THROWS, AND THAT IS THE POINT.
 *
 * The capability grammar moved to `OCA\OpenRegister\Service\Capability` under
 * ADR-099 §5. Fifteen hermiq test classes exercise it, and most do so for its
 * BEHAVIOUR — the grant grammar, the argument-constraint parser, the waiver
 * matcher. A stub that RETURNED plausible values would make all fifteen validate
 * a fake while reporting green, which is the failure this repo has already paid
 * for once on a three-method facade (`tests/Stubs/Service/Mcp/ToolRegistryFacade.php`
 * drifted by one method and broke a whole matrix leg). This is ~2,400 lines of
 * security-relevant parsing.
 *
 * So this file exists for ONE reader: static analysis. PHPStan (`scanDirectories`)
 * and Psalm (`extraFiles`) need the class to RESOLVE so they can type-check the
 * thirteen hermiq files that call it — the `php-quality` job does not check out
 * OpenRegister, so without this every call site is an unknown-class error and the
 * gate goes quiet on all of them.
 *
 * At TEST time it is never used: `tests/bootstrap.php` registers the REAL source
 * under the longer PSR-4 prefix `OCA\OpenRegister\Service\Capability\`, which wins
 * over the blanket `OCA\OpenRegister\` → `tests/Stubs/` mapping regardless of
 * registration order. If the real source is somehow absent, these throws make that
 * loud instead of letting a fake answer.
 *
 * KEEP THE SIGNATURES IN STEP with OpenRegister's real class. A signature drift is
 * caught by the unit tests, which run against the real source — this file cannot
 * hide one, only fail to describe it.
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
class ToolGrantResolver {

	public const NO_TOOLS_SENTINEL = '__none__';

	public const READ_VERBS = ['search', 'get'];

	public const WRITE_VERBS = ['create', 'update', 'delete'];

	public const CONSTRAINT_OPENER = '?';

	public const WAIVER_FRAGMENT = '#noapproval';

	public const CONSTRAINT_SEPARATOR = '&';

	public const CONSTRAINT_SET_PREFIX = 'in:';

	public const CONSTRAINT_MODE_PIN = 'pin';

	public const CONSTRAINT_MODE_SET = 'set';

	/**
	 * Refuses: this stub exists for static analysis only.
	 *
	 * @return never
	 *
	 * @throws LogicException Always.
	 */
	private static function refuse(): never {
		throw new LogicException(
			'OCA\OpenRegister\Service\Capability\ToolGrantResolver was resolved from hermiq\'s '
			. 'ANALYSIS STUB rather than from OpenRegister. The real class ships with OpenRegister '
			. '(ADR-099 §5); tests/bootstrap.php maps it from ../openregister/lib/Service/Capability. '
			. 'Refusing rather than answering, because a stubbed grant grammar would make every '
			. 'authorization test pass against a fake.'
		);
	}

	/**
	 * @param array $grants  The grant entries.
	 * @param array $catalog The tool catalogue.
	 *
	 * @return array The resolved tools.
	 */
	public function resolve(array $grants, array $catalog): array {
		self::refuse();
	}

	/**
	 * @param array $grants The grant entries.
	 *
	 * @return boolean Whether the grants explicitly say "no tools".
	 */
	public function isExplicitNoTools(array $grants): bool {
		self::refuse();
	}

	/**
	 * @param array $grants        The grant entries.
	 * @param array $resolvedTools What they resolved to.
	 *
	 * @return boolean Whether they named tools but matched none.
	 */
	public function resolvesToNothing(array $grants, array $resolvedTools): bool {
		self::refuse();
	}

	/**
	 * @param array $grants The grant entries.
	 *
	 * @return array The base tool ids.
	 */
	public function baseToolIds(array $grants): array {
		self::refuse();
	}

	/**
	 * @param array $grants The grant entries.
	 *
	 * @return array The argument constraints per tool.
	 */
	public function argumentConstraints(array $grants): array {
		self::refuse();
	}

	/**
	 * @param array $constraintSets The constraint sets.
	 * @param array $arguments      The invocation arguments.
	 *
	 * @return array|null The violation, or null.
	 */
	public static function violationFor(array $constraintSets, array $arguments): ?array {
		self::refuse();
	}

	/**
	 * @param array $grants The grant entries.
	 *
	 * @return array The waived constraint sets.
	 */
	public function waivedConstraintSets(array $grants): array {
		self::refuse();
	}

	/**
	 * @param array  $waivedSets The waived sets.
	 * @param string $toolId     The tool id.
	 * @param array  $arguments  The invocation arguments.
	 *
	 * @return boolean Whether the invocation is waived.
	 */
	public static function waives(array $waivedSets, string $toolId, array $arguments): bool {
		self::refuse();
	}

	/**
	 * @param array $grants The grant entries.
	 *
	 * @return boolean Whether a wildcard grant is present.
	 */
	public function hasWildcardGrant(array $grants): bool {
		self::refuse();
	}

	/**
	 * @param string     $id         The tool id.
	 * @param array|null $descriptor The tool descriptor.
	 *
	 * @return boolean Whether the tool writes or destroys.
	 */
	public static function isWriteOrDestructive(string $id, ?array $descriptor = null): bool {
		self::refuse();
	}

	/**
	 * @param string     $id         The tool id.
	 * @param array|null $descriptor The tool descriptor.
	 *
	 * @return boolean Whether the tool needs an explicit grant.
	 */
	public static function requiresGrant(string $id, ?array $descriptor = null): bool {
		self::refuse();
	}
}
