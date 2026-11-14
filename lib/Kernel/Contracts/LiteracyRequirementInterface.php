<?php

/**
 * Contract of LiteracyRequirement
 *
 * The public surface of LiteracyRequirement that other components call, so they depend
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

use OCA\Hermiq\Service\Literacy\LiteracyRequiredException;
use OCP\IUser;
use RuntimeException;

/**
 * Contract of LiteracyRequirement.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LiteracyRequirementInterface {

	/**
	 * Whether an organisation requires the course.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return bool True when required.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function isRequired(string $organisation): bool;

	/**
	 * Whether a person may administer an organisation: an instance admin, or its owner.
	 *
	 * @param string $organisation The organisation.
	 * @param string $uid The person.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function mayAdminister(string $organisation, string $uid): bool;

	/**
	 * The person's active organisation, '' when none.
	 *
	 * @param string $uid The person.
	 *
	 * @return string The organisation.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function organisationOf(string $uid): string;

	/**
	 * Switch the requirement, as an instance admin or the organisation's owner.
	 *
	 * @param string $organisation The organisation.
	 * @param bool $required Whether the course is required.
	 * @param IUser $actor Who switches it.
	 *
	 * @return bool The stored value.
	 *
	 * @throws RuntimeException When the actor may not administer the organisation.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function setRequired(string $organisation, bool $required, IUser $actor): bool;

	/**
	 * Refuse a person who must finish the course first.
	 *
	 * @param string $uid The person.
	 *
	 * @return void
	 *
	 * @throws LiteracyRequiredException When the organisation requires the course and it is not done.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function assertMayUseAgents(string $uid): void;

	/**
	 * The refusal a person gets instead of a run, or null when they may go ahead:
	 * the course message, its stable code and the course link.
	 *
	 * @param string $uid The person.
	 *
	 * @return array{message: string, errorCode: string, courseUrl: string}|null The refusal.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function refusal(string $uid): ?array;
}//end interface
