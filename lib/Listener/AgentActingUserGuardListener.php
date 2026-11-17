<?php

/**
 * Hermiq AgentActingUserGuardListener.
 *
 * Naming the account an agent works with is a governance action: an agent
 * whose `actingUser` is set runs with that user's rights. Any write of an
 * agent object that sets `actingUser` to a non-empty value different from the
 * stored one is refused before it is saved unless the writer passes
 * `agent.set-acting-user` and the named user exists and is enabled.
 *
 * The guard sits on OpenRegister's pre-save events, so it covers every write
 * path: the edit page, and a direct write through the objects API. Clearing
 * `actingUser` narrows the agent's rights to the person who uses it and stays
 * allowed. A value that does not change is not checked again, so an agent set
 * before this guard keeps its account. A write with no signed-in user (a
 * repair step, or TenantOpsService::reassignAgent() after its own check) is
 * left to the code that made it.
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
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-naming-a-service-account-is-a-governance-action-checked-on-every-write-req-agid-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Refuses a write that names an agent's acting user without the right to.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-naming-a-service-account-is-a-governance-action-checked-on-every-write-req-agid-002
 */
class AgentActingUserGuardListener implements IEventListener {

	/**
	 * The governance action that names an agent's acting user.
	 *
	 * @var string
	 */
	public const ACTION = 'agent.set-acting-user';

	/**
	 * The field that holds the acting user.
	 *
	 * @var string
	 */
	private const FIELD = 'actingUser';

	/**
	 * The refusal when the writer lacks the action.
	 *
	 * @var string
	 */
	public const REFUSED_ACTION = 'Naming the account an agent works with needs the action agent.set-acting-user. An administrator sets it.';

	/**
	 * The refusal when the named account cannot be used.
	 *
	 * @var string
	 */
	public const REFUSED_ACCOUNT = 'The account an agent works with does not exist or is disabled.';

	/**
	 * Constructor.
	 *
	 * @param AgentObjectMatcherInterface $agents      Tells a hermiq agent object apart.
	 * @param ActionAuthServiceInterface  $actions     The action-authorization matrix.
	 * @param IUserSession                $userSession The signed-in writer.
	 * @param IUserManager                $userManager Resolves the named account.
	 * @param LoggerInterface             $logger      Logger.
	 */
	public function __construct(
		private readonly AgentObjectMatcherInterface $agents,
		private readonly ActionAuthServiceInterface $actions,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when it names an acting user the writer may not name.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-naming-a-service-account-is-a-governance-action-checked-on-every-write-req-agid-002
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

		$named = self::actingUserOf(data: array_merge($agent->getObject(), $event->getModifiedData()));
		if ($named === '' || $named === $this->storedActingUser(event: $event)) {
			return;
		}

		$writer = $this->userSession->getUser();
		if ($writer === null) {
			return;
		}

		if ($this->actions->can(user: $writer, action: self::ACTION) === false) {
			$this->refuse(event: $event, message: self::REFUSED_ACTION, writer: $writer->getUID());
			return;
		}

		$account = $this->userManager->get($named);
		if ($account === null || $account->isEnabled() === false) {
			$this->refuse(event: $event, message: self::REFUSED_ACCOUNT, writer: $writer->getUID());
		}
	}//end handle()

	/**
	 * The object being written.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
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
	 * The acting user stored before this write; none on a create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return string
	 */
	private function storedActingUser(ObjectCreatingEvent|ObjectUpdatingEvent $event): string {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return '';
		}

		return self::actingUserOf(data: ($event->getOldObject()?->getObject() ?? []));
	}//end storedActingUser()

	/**
	 * The acting user an agent's data names, trimmed; empty when none.
	 *
	 * @param array<string, mixed> $data The agent's data.
	 *
	 * @return string
	 */
	private static function actingUserOf(array $data): string {
		$value = ($data[self::FIELD] ?? null);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end actingUserOf()

	/**
	 * Stop the write and name why.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The pre-save event.
	 * @param string                                  $message The reason.
	 * @param string                                  $writer  Who tried, for the log.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $message, string $writer): void {
		$event->setErrors([self::FIELD => $message]);
		$event->stopPropagation();
		$this->logger->info(
			'[AgentActingUserGuardListener] Refused naming an agent\'s acting user: {message}',
			['message' => $message, 'writer' => $writer]
		);
	}//end refuse()
}//end class
