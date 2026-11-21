<?php

/**
 * Contract of TalkApprovalBinding
 *
 * The public surface of TalkApprovalBinding that other components call, so they depend
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
 * Contract of TalkApprovalBinding.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkApprovalBindingInterface {

	/**
	 * Find the approval a reacted-to message decides.
	 *
	 * The register cannot express uniqueness on `talkMessageId`, so more than
	 * one row MAY match; resolution is deterministic (most recent wins) rather
	 * than assuming a single result.
	 *
	 * @param string $messageId The reacted-to message id.
	 *
	 * @return ObjectEntity|null The approval, or null when the message carries none.
	 *
	 * @spec openspec/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
	 */
	public function findByMessageId(string $messageId): ?ObjectEntity;

	/**
	 * Record how a decision arrived, for the audit record.
	 *
	 * @param string $approvalUuid The decided approval.
	 * @param string $via The surface — `inbox` or `reaction`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
	 */
	public function recordDecidedVia(string $approvalUuid, string $via): void;
}//end interface
