<?php

/**
 * Hermiq TaskUserScope
 *
 * Runs a ContextAgent interaction for the Nextcloud task's own user.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ContextAgent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-a-background-task-acts-for-the-tasks-user
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ContextAgent;

use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\TaskProcessing\Exception\ProcessingException;

/**
 * Runs work for a task's user: acting for that user towards the credential broker, and,
 * from cron, as that user inside OpenRegister.
 *
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-a-background-task-acts-for-the-tasks-user
 */
class TaskUserScope {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister, whose runAs() switches the acting user.
	 * @param IUserSession $userSession Tells a cron run (no session) from a request.
	 * @param IUserManager $userManager Resolves the task's user for a cron run.
	 * @param ProviderFactory|null $providerFactory Scopes broker calls to the task's user;
	 *                                              null runs the work without that scope.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly ?ProviderFactory $providerFactory = null,
	) {
	}//end __construct()

	/**
	 * Run the work for the task's user.
	 *
	 * The acting scope is entered OUTSIDE the runAs() switch: entered without a session,
	 * it marks the work as background, so every broker call in the turn is judged by the
	 * broker's sessionless rule (real membership of the credential's organisation) and
	 * not by the session rule runAs() would otherwise present.
	 *
	 * @param string $userId The task user (never request input: Nextcloud's stored task user).
	 * @param callable $work The work, called without arguments.
	 *
	 * @return array The work's result.
	 *
	 * @throws ProcessingException When a cron run's task user is unknown or disabled.
	 *
	 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-a-background-task-acts-for-the-tasks-user
	 */
	public function run(string $userId, callable $work): array {
		$asUser = fn (): array => $this->runAsTaskUser(userId: $userId, work: $work);
		if ($this->providerFactory === null) {
			return $asUser();
		}

		return $this->providerFactory->actingFor(userId: $userId, work: $asUser);
	}//end run()

	/**
	 * Run the work as the task's user when Nextcloud runs it from cron.
	 *
	 * Assistant routes chat to `core:contextagent:interaction`, which Nextcloud runs from
	 * cron with no session. Every OpenRegister read and write of the turn (the agent, the
	 * session, its messages, the agent's tools) is RBAC- and organisation-checked against
	 * the session user, which in cron is nobody: the turn failed with "User 'Anonymous'
	 * does not have permission to 'read' objects in schema 'Session'", and a session saved
	 * anonymously could not be found again by the engine. Without a session the work runs
	 * inside OpenRegister's `ObjectService::runAs()` for the task's user. That grants
	 * nothing: every check answers for that user, and the previous user is restored after.
	 *
	 * With a session (a task run synchronously inside a request) nothing is switched: the
	 * session user stays. A task whose user no longer exists or is disabled is refused.
	 *
	 * @param string $userId The task user.
	 * @param callable $work The work, called without arguments.
	 *
	 * @return array The work's result.
	 *
	 * @throws ProcessingException When a cron run's task user is unknown or disabled.
	 */
	private function runAsTaskUser(string $userId, callable $work): array {
		if ($this->userSession->getUser() !== null) {
			return $work();
		}

		$user = $this->userManager->get($userId);
		if ($user === null || $user->isEnabled() === false) {
			throw new ProcessingException('Hermiq ContextAgent cannot act for this task: its user is unknown or disabled.');
		}

		return $this->objectService->runAs($user, $work);
	}//end runAsTaskUser()
}//end class
