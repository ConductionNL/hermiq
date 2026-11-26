<?php

/**
 * Hermiq MirrorTaskRef.
 *
 * An approval's mirror task and the reviewer acting on it.
 *
 * @category ValueObject
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

/**
 * One mirror task and its actor.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-talk-reaction-completes-the-mirror-task
 */
final class MirrorTaskRef {

	/**
	 * Build the reference.
	 *
	 * @param string $uuid  The mirror task's uuid.
	 * @param string $actor The reviewer acting on it.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $actor,
	) {
	}//end __construct()
}//end class
