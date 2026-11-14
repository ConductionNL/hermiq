<?php

/**
 * Contract of AiOversightAccess
 *
 * The public surface of AiOversightAccess that other components call, so they depend
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
 * Contract of AiOversightAccess.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiOversightAccessInterface {

	/**
	 * Nextcloud group whose members read every advisory Approval.
	 *
	 * The same id stands in the Approval schema's read rule in
	 * hermiq_register.json; the two must stay equal.
	 *
	 * @var string
	 */
	public const OVERSIGHT_GROUP = 'ai-oversight';

	/**
	 * Whether the user holds the AI-oversight role or is an administrator.
	 *
	 * @param string $userId The user.
	 *
	 * @return boolean True for an admin or a member of the oversight group.
	 *
	 * @spec openspec/specs/ai-oversight/spec.md
	 */
	public function hasOversightRole(string $userId): bool;

	/**
	 * Whether the signed-in user may read the advisory Approvals about a subject.
	 *
	 * Fails closed: a subject that is not an OpenRegister uuid, cannot be found,
	 * or whose lookup throws, grants nothing to a user without the role.
	 *
	 * @param string $userId The signed-in user.
	 * @param string $subjectId The subject's id in its origin app.
	 *
	 * @return boolean True when the user may read them.
	 *
	 * @spec openspec/specs/ai-oversight/spec.md
	 */
	public function maySeeSubject(string $userId, string $subjectId): bool;
}//end interface
