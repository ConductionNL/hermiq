<?php

/**
 * Hermiq TalkTurnExecutor.
 *
 * Claim, run, record: the one way any executor runs a handed-off Talk turn.
 * The queued `TalkTurnJob` and the instant post-flush path both come through
 * here, so neither can run a turn the other already holds or finished, and
 * `TalkTurnService::runTurn()` stays the single execution route.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/instant-talk/tasks.md#1-claims-and-dedup
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\Kernel\Contracts\TalkTurnServiceInterface;

/**
 * Runs a handed-off turn under its claim.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
 */
class TalkTurnExecutor {

	/**
	 * The fields every handed-off turn carries, all required.
	 *
	 * @var list<string>
	 */
	private const REQUIRED = ['conversationUuid', 'speakerUid', 'message', 'roomToken'];

	/**
	 * The fields a turn handed off since instant-talk also carries; optional,
	 * so a job enqueued before the upgrade still runs.
	 *
	 * @var list<string>
	 */
	private const OPTIONAL = ['turnId', 'agentId', 'messageId'];

	/**
	 * Constructor.
	 *
	 * @param TalkTurnClaims           $claims  The claim store.
	 * @param TalkTurnServiceInterface $service The service that runs the turn.
	 */
	public function __construct(
		private readonly TalkTurnClaims $claims,
		private readonly TalkTurnServiceInterface $service,
	) {
	}//end __construct()

	/**
	 * Run the handed-off turn unless another executor holds or finished it.
	 *
	 * The done marker is written only once `runTurn()` returned: a process that
	 * dies mid-turn leaves just the claim, which expires, and the still-enqueued
	 * job then runs the turn — at least once through the queue, exactly once
	 * visibly through the claim.
	 *
	 * @param mixed $argument The hand-off argument (the queued job's argument).
	 *
	 * @return string `ran`, `skipped` (claimed or done elsewhere) or `invalid`.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function execute(mixed $argument): string {
		$turn = $this->turnOf(argument: $argument);
		if ($turn === null) {
			return 'invalid';
		}

		$turnId = $turn['turnId'];
		if ($this->claims->claim(turnId: $turnId) === false) {
			return 'skipped';
		}

		$this->service->runTurn(turn: $this->talkTurn(fields: $turn));
		$this->claims->markDone(turnId: $turnId);

		return 'ran';
	}//end execute()

	/**
	 * The turn to run, from the hand-off's validated fields.
	 *
	 * @param array<string, mixed> $fields The fields `turnOf()` answered.
	 *
	 * @return TalkTurn The turn.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	private function talkTurn(array $fields): TalkTurn {
		$turn = new TalkTurn(conversationUuid: (string)$fields['conversationUuid'], speakerUid: (string)$fields['speakerUid']);
		$turn->message = (string)$fields['message'];
		$turn->roomToken = (string)$fields['roomToken'];
		$turn->turnId = (string)$fields['turnId'];
		$turn->agentId = (string)$fields['agentId'];
		$turn->messageId = (string)$fields['messageId'];
		$turn->chain = (array)$fields['chain'];

		return $turn;
	}//end talkTurn()

	/**
	 * The turn's fields, or null when a required one is missing or empty.
	 *
	 * @param mixed $argument The hand-off argument.
	 *
	 * @return array<string, mixed>|null The four required fields, `turnId`,
	 *                                   `agentId` and `messageId` as strings, and `chain` as an array.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function turnOf(mixed $argument): ?array {
		if (is_array($argument) === false) {
			return null;
		}

		$turn = [];
		foreach (self::REQUIRED as $field) {
			$turn[$field] = (string)($argument[$field] ?? '');
			if ($turn[$field] === '') {
				return null;
			}
		}

		foreach (self::OPTIONAL as $field) {
			$turn[$field] = (string)($argument[$field] ?? '');
		}

		$chain = ($argument['chain'] ?? []);
		$turn['chain'] = [];
		if (is_array($chain) === true) {
			$turn['chain'] = $chain;
		}

		return $turn;
	}//end turnOf()
}//end class
