<?php

/**
 * Contract of SeedCustodyService
 *
 * The public surface of SeedCustodyService that other components call, so they depend
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

/**
 * Contract of SeedCustodyService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SeedCustodyServiceInterface {

	/**
	 * Whether $uid may act as the owner of an object whose stored owner is $owner.
	 *
	 * True when $uid IS the owner, or when the object is system-seeded
	 * (`__system__`) and $uid is an instance admin (seed custodianship — see the
	 * class docblock). Everything else is a mismatch and MUST 404 upstream
	 * (never 403) so existence is not confirmed to a non-owner.
	 *
	 * @param string|null $owner The object's stored owner uid (null-safe).
	 * @param string $uid The requesting user's uid.
	 *
	 * @return bool True when the caller may act as the owner.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
	 */
	public function actsAsOwner(?string $owner, string $uid): bool;
}//end interface
