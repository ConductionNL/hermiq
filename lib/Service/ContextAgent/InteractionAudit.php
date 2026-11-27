<?php

/**
 * Hermiq InteractionAudit
 *
 * Writes the redacted audit entry of one ContextAgent interaction.
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
 * @spec openspec/changes/archive/2026-11-20-contextagent-provider/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ContextAgent;

use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * The redacted `contextagent-interaction` audit write. Never fatal to the interaction.
 *
 * @spec openspec/changes/archive/2026-11-20-contextagent-provider/tasks.md#task-2-2
 */
class InteractionAudit {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper The audit write-path.
	 * @param RedactionService $redactionService Masks secrets/PII before the audit write.
	 * @param AgentVersionService $agentVersionService Resolves the serving agent's current
	 *                                                 version identifier (agent-versioning).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly RedactionService $redactionService,
		private readonly AgentVersionService $agentVersionService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write a redacted `contextagent-interaction` AuditTrail entry. Never fatal.
	 *
	 * @param ObjectEntity $object The object to attach the audit entry to (agent or conversation).
	 * @param string $status The interaction status (ok/error/skipped_killswitch).
	 * @param string $summary A short summary (redacted before persist).
	 * @param string $agentId The serving agent UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-20-contextagent-provider/tasks.md#task-2-2
	 */
	public function record(ObjectEntity $object, string $status, string $summary, string $agentId): void {
		try {
			$this->auditTrailMapper->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $object,
					action: 'contextagent-interaction',
					context: [
						'status' => $status,
						'agentId' => $agentId,
						'summary' => $this->redactionService->redact($summary),
						// Agent-versioning: the version of the serving agent's config at
						// the time of this interaction (null when unresolvable — never
						// fatal to the interaction).
						'agentVersion' => $this->agentVersionService->currentVersionId(agentUuid: $agentId),
					]
				)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ContextAgentInteractionService] Could not write interaction audit',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}
	}//end record()
}//end class
