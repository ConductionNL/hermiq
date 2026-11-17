<?php

/**
 * Hermiq HandoffResultJob.
 *
 * One-shot QueuedJob that brings a closed handoff's result back into its
 * conversation. Queued by HandoffService when a handoff closes or expires, so
 * the request that closed it (a flow-run event, a webhook callback, a poll)
 * never waits on an agent turn. All logic lives in HandoffDelivery; this
 * class stays a pure wrapper (ADR-002), like AgentRunRequestedJob.
 *
 * @category Cron
 * @package  OCA\Hermiq\BackgroundJob
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
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-finished-handoff-returns-its-result-as-a-new-turn-in-the-same-conversation-req-handoff-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Handoff\HandoffDelivery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Background job that delivers one closed handoff.
 *
 * @psalm-api
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-finished-handoff-returns-its-result-as-a-new-turn-in-the-same-conversation-req-handoff-002
 */
class HandoffResultJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time     Time factory for the base job.
	 * @param HandoffDelivery $delivery Runs the follow-up turn or posts the expiry note.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-finished-handoff-returns-its-result-as-a-new-turn-in-the-same-conversation-req-handoff-002
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly HandoffDelivery $delivery,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the job: deliver the handoff the argument names.
	 *
	 * @param mixed $argument `['handoffId' => uuid]`; checked, as job arguments are a JSON round-trip.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-finished-handoff-returns-its-result-as-a-new-turn-in-the-same-conversation-req-handoff-002
	 */
	protected function run($argument): void {
		$handoffId = '';
		if (is_array($argument) === true && is_string($argument['handoffId'] ?? null) === true) {
			$handoffId = $argument['handoffId'];
		}

		$this->delivery->deliver(handoffId: $handoffId);
	}//end run()
}//end class
