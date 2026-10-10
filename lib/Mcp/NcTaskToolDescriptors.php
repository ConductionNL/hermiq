<?php

/**
 * Hermiq Nextcloud Tasks tool descriptors (tools-nextcloud-tasks).
 *
 * Three tools over the acting user's CalDAV task lists, the lists the Tasks app
 * shows. Held outside `HermiqToolProvider` like the other descriptor lists; the
 * provider stays the one `IMcpToolProvider` and merges these in.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-task-tools-are-default-denied-never-delete-and-record-identity-without-content-req-nctask-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Descriptors of the task tools.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-task-tools-are-default-denied-never-delete-and-record-identity-without-content-req-nctask-004
 */
final class NcTaskToolDescriptors {

	public const LIST_TASKS = Application::APP_ID . '.listTasks';

	public const CREATE_TASK = Application::APP_ID . '.createTask';

	public const COMPLETE_TASK = Application::APP_ID . '.completeTask';

	public const IDS = [self::LIST_TASKS, self::CREATE_TASK, self::COMPLETE_TASK];

	/**
	 * The descriptors, in catalogue order. No tool here deletes a task.
	 *
	 * Create and complete are reach `instance`, not `user`: a list the user owns
	 * can be shared with colleagues, who then see the change. Both are write
	 * classified, so they are default-denied and gated when un-granted.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public const ALL = [
		[
			'id' => self::LIST_TASKS,
			'subject' => 'task',
			'action' => 'list',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'List tasks',
			'description' => 'List tasks from your task lists in the Tasks app: open, completed or all, with due date, '
				. 'status and list. Returns at most 50 tasks.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'status' => [
						'type' => 'string',
						'enum' => ['open', 'completed', 'all'],
						'description' => 'Which tasks (default open).',
					],
					'dueBefore' => ['type' => 'string', 'description' => 'Only tasks due before this ISO-8601 date.'],
					'taskListUri' => ['type' => 'string', 'description' => 'Only this task list.'],
					'limit' => ['type' => 'integer', 'description' => 'At most this many tasks (default and maximum 50).'],
				],
				'required' => [],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
		[
			'id' => self::CREATE_TASK,
			'subject' => 'task',
			'action' => 'create',
			'reach' => ToolReachResolver::REACH_INSTANCE,
			'name' => 'Create task',
			'description' => 'Creates a task in one of your own task lists. People you share that list with will see it. '
				. 'Lists shared with you by someone else are never written. The task is marked as written by this agent.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'summary' => ['type' => 'string', 'description' => 'What the task is.'],
					'due' => ['type' => 'string', 'description' => 'Optional due date or date-time, ISO-8601.'],
					'description' => ['type' => 'string', 'description' => 'Optional notes.'],
					'priority' => ['type' => 'integer', 'description' => 'Optional priority, 1 (high) to 9 (low).'],
					'taskListUri' => ['type' => 'string', 'description' => 'Optional task list (default: your first own list).'],
				],
				'required' => ['summary'],
			],
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => false,
			'scope' => 'create',
		],
		[
			'id' => self::COMPLETE_TASK,
			'subject' => 'task',
			'action' => 'complete',
			'reach' => ToolReachResolver::REACH_INSTANCE,
			'name' => 'Complete task',
			'description' => 'Marks one of your tasks as done. People you share that list with will see it. Everything '
				. 'else in the task stays as you wrote it, and you can reopen it in the Tasks app.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'uid' => ['type' => 'string', 'description' => 'The task uid, from listTasks.'],
					'taskListUri' => ['type' => 'string', 'description' => 'Optional task list the task is in.'],
				],
				'required' => ['uid'],
			],
			// Not destructive: nothing is lost and one click reopens the task.
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => false,
			'scope' => 'update',
		],
	];

}//end class
