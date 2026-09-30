<?php

/**
 * Hermiq WorkspaceAuditor.
 *
 * Writes one audit record for every write-shaped workspace tool call, allowed
 * or refused, on the run-audit path every other agent run uses: an
 * OpenRegister audit-trail entry on the agent (action `workspace-write`). The
 * record names the run, the agent, the tool and its classification, the run
 * owner, the forge credential a push used, the approval that permitted the
 * call, the arguments and the outcome (`ok` or the refusal code).
 *
 * The arguments are an allowlisted, redacted subset: workspace-relative paths,
 * branch and repository names, a commit message. File content and patch bodies
 * never enter a record, and neither does a filesystem path, a host name or a
 * credential secret, because none of them is ever handed to this class.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-governed-workspace-write-is-audited-with-owner-credential-and-approval
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records workspace writes.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-governed-workspace-write-is-audited-with-owner-credential-and-approval
 */
class WorkspaceAuditor {

	/**
	 * The audit-trail action of a workspace write.
	 *
	 * @var string
	 */
	public const ACTION = 'workspace-write';

	/**
	 * The arguments a record may carry, per tool. Content and patch bodies are absent on purpose.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const RECORDED_ARGUMENTS = [
		WorkspaceToolDescriptors::WRITE_FILE => ['path', 'mode'],
		WorkspaceToolDescriptors::DELETE_FILE => ['path'],
		WorkspaceToolDescriptors::APPLY_PATCH => [],
		WorkspaceToolDescriptors::CREATE_BRANCH => ['branch'],
		WorkspaceToolDescriptors::CHECKOUT_BRANCH => ['branch'],
		WorkspaceToolDescriptors::COMMIT => ['message', 'paths'],
		WorkspaceToolDescriptors::PUSH => ['repository', 'branch'],
	];

	/**
	 * The longest recorded text value.
	 *
	 * @var int
	 */
	private const MAX_TEXT = 500;

	/**
	 * Build the auditor.
	 *
	 * @param ObjectService    $objects    Reads the run's agent (the audit subject).
	 * @param AuditTrailMapper $auditTrail OpenRegister's append-only audit trail.
	 * @param RedactionService $redaction  Masks secrets and personal data before persistence.
	 * @param LoggerInterface  $logger     Logs a record that could not be written.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly AuditTrailMapper $auditTrail,
		private readonly RedactionService $redaction,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one write-shaped call.
	 *
	 * @param array{runId: string, agentId: string, userId: string} $run       The verified run.
	 * @param string                                               $toolId    The tool.
	 * @param array<string, mixed>                                 $arguments The call's arguments.
	 * @param array{approvalId: string, decidedBy: string}|null    $approval  The decision that permitted it, or null.
	 * @param array<string, mixed>|null                            $result    The tool's result, or null when refused.
	 * @param string                                               $outcome   `ok` or the refusal code.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-refused-write-is-audited-too
	 */
	public function record(array $run, string $toolId, array $arguments, ?array $approval, ?array $result, string $outcome): void {
		$recorded = $this->recordedArguments(toolId: $toolId, arguments: $arguments);
		if ($toolId === WorkspaceToolDescriptors::APPLY_PATCH) {
			$recorded['files'] = array_values(array_map('strval', (array)($result['files'] ?? [])));
		}

		$context = [
			'runId' => $run['runId'],
			'agentId' => $run['agentId'],
			'tool' => $toolId,
			'classification' => $this->classification(toolId: $toolId),
			'owner' => $run['userId'],
			'credentialId' => (string)($result['credentialId'] ?? ''),
			'approvalId' => (string)($approval['approvalId'] ?? ''),
			'decidedBy' => (string)($approval['decidedBy'] ?? ''),
			'arguments' => $recorded,
			'outcome' => $outcome,
		];

		try {
			$this->auditTrail->createAuditTrailEntry(object: $this->subject(agentId: $run['agentId']), action: self::ACTION, context: $context);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not write the workspace audit record for run ' . $run['runId'] . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end record()

	/**
	 * The allowlisted, redacted arguments of a call.
	 *
	 * @param string               $toolId    The tool.
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function recordedArguments(string $toolId, array $arguments): array {
		$recorded = [];
		foreach (self::RECORDED_ARGUMENTS[$toolId] ?? [] as $name) {
			if (array_key_exists($name, $arguments) === false) {
				continue;
			}

			$value = $arguments[$name];
			if (is_array($value) === true) {
				$recorded[$name] = array_values(array_map(fn ($item): string => $this->text(value: $item), $value));
				continue;
			}

			$recorded[$name] = $this->text(value: $value);
		}

		return $recorded;
	}//end recordedArguments()

	/**
	 * One redacted, bounded text value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return $this->redaction->redact(mb_substr((string)$value, 0, self::MAX_TEXT));
	}//end text()

	/**
	 * `destructive` or `write`, from the tool's own descriptor; narrowing a grant never changes it.
	 *
	 * @param string $toolId The tool.
	 *
	 * @return string
	 */
	private function classification(string $toolId): string {
		foreach (WorkspaceToolDescriptors::ALL as $descriptor) {
			if ($descriptor['id'] === $toolId && ($descriptor['destructiveHint'] ?? false) === true) {
				return 'destructive';
			}
		}

		return 'write';
	}//end classification()

	/**
	 * The agent the record hangs on; an unreadable agent still gets its record.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return ObjectEntity
	 */
	private function subject(string $agentId): ObjectEntity {
		try {
			$agent = $this->objects->find(id: $agentId, register: 'hermiq', schema: 'agent', _rbac: false, _multitenancy: false);
			if ($agent instanceof ObjectEntity) {
				return $agent;
			}
		} catch (Throwable) {
			// Fall through: the record matters more than the lookup.
		}

		$subject = new ObjectEntity();
		$subject->setUuid($agentId);
		return $subject;
	}//end subject()
}//end class
