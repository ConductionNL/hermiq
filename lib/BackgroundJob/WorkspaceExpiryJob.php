<?php

/**
 * Hermiq WorkspaceExpiryJob.
 *
 * Ends every conversation-long workspace whose conversation has been deleted.
 * The 14-day idle end is the reaper's: this job only answers "is the
 * conversation still there".
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Kernel\Contracts\ServerSideWorkspaceProviderInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the workspaces of deleted conversations, once a day.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
 */
class WorkspaceExpiryJob extends TimedJob {

	/**
	 * The register conversations live in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The conversation schema.
	 *
	 * @var string
	 */
	private const CONVERSATION_SCHEMA = 'agentsession';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                         $time          Clock.
	 * @param ServerSideWorkspaceProviderInterface $provider      The workspaces.
	 * @param ObjectService                        $objectService Looks the conversations up.
	 * @param LoggerInterface                      $logger        Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ServerSideWorkspaceProviderInterface $provider,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Remove the workspaces whose conversation is gone.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
	 */
	protected function run($argument): void {
		try {
			$removed = $this->provider->reapEndedSessions(
				conversationGone: fn (string $conversationId): bool => $this->conversationGone(conversationId: $conversationId)
			);
			if ($removed > 0) {
				$this->logger->info(sprintf('Hermiq removed %d workspaces of deleted conversations', $removed), ['app' => 'hermiq']);
			}
		} catch (Throwable $e) {
			$this->logger->error('Hermiq workspace expiry failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'hermiq']);
		}
	}//end run()

	/**
	 * Whether a conversation no longer exists.
	 *
	 * 🔴 ONLY "NOT FOUND" ENDS A WORKSPACE. A lookup that fails for any other
	 * reason (a database hiccup, a schema not yet imported) keeps it: the idle
	 * end removes it in due course, while a false "gone" would delete the
	 * user's files from a conversation that is still open.
	 *
	 * @param string $conversationId The conversation.
	 *
	 * @return bool True when it is gone.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
	 */
	public function conversationGone(string $conversationId): bool {
		try {
			// No user is present in a background job: existence is the only
			// question, so access rules and tenancy do not apply to it.
			$conversation = $this->objectService->find(
				id: $conversationId,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException) {
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not check conversation ' . $conversationId . ' for its workspace: ' . $e->getMessage(),
				['app' => 'hermiq']
			);
			return false;
		}

		return $conversation === null;
	}//end conversationGone()
}//end class
