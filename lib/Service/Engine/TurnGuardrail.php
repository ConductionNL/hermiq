<?php

/**
 * Hermiq TurnGuardrail.
 *
 * The guardrail policy of one chat turn: the effective policy is resolved once,
 * the user's text is screened before it is stored or sent to a model, and the
 * model's answer is screened before it is stored or returned. Only a guardrail
 * action (a block, or a redaction that changed the text) becomes a trace step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-3-wire-input-output-filters-into-engine-processmessage
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;

/**
 * Screens one turn's input and output; one instance per turn.
 *
 * Without a GuardrailPolicyService the policy is fully open and every text
 * passes unchanged (design.md Decision 1, fail-open).
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
 */
class TurnGuardrail {

	/**
	 * The turn's effective policy, once resolved.
	 *
	 * @var array<string, mixed>
	 */
	private array $policy = [];

	/**
	 * Constructor.
	 *
	 * @param GuardrailPolicyService|null $policies The policy service; null is the fully-open policy.
	 * @param RunTraceCollector|null      $trace    The turn's trace, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
	 */
	public function __construct(
		private readonly ?GuardrailPolicyService $policies,
		private readonly ?RunTraceCollector $trace,
	) {
	}//end __construct()

	/**
	 * Resolve the effective policy for the conversation's organisation.
	 *
	 * @param string $organisation The conversation's organisation (may be '').
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
	 */
	public function resolve(string $organisation): void {
		if ($this->policies === null) {
			$this->policy = [
				'inputFilters' => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
				'outputFilters' => ['piiAction' => 'off'],
				'toolPolicy' => [],
			];
			return;
		}

		$this->policy = $this->policies->effectivePolicyFor(organisation: $organisation);
	}//end resolve()

	/**
	 * Have the channel's tool-call and tool-result frames pass the output filters
	 * of the policy that filters the answer. No channel or no policy service
	 * leaves the frames as they are.
	 *
	 * @param StreamYieldChannel|null $channel The turn's streaming channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	public function screenToolDetails(?StreamYieldChannel $channel): void {
		$policies = $this->policies;
		if ($channel === null || $policies === null) {
			return;
		}

		$policy = $this->policy;
		$channel->screenToolDetails(
			screen: static fn (mixed $value): mixed => $policies->filterOutputValue(policy: $policy, value: $value)
		);
	}//end screenToolDetails()

	/**
	 * The user's text as it may be stored and sent: a `block` match (PII, a
	 * secret or prompt injection) throws, a `redact` match returns the masked text.
	 *
	 * @param string $text The user's text.
	 *
	 * @return string
	 *
	 * @throws GuardrailBlockedException When the input filter blocks the text.
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-3-wire-input-output-filters-into-engine-processmessage
	 */
	public function screenInput(string $text): string {
		$filter = $this->policies?->filterInput(policy: $this->policy, text: $text)
			?? ['text' => $text, 'blocked' => false, 'reason' => null];
		$this->recordAction(filter: $filter, step: ['name' => 'Input filter', 'text' => $text]);

		if ($filter['blocked'] === true) {
			throw new GuardrailBlockedException(reason: (string)$filter['reason']);
		}

		return (string)$filter['text'];
	}//end screenInput()

	/**
	 * The model's answer as it may be stored and returned: a `block` match never
	 * aborts the turn, it returns the withheld-response placeholder instead.
	 *
	 * @param string $text The model's answer.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-3-wire-input-output-filters-into-engine-processmessage
	 */
	public function screenOutput(string $text): string {
		$filter = $this->policies?->filterOutput(policy: $this->policy, text: $text)
			?? ['text' => $text, 'blocked' => false, 'reason' => null];
		$this->recordAction(filter: $filter, step: ['name' => 'Output filter', 'text' => $text]);

		return (string)$filter['text'];
	}//end screenOutput()

	/**
	 * Filter the model's reasoning as the answer is filtered: a masked reasoning
	 * comes back masked, a blocked one as null. It records no trace step, so the
	 * answer's account does not report a filter action on the answer itself.
	 *
	 * @param string $text The reasoning.
	 *
	 * @return string|null The filtered reasoning, or null when the filter blocked it.
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
	 */
	public function screenReasoning(string $text): ?string {
		$filter = $this->policies?->filterOutput(policy: $this->policy, text: $text);
		if ($filter === null) {
			return $text;
		}

		if ($filter['blocked'] === true) {
			return null;
		}

		return (string)$filter['text'];
	}//end screenReasoning()

	/**
	 * Whether `screenOutput()` can change or withhold the answer under this policy:
	 * true when the output PII action is anything but `off`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	public function screensOutput(): bool {
		$outputFilters = (array)($this->policy['outputFilters'] ?? []);

		return (string)($outputFilters['piiAction'] ?? 'off') !== 'off';
	}//end screensOutput()

	/**
	 * Record a guardrail action as a trace step; a no-op pass-through records
	 * nothing, so a fully-open policy leaves the step timeline unchanged.
	 *
	 * @param array{text: mixed, blocked: bool, reason: mixed} $filter The filter result.
	 * @param array{name: string, text: string}                $step   The step name and the pre-filter text.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
	 */
	private function recordAction(array $filter, array $step): void {
		if ($filter['blocked'] !== true && ((string)$filter['text']) === $step['text']) {
			return;
		}

		$outcome = 'redacted';
		if ($filter['blocked'] === true) {
			$outcome = 'blocked';
		}

		$token = $this->trace?->startStep(type: 'guardrail', name: $step['name']);
		if ($token !== null) {
			$this->trace?->endStep(token: $token, outcome: $outcome);
		}
	}//end recordAction()
}//end class
