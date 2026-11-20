<?php

/**
 * Hermiq TalkTurnRunnerInterface.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * The contract a triggerable Talk-turn runner fulfils (instant-talk path A).
 *
 * A runner is registered as a TaskProcessing `ITriggerableProvider`;
 * `TalkTurnDispatcher` nudges it with `trigger()` inside the sender's request,
 * which must return at once. Out of that request the runner then loops:
 *
 * 1. pull the OLDEST queued `TalkTurnJob` argument it has not seen;
 * 2. hand it to `TalkTurnExecutor::execute()`, which claims the turn id, runs
 *    the turn through `TalkTurnService::runTurn()` and records it done — an
 *    argument another executor already claimed or finished comes back
 *    `skipped`, so the runner never needs to deduplicate itself;
 * 3. repeat until no unseen turn is left, then wait for the next trigger.
 *
 * The runner never removes the queued job: the job is the durable record and
 * finds the done marker when it runs. The runner ships on the
 * `llm-cli-runner-exapp` track; hermiq deliberately carries no implementation,
 * which is also why this lives beside the dispatcher and not under
 * Kernel\Contracts, where every contract is bound to a hermiq class.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
 */
interface TalkTurnRunnerInterface {

	/**
	 * Run queued Talk turns, oldest first, until none is left.
	 *
	 * @return int How many turns this call ran (skipped ones are not counted).
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function drain(): int;
}//end interface
