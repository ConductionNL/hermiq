<?php

/**
 * DECLARATION-ONLY stub for OpenRegister's ToolGrantResolutionException.
 *
 * 🔴 See ToolGrantResolver.php in this directory for the full argument. This file
 * exists so PHPStan and Psalm can RESOLVE the class in the `php-quality` job,
 * which does not check out OpenRegister. At test time `tests/bootstrap.php` maps
 * the REAL source under a longer PSR-4 prefix, which wins.
 *
 * This one is CONSTRUCTIBLE, unlike its siblings, and deliberately so: `ToolLoop`
 * throws it and `ToolLoopTest` catches it, so a throwing constructor would break a
 * test that never touches the grammar. It carries the grants it was given and
 * nothing else — there is no logic here to get wrong.
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

use RuntimeException;

/**
 * Analysis-only stand-in for the resolution failure.
 */
class ToolGrantResolutionException extends RuntimeException {

	/**
	 * The grants that resolved to nothing.
	 *
	 * @var array
	 */
	private array $grants;

	/**
	 * Constructor.
	 *
	 * @param array $grants The grants that resolved to no tool at all.
	 */
	public function __construct(array $grants) {
		$this->grants = $grants;

		parent::__construct(
			'The agent\'s tool grants name at least one tool but resolve to none.'
		);
	}

	/**
	 * The grants that resolved to nothing.
	 *
	 * @return array The grants.
	 */
	public function getGrants(): array {
		return $this->grants;
	}
}
