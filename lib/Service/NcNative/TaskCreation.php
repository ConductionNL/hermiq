<?php

/**
 * Hermiq TaskCreation.
 *
 * One task being created: the own list it goes to, its new uid and the tool's fields.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use OCP\Calendar\ICreateFromString;

/**
 * One task creation in progress; it never leaves TaskWriteService.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
 */
final class TaskCreation {

	/**
	 * The tool's fields: summary, due, description, priority, agentId.
	 *
	 * @var array<string, mixed>
	 */
	public array $fields = [];

	/**
	 * Start the creation.
	 *
	 * @param ICreateFromString $target  The user's own writable task list.
	 * @param string            $taskUid The new task's uid.
	 */
	public function __construct(
		public readonly ICreateFromString $target,
		public readonly string $taskUid,
	) {
	}//end __construct()
}//end class
