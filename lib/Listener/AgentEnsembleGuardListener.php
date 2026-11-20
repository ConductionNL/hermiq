<?php

/**
 * Hermiq AgentEnsembleGuardListener.
 *
 * An agent's reference models (its `ensemble`) must each be allowed by the
 * organisation's effective model policy. The agent form writes the agent
 * through OpenRegister's objects API, not through AgentsController, so the
 * check sits on OpenRegister's pre-save events and covers every write path.
 * An ensemble that does not change is not checked again, so a policy narrowed
 * later does not block unrelated edits of an agent; every reference call is
 * checked against the policy again at run time.
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
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\Hermiq\Service\Agent\ReferenceModelSettings;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Refuses a write that names a reference model the policy does not allow.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
class AgentEnsembleGuardListener implements IEventListener {

	/**
	 * The field that holds the ensemble.
	 *
	 * @var string
	 */
	private const FIELD = 'ensemble';

	/**
	 * Constructor.
	 *
	 * @param AgentObjectMatcherInterface $agents          Tells a hermiq agent object apart.
	 * @param ReferenceModelSettings      $referenceModels Checks the ensemble against the policy.
	 * @param IUserSession                $userSession     The signed-in writer, for a new agent's organisation.
	 * @param LoggerInterface             $logger          Logger.
	 */
	public function __construct(
		private readonly AgentObjectMatcherInterface $agents,
		private readonly ReferenceModelSettings $referenceModels,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when its changed ensemble names a model the policy does not allow.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
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

		$data = array_merge($agent->getObject(), $event->getModifiedData());
		if (array_key_exists(self::FIELD, $data) === false || $data[self::FIELD] === $this->storedEnsemble(event: $event)) {
			return;
		}

		try {
			$this->referenceModels->normalise(raw: $data[self::FIELD], organisation: $this->organisationOf(agent: $agent));
		} catch (InvalidArgumentException $e) {
			$event->setErrors([self::FIELD => $e->getMessage()]);
			$event->stopPropagation();
			$this->logger->info(
				'[AgentEnsembleGuardListener] Refused an agent ensemble: {message}',
				['message' => $e->getMessage(), 'agent' => (string)$agent->getUuid()]
			);
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
	 * The ensemble stored before this write; null on a create or when none was stored.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return mixed
	 */
	private function storedEnsemble(ObjectCreatingEvent|ObjectUpdatingEvent $event): mixed {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return null;
		}

		return ($event->getOldObject()?->getObject()[self::FIELD] ?? null);
	}//end storedEnsemble()

	/**
	 * The organisation whose policy applies: the agent's own, else the writer's active one.
	 *
	 * @param ObjectEntity $agent The agent being written.
	 *
	 * @return string The organisation identifier, or ''.
	 */
	private function organisationOf(ObjectEntity $agent): string {
		$organisation = (string)($agent->getOrganisation() ?? '');
		if ($organisation !== '') {
			return $organisation;
		}

		return $this->referenceModels->organisationOf(uid: (string)$this->userSession->getUser()?->getUID());
	}//end organisationOf()
}//end class
