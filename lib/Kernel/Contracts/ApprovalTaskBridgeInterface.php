<?php

/**
 * Contract of ApprovalTaskBridge
 *
 * The public surface of ApprovalTaskBridge that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of ApprovalTaskBridge.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalTaskBridgeInterface {

	/**
	 * Ensure the OpenRegister mirror task for a pending Approval, returning
	 * its uuid (the caller persists it onto the Approval as `taskUuid`).
	 *
	 * Idempotent through the caller's guard: an Approval already carrying a
	 * `taskUuid` is returned as-is without touching the task surface. Never
	 * throws: a missing or older task surface logs a warning and returns
	 * null, and the approval write-path proceeds unmirrored.
	 *
	 * @param ObjectEntity $approval The just-persisted pending Approval.
	 *
	 * @return string|null The mirror task's uuid, or null when unmirrored.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
	 */
	public function ensureTaskFor(ObjectEntity $approval): ?string;

	/**
	 * Release an Approval's mirror after a decision on hermiq's own surface:
	 * the task is terminated as moot so it leaves every OpenRegister inbox.
	 *
	 * Idempotent both ways: no `taskUuid` is a no-op, and OpenRegister's
	 * `terminateAsMoot()` returns an already-terminal task untouched, which
	 * is exactly the case when the decision CAME from the task (design.md
	 * Decision 1). Never throws.
	 *
	 * @param ObjectEntity $approval The decided Approval.
	 * @param string $decision The decision applied (`approved`|`denied`).
	 * @param string $deciderUid The deciding user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-hermiq-decision-releases-the-mirror
	 */
	public function releaseFor(ObjectEntity $approval, string $decision, string $deciderUid): void;
}//end interface
