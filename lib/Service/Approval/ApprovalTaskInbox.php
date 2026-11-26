<?php
/**
 * Hermiq ApprovalTaskInbox.
 *
 * Reads which Approvals are waiting for a reviewer from OpenRegister's task
 * inbox: the mirror tasks ApprovalTaskBridge creates are routed to the
 * reviewer (assignee) or the reviewer group (candidate group), and the task
 * inbox answers "what is waiting for me" for both in one place.
 *
 * Like ApprovalTaskBridge, the task surface is resolved from the container by
 * FQN string at call time, never constructor-injected: an older OpenRegister
 * without it answers null, and the caller keeps its own pending query.
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

use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxSelection;
use OCA\OpenRegister\Service\Task\TaskInboxPage;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the Approval uuids whose open mirror task is routed to a reviewer.
 *
 * Not final: ApprovalInbox's unit tests mock it.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
 */
class ApprovalTaskInbox {

	/**
	 * FQN of OpenRegister's task inbox, resolved lazily.
	 *
	 * @var string
	 */
	public const INBOX_SERVICE_CLASS = 'OCA\\OpenRegister\\Service\\Task\\TaskInboxService';

	/**
	 * Rows read per inbox page.
	 *
	 * @var integer
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Pages read per scope at most, so a runaway inbox cannot stall a request.
	 *
	 * @var integer
	 */
	public const MAX_PAGES = 10;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container    Lazy TaskInboxService resolution.
	 * @param IUserManager       $userManager  Resolves the reviewer for their groups.
	 * @param IGroupManager      $groupManager The reviewer's group ids, for pooled tasks.
	 * @param LoggerInterface    $logger       Why the task inbox could not be read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The Approval uuids whose open mirror task is assigned to the reviewer or
	 * offered to a group they are in, in the task inbox's order.
	 *
	 * Only tasks hermiq's approval gate requested count: any other task in the
	 * reviewer's inbox is not an Approval.
	 *
	 * @param string $uid The reviewer's uid.
	 *
	 * @return array<int, array{approvalUuid: string, taskUuid: string}>|null The routed approvals,
	 *                                                                        or null when the task inbox cannot be read.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-inbox-reads-pending-work-from-the-openregister-task-inbox
	 */
	public function routedApprovals(string $uid): ?array {
		if ($uid === '') {
			return [];
		}

		$service = $this->inboxService();
		if ($service === null) {
			return null;
		}

		try {
			$groupIds = $this->groupIdsOf(uid: $uid);
			$routed = [];
			foreach ([TaskInboxCriteria::SCOPE_ASSIGNED, TaskInboxCriteria::SCOPE_POOLED] as $scope) {
				$criteria = new TaskInboxCriteria(
					audience: new TaskInboxAudience(uid: $uid, groupIds: $groupIds, isAdmin: false, scope: $scope),
					selection: new TaskInboxSelection(filter: new TaskInboxFilter(isTerminal: false))
				);
				$routed = $this->collect(service: $service, criteria: $criteria, routed: $routed);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq approval inbox could not read the OpenRegister task inbox: ' . $e->getMessage(),
				['exception' => $e]
			);

			return null;
		}

		return array_values($routed);
	}//end routedApprovals()

	/**
	 * Read every page of one scope and add its approval rows.
	 *
	 * @param TaskInboxService                                             $service  The task inbox.
	 * @param TaskInboxCriteria                                            $criteria The scope's query.
	 * @param array<string, array{approvalUuid: string, taskUuid: string}> $routed   The rows so far, by approval uuid.
	 *
	 * @return array<string, array{approvalUuid: string, taskUuid: string}> The rows, by approval uuid.
	 */
	private function collect(TaskInboxService $service, TaskInboxCriteria $criteria, array $routed): array {
		for ($pageIndex = 0; $pageIndex < self::MAX_PAGES; $pageIndex++) {
			$page = $service->inbox(criteria: $criteria, page: new TaskInboxPage(limit: self::PAGE_SIZE, offset: ($pageIndex * self::PAGE_SIZE)));
			$rows = (array)($page['results'] ?? []);
			foreach ($rows as $row) {
				$entry = self::approvalOf(row: (array)$row);
				if ($entry !== null && isset($routed[$entry['approvalUuid']]) === false) {
					$routed[$entry['approvalUuid']] = $entry;
				}
			}

			$seen = (($pageIndex + 1) * self::PAGE_SIZE);
			if (count($rows) < self::PAGE_SIZE || $seen >= (int)($page['total'] ?? 0)) {
				break;
			}
		}

		return $routed;
	}//end collect()

	/**
	 * The approval a task row mirrors, when hermiq's approval gate requested it.
	 *
	 * @param array<string, mixed> $row One task inbox row.
	 *
	 * @return array{approvalUuid: string, taskUuid: string}|null The approval and its task, or null for a foreign task.
	 */
	private static function approvalOf(array $row): ?array {
		if ((string)($row['requester'] ?? '') !== ApprovalTaskBridge::REQUESTER) {
			return null;
		}

		$metadata = (array)($row['metadata'] ?? []);
		$hermiq = (array)($metadata['hermiq'] ?? []);
		$approvalUuid = (string)($hermiq['approvalUuid'] ?? ($row['objectUuid'] ?? ''));
		if ($approvalUuid === '') {
			return null;
		}

		return ['approvalUuid' => $approvalUuid, 'taskUuid' => (string)($row['uuid'] ?? '')];
	}//end approvalOf()

	/**
	 * The reviewer's group ids, for the tasks offered to a group.
	 *
	 * @param string $uid The reviewer's uid.
	 *
	 * @return array<int, string> The group ids; none for an unknown user.
	 */
	private function groupIdsOf(string $uid): array {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}

		return array_values(array_map('strval', $this->groupManager->getUserGroupIds($user)));
	}//end groupIdsOf()

	/**
	 * OpenRegister's task inbox, or null when this OpenRegister does not ship it.
	 *
	 * @return TaskInboxService|null The task inbox, or null when unavailable.
	 */
	private function inboxService(): ?TaskInboxService {
		if (class_exists(self::INBOX_SERVICE_CLASS) === false) {
			return null;
		}

		try {
			$service = $this->container->get(self::INBOX_SERVICE_CLASS);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq approval inbox could not resolve the OpenRegister task inbox: ' . $e->getMessage(),
				['exception' => $e]
			);

			return null;
		}

		if (($service instanceof TaskInboxService) === false) {
			return null;
		}

		return $service;
	}//end inboxService()
}//end class
