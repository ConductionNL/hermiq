<?php

/**
 * Hermiq ManualRunService.
 *
 * Runs an agent by hand with a prompt, without a schedule
 * (scheduling-overview-and-start-choice D2). The run goes through the same
 * gates a scheduled run passes (the agent is switched on, the organisation's
 * kill switch is not engaged, the budget's hard cap is not reached) and the
 * same governed entry, `runAgentAsOwner()`, as the calling user. Every attempt,
 * held or run, is written to the agent's audit trail as a `manual-run` entry,
 * which the run list shows with trigger `manual`. No schedule is created.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
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
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A run by hand of one agent, gated and audited like a scheduled run.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 */
class ManualRunService {

	/**
	 * The audit action of a run by hand; the run list shows it as trigger `manual`.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'manual-run';

	/**
	 * The longest summary kept on the audit entry.
	 *
	 * @var integer
	 */
	private const SUMMARY_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @param ScheduleServiceInterface $schedules  The gates and the governed run entry.
	 * @param AuditTrailMapper         $auditTrail Writes the run entry on the agent.
	 * @param RedactionService         $redaction  Masks secrets and personal data before the write.
	 * @param LoggerInterface          $logger     Logger.
	 */
	public function __construct(
		private readonly ScheduleServiceInterface $schedules,
		private readonly AuditTrailMapper $auditTrail,
		private readonly RedactionService $redaction,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the agent now with the prompt, as the user.
	 *
	 * The caller has already checked that the user may use the agent.
	 *
	 * @param ObjectEntity $agent  The agent, readable by the user.
	 * @param string       $uid    The user the run is read and written as.
	 * @param string       $prompt What the agent should do.
	 *
	 * @return array{status: string, trigger: string, output: string, error: string|null}
	 *         `status` is `ok`, `error` or the gate that held the run.
	 *
	 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
	 */
	public function run(ObjectEntity $agent, string $uid, string $prompt): array {
		$agentId      = (string)$agent->getUuid();
		$organisation = (string)($agent->getOrganisation() ?? '');
		$startedAt    = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$gate = $this->schedules->gateFor(organisation: $organisation, agentId: $agentId);
		if ($gate !== null) {
			$result = ['status' => $gate, 'trigger' => 'manual', 'output' => '', 'error' => null];
			$this->audit(agent: $agent, run: $result + ['uid' => $uid, 'prompt' => $prompt, 'startedAt' => $startedAt, 'usage' => []]);
			return $result;
		}

		try {
			$output = $this->schedules->runAgentAsOwner(
				owner: $uid,
				agentId: $agentId,
				prompt: $prompt,
				organisation: $organisation
			);
			$result = ['status' => 'ok', 'trigger' => 'manual', 'output' => $output, 'error' => null];
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ManualRunService] A run by hand failed',
				context: ['agentId' => $agentId, 'exception' => $e]
			);
			$result = ['status' => 'error', 'trigger' => 'manual', 'output' => '', 'error' => $e->getMessage()];
		}

		$usage = $this->schedules->getLastRunUsage();
		$this->audit(agent: $agent, run: $result + ['uid' => $uid, 'prompt' => $prompt, 'startedAt' => $startedAt, 'usage' => $usage]);

		return $result;
	}//end run()

	/**
	 * Write the run entry on the agent. A failed write is logged, never fatal.
	 *
	 * @param ObjectEntity         $agent The agent that ran.
	 * @param array<string, mixed> $run   The result plus `uid`, `prompt`, `startedAt` and `usage`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
	 */
	private function audit(ObjectEntity $agent, array $run): void {
		$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$summary = (string)($run['error'] ?? $run['output']);

		$context = [
			'status' => $run['status'],
			'trigger' => 'manual',
			'agentId' => (string)$agent->getUuid(),
			'runAsUser' => $run['uid'],
			'startedAt' => $run['startedAt']->format('c'),
			'endedAt' => $endedAt->format('c'),
			'durationMs' => (((int)$endedAt->format('U') - (int)$run['startedAt']->format('U')) * 1000),
			'usage' => $run['usage'],
			'summary' => $this->redaction->redact(mb_substr($summary, 0, self::SUMMARY_LENGTH)),
			'prompt' => $this->redaction->redact((string)$run['prompt']),
			'dryRun' => false,
		];

		try {
			$this->auditTrail->recordObjectEvent(
				event: new ObjectAuditEvent(object: $agent, action: self::AUDIT_ACTION, context: $context)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ManualRunService] Could not write the run entry',
				context: ['agentId' => (string)$agent->getUuid(), 'exception' => $e]
			);
		}
	}//end audit()
}//end class
