<?php

/**
 * Hermiq AgentDepartmentGuardListener.
 *
 * The department an agent runs for decides which department switch holds its
 * runs (oversight-switch-ai-off-per-department). Any write of an agent object
 * that changes `department` (setting, changing or clearing it) is refused
 * before it is saved unless the writer is a Nextcloud administrator, so the
 * person using an agent cannot move it out of a department whose AI an
 * administrator switched off.
 *
 * The guard sits on OpenRegister's pre-save events, so it covers every write
 * path. A value that does not change is not checked, so an agent saved for
 * another reason keeps its department. A write with no signed-in user (a
 * repair step or a background job) is left to the code that made it.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Refuses a write that changes an agent's department unless an administrator makes it.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */
class AgentDepartmentGuardListener implements IEventListener {

	/**
	 * The agent field that names its department.
	 *
	 * @var string
	 */
	private const FIELD = 'department';

	/**
	 * The refusal.
	 *
	 * @var string
	 */
	public const REFUSED = 'Only an administrator can change the department an agent runs for.';

	/**
	 * Constructor.
	 *
	 * @param AgentObjectMatcherInterface $agents       Tells an agent object from any other.
	 * @param IUserSession                $userSession  The writer.
	 * @param IGroupManager               $groupManager Answers whether the writer is an administrator.
	 * @param LoggerInterface             $logger       Logs a refusal.
	 */
	public function __construct(
		private readonly AgentObjectMatcherInterface $agents,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a non-administrator's write that changes an agent's department.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		$agent = $this->written(event: $event);
		if ($this->agents->isAgent(object: $agent) === false) {
			return;
		}

		$named = self::departmentOf(data: array_merge($agent->getObject(), $event->getModifiedData()));
		if ($named === $this->storedDepartment(event: $event)) {
			return;
		}

		$writer = $this->userSession->getUser();
		if ($writer === null || $this->groupManager->isAdmin($writer->getUID()) === true) {
			return;
		}

		$event->setErrors([self::FIELD => self::REFUSED]);
		$event->stopPropagation();
		$this->logger->info(
			'[AgentDepartmentGuardListener] Refused changing an agent\'s department',
			['writer' => $writer->getUID()]
		);
	}//end handle()

	/**
	 * The object being written.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return ObjectEntity
	 */
	private function written(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end written()

	/**
	 * The stored department, or '' on a create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return string
	 */
	private function storedDepartment(ObjectCreatingEvent|ObjectUpdatingEvent $event): string {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return '';
		}

		return self::departmentOf(data: ($event->getOldObject()?->getObject() ?? []));
	}//end storedDepartment()

	/**
	 * The trimmed department of an agent payload, or '' when absent or not a string.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return string
	 */
	private static function departmentOf(array $data): string {
		$value = ($data[self::FIELD] ?? null);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end departmentOf()
}//end class
