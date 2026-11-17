<?php

/**
 * Contract of ActingUserScope
 *
 * The public surface of ActingUserScope that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of ActingUserScope.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActingUserScopeInterface {

	/**
	 * Run the work as the user, restoring the prior session user afterwards.
	 *
	 * @param string $uid The acting user id.
	 * @param callable $work The work; its return value is returned.
	 *
	 * @return mixed What the work returned.
	 *
	 * @throws RuntimeException When the user does not exist.
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function run(string $uid, callable $work): mixed;
}//end interface
