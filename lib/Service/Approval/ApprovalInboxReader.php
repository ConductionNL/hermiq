<?php
/**
 * Hermiq ApprovalInboxReader.
 *
 * Who reads the approval inbox, and the preview builder their records use.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

use OCA\Hermiq\Service\ApprovalPreviewBuilder;

/**
 * The reader of one inbox listing.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
 */
final class ApprovalInboxReader {

	/**
	 * Constructor.
	 *
	 * @param string                      $uid      The reviewer reading the inbox.
	 * @param ApprovalPreviewBuilder|null $previews The preview builder, when available.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly ?ApprovalPreviewBuilder $previews,
	) {
	}//end __construct()
}//end class
