<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\Handoff
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Handoff;

use OCP\IURLGenerator;

/**
 * Webhook handoffs: a workflow (an n8n workflow, or any webhook a custom tool
 * reaches through integriq) that answers its webhook at once and posts its
 * result later to a one-time address.
 *
 * Opening one mints 32 random bytes as the result token and keeps only their
 * SHA-256 on the handoff. The caller adds `hermiqResultUrl` and
 * `hermiqResultToken` to the payload it sends through integriq; hermiq never
 * calls the workflow's host itself. A result is accepted once, with that
 * token, while the handoff is waiting; everything else is refused the same way.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
 */
class WebhookHandoffs {

	/**
	 * The route the workflow posts its result to.
	 */
	public const RESULT_ROUTE = 'hermiq.handoffResult.post';

	/**
	 * The number of random bytes in a result token.
	 */
	private const TOKEN_BYTES = 32;

	/**
	 * Constructor.
	 *
	 * @param HandoffService $handoffs     The handoff record.
	 * @param IURLGenerator  $urlGenerator Builds the absolute result address.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function __construct(
		private readonly HandoffService $handoffs,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Open a webhook handoff and answer the fields to add to the workflow's payload.
	 *
	 * @param FlowTurn $turn          The agent, conversation and owner of the turn.
	 * @param string   $label         What the user sees the work called.
	 * @param string   $target        The custom tool's slug.
	 * @param int|null $deadlineHours The tool's deadline; null is the default of 24 hours.
	 *
	 * @return array{handoffId: string, hermiqResultUrl: string, hermiqResultToken: string}
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function open(FlowTurn $turn, string $label, string $target, ?int $deadlineHours = null): array {
		$token   = bin2hex(random_bytes(self::TOKEN_BYTES));
		$handoff = $this->handoffs->open(
			handoff: [
				'kind'            => 'webhook',
				'agentId'         => $turn->agentId,
				'conversationId'  => $turn->conversationId,
				'requestedBy'     => $turn->owner,
				'label'           => $label,
				'target'          => $target,
				'deadlineHours'   => $deadlineHours,
				'resultTokenHash' => self::hashOf(token: $token),
			]
		);
		$handoffId = (string) ($handoff['id'] ?? '');

		return [
			'handoffId'         => $handoffId,
			'hermiqResultUrl'   => $this->urlGenerator->linkToRouteAbsolute(self::RESULT_ROUTE, ['id' => $handoffId]),
			'hermiqResultToken' => $token,
		];
	}//end open()

	/**
	 * Accept a workflow's result: only with the handoff's token, only once, only while it waits.
	 *
	 * @param string $handoffId The handoff named by the address.
	 * @param string $token     The token the workflow sent.
	 * @param mixed  $result    The workflow's result.
	 *
	 * @return bool True when the result closed the handoff; false for every refusal alike.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function acceptResult(string $handoffId, string $token, mixed $result): bool {
		if ($token === '') {
			return false;
		}

		$handoff = $this->handoffs->find(handoffId: $handoffId);
		if ($handoff === null
			|| ($handoff['kind'] ?? null) !== 'webhook'
			|| ($handoff['status'] ?? null) !== HandoffService::WAITING
		) {
			return false;
		}

		$stored = (string) ($handoff['resultTokenHash'] ?? '');
		if ($stored === '' || hash_equals($stored, self::hashOf(token: $token)) === false) {
			return false;
		}

		return $this->handoffs->close(handoffId: $handoffId, status: 'done', result: $result);
	}//end acceptResult()

	/**
	 * The stored form of a token.
	 *
	 * @param string $token The token.
	 *
	 * @return string
	 */
	private static function hashOf(string $token): string {
		return hash('sha256', $token);
	}//end hashOf()
}//end class
