<?php

/**
 * Golden tests for ApprovalTaskBridge's mirror payload and its guards.
 *
 * Pins the exact task payload a pending Approval is mirrored with for each reviewer
 * routing (user, group, none) and each title and description shape, and every guard
 * that leaves an approval unmirrored.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Approval
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the mirror payload: every reviewer routing, title and description
 * shape, and every guard that leaves an approval unmirrored.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
 */
class ApprovalTaskBridgeTaskDataGoldenTest extends TestCase {

	/**
	 * Mirror an approval and answer what was imported and what came back.
	 *
	 * @param array<string, mixed> $data The approval payload.
	 * @param string $uuid The approval uuid.
	 * @param string $taskUuid The uuid the imported task carries.
	 *
	 * @return array{0: ?string, 1: array|null}
	 */
	private function mirror(array $data, string $uuid = 'appr-1', string $taskUuid = 'task-1'): array {
		$imported = null;
		$tasks = $this->createMock(TaskService::class);
		$tasks->method('import')->willReturnCallback(
			function (array $data, string $actor) use (&$imported, $taskUuid): Task {
				$imported = [$data, $actor];
				$task = new Task();
				$task->setUuid($taskUuid);
				return $task;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($tasks);

		$approval = new ObjectEntity();
		$approval->setUuid($uuid);
		$approval->setObject($data);

		$result = (new ApprovalTaskBridge($container, $this->createMock(LoggerInterface::class)))->ensureTaskFor($approval);
		return [$result, $imported];
	}//end mirror()

	/**
	 * The exact payload for each reviewer routing and title/description shape.
	 *
	 * @return void
	 */
	public function testThePayloadForEachRouting(): void {
		$metadata = static fn (string $source): array => ['hermiq' => ['approvalUuid' => 'appr-1', 'sourceType' => $source]];

		[$uuid, [$payload, $actor]] = $this->mirror(['status' => 'pending', 'reviewer' => ' bob ', 'agentId' => ' a-1 ', 'prompt' => ' Do it ']);
		$this->assertSame('task-1', $uuid);
		$this->assertSame(ApprovalTaskBridge::REQUESTER, $actor);
		$this->assertSame(
			[
				'title' => 'Hermiq approval: schedule (agent a-1)',
				'description' => "A gated hermiq schedule action awaits your approval.\n\nDo it",
				'state' => 'active',
				'performerType' => 'user',
				'priority' => 'high',
				'assignee' => 'bob',
				'candidateGroups' => null,
				'requester' => ApprovalTaskBridge::REQUESTER,
				'objectUuid' => 'appr-1',
				'metadata' => $metadata('schedule'),
			],
			$payload
		);

		[, [$payload]] = $this->mirror(['status' => 'pending', 'sourceType' => 'webhook', 'reviewer' => 'ops', 'reviewerType' => 'group']);
		$this->assertSame(
			[
				'title' => 'Hermiq approval: webhook',
				'description' => 'A gated hermiq webhook action awaits your approval.',
				'state' => 'enabled',
				'performerType' => 'group',
				'priority' => 'high',
				'assignee' => null,
				'candidateGroups' => ['ops'],
				'requester' => ApprovalTaskBridge::REQUESTER,
				'objectUuid' => 'appr-1',
				'metadata' => $metadata('webhook'),
			],
			$payload
		);

		foreach (['user', 'group'] as $type) {
			[, [$payload]] = $this->mirror(['status' => 'pending', 'reviewer' => '  ', 'reviewerType' => $type]);
			$this->assertSame(['enabled', 'group', null, null], [$payload['state'], $payload['performerType'], $payload['assignee'], $payload['candidateGroups']], $type);
		}
	}//end testThePayloadForEachRouting()

	/**
	 * Each guard that leaves an approval unmirrored, and an existing backlink.
	 *
	 * @return void
	 */
	public function testEachGuard(): void {
		$this->assertSame(['task-0', null], $this->mirror(['status' => 'approved', 'taskUuid' => ' task-0 ']));
		$this->assertSame([null, null], $this->mirror(['status' => 'approved']));
		$this->assertSame([null, null], $this->mirror([]));
		$this->assertSame([null, null], $this->mirror(['status' => 'pending'], ''));
		[$uuid, $imported] = $this->mirror(['status' => 'pending'], 'appr-1', '');
		$this->assertNull($uuid);
		$this->assertNotNull($imported);
	}//end testEachGuard()
}//end class
