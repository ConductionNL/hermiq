<?php

/**
 * The agent step, contributed to OpenRegister's flow engine.
 *
 * This is what makes hermiq a CONSUMER of the fleet's one flow engine rather
 * than the owner of a sixth one (ADR-022, ADR-065). hermiq keeps the one thing
 * only it can do — run an agent turn — and contributes it as a node type;
 * OpenRegister's engine walks the graph, handles branching, joins, waits, run
 * persistence and the trace. hermiq's own graph executor is gone.
 *
 * The turn itself is unchanged: the proven `ScheduleService::runAgentAsOwner()`,
 * the same call the old executor made. Only the surface around it moves — from
 * a bespoke walker to OpenRegister's `IFlowNode`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeLogActions;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Throwable;
use UnexpectedValueException;

/**
 * Runs an agent turn as one step of an OpenRegister flow.
 *
 * WHICH IDENTITY A TURN ACTS AS
 *
 * `context['runAs']` first, `context['triggeredBy']` only as a fallback. The two
 * are deliberately different fields on a run and answer different questions:
 * `triggeredBy` is PROVENANCE — who caused this — and `runAs` is AUTHORIZATION,
 * whose rights the work uses. They are equal for a run a person started and
 * DIFFER for a scheduled one, where the cause is a schedule and the acting
 * identity is the user its trigger declares. An agent turn is an access
 * decision, so it reads the authorization field; reading provenance for one is
 * how a turn comes to execute with one person's rights while the record names
 * another.
 *
 * ⚠️ THE FALLBACK IS A COMPATIBILITY SHIM WITH A KNOWN END, not a design.
 * `runAs` reaches the node context from openregister#2835. An engine older than
 * that build sets only `triggeredBy`, and on such an engine `triggeredBy` IS the
 * only identity there has ever been — so falling back reproduces the old
 * behaviour rather than inventing one. Reading `runAs` alone would make this
 * node refuse every turn against an un-upgraded instance, which is an outage
 * dressed as a safety property. Remove the fallback once the fleet's target
 * instances are known to carry #2835.
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
class HermiqAgentNode implements IFlowNode, IFlowNodeLogActions {

	/**
	 * Appended to the prompt when the node declares `expectJson`.
	 *
	 * @var string
	 */
	private const JSON_INSTRUCTION = 'Reply with a single valid JSON object and nothing else. '
		. 'No prose before or after it, and no markdown code fence.';

	/**
	 * Constructor.
	 *
	 * @param ScheduleServiceInterface $scheduleService The proven agent-turn runner.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly ScheduleServiceInterface $scheduleService,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function getId(): string {
		return 'hermiq.agent-step';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Agent step');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Run an agent turn and put its answer on the item.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('hermiq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * The link an agent turn earns in the run log: its session.
	 *
	 * The step recorded `sessionId` on the item rather than copying the
	 * conversation, so this resolves that pointer to the chat the operator can
	 * read. A copy would have diverged from the session the moment it gained a
	 * reply.
	 *
	 * The href is a hash fragment on hermiq's own root, because the SPA is a
	 * hash router: `linkToRoute()` on a client-side path would throw and take
	 * out the whole log rather than one link.
	 *
	 * No session recorded means NO link — a dry run and the flag-off legacy
	 * path both produce none. A link to the conversation list instead would be
	 * followed once and then never trusted again.
	 *
	 * @param array<string, mixed> $entry One entry from a run's log.
	 *
	 * @return array<int, array{label: string, href: string}> The links.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function logActions(array $entry): array {
		$items = (array)($entry['output']['items'] ?? []);
		$session = trim((string)(($items[0] ?? [])['json']['sessionId'] ?? ''));
		if ($session === '') {
			return [];
		}

		$root = $this->urls->linkToRoute('hermiq.dashboard.page');

		return [
			[
				'label' => $this->l10n->t('Open the agent session'),
				'href' => rtrim($root, '/') . '#/chat/' . rawurlencode($session),
			],
		];

	}//end logActions()

	/**
	 * Available in both scopes; the agent turn enforces its own identity.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Reject an agent step that names no agent.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no agent is named.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function validateConfig(array $config): void {
		$this->assertNamesAnAgent(config: $config);

	}//end validateConfig()

	/**
	 * Run the agent once per item, putting its answer on each.
	 *
	 * The agent is run once for each item so a fanned-out collection is each
	 * handled — the same as every other node in the item model. The answer is
	 * stored under the configured `output` key (default `result`); with
	 * `expectJson` the answer is parsed so a later node can read one field.
	 *
	 * A turn that fails, and an `expectJson` answer that is not JSON, both
	 * FAIL THE STEP rather than putting a placeholder on the item. See the
	 * comments at each site: a downstream router cannot tell "the agent
	 * answered nothing" from "the agent never answered", so the node must not
	 * present the second as the first.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, each with the agent's answer added.
	 *
	 * @throws Throwable When the agent turn fails; the step's `onError` policy
	 *                   decides what the run then does.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		$config = $call->config;
		// Third shape of the same defect: a step that did not run must not
		// report success. `validateConfig()` already rejects a nameless agent,
		// but it only runs when a flow is SAVED — a flow imported or seeded
		// through another path reaches `execute()` unvalidated, and returning
		// the items unchanged makes the step a silent pass-through. The output
		// key is then absent, so a downstream router takes its default branch
		// exactly as though the agent had answered.
		$this->assertNamesAnAgent(config: $config);
		$run = new AgentStepRun(config: $config, owner: $this->actingIdentity(context: $call->context));

		$out = [];
		foreach ($call->items as $index => $item) {
			$slot = new FlowItemSlot(item: $item, index: $index);
			$out[] = $slot->withJson(json: $this->answerOnto(run: $run, json: $slot->json()));
		}

		return $out;
	}//end execute()

	/**
	 * Refuse a step that names no agent.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no agent is named.
	 */
	private function assertNamesAnAgent(array $config): void {
		if (trim((string)($config['agentId'] ?? ($config['agent'] ?? ''))) === '') {
			throw new UnexpectedValueException($this->l10n->t('An agent step needs an agent.'));
		}
	}//end assertNamesAnAgent()

	/**
	 * The identity the turns act as, read from the run context only.
	 *
	 * 🔴 The flow document does NOT get to name the identity.
	 *
	 * This read used to be `$config['owner'] ?? $context['triggeredBy']`, and
	 * Integriq's FlowOwner names it by hand as "the anti-pattern this class
	 * exists to avoid, not the template". A flow document is authored by
	 * anyone who may edit flows, so letting it name the identity its steps run
	 * as is an authoring-time privilege escalation: write `owner: admin` into
	 * a node and the turn executes with admin's rights, attributed to admin.
	 *
	 * ADR-099: identity NARROWS along an invocation chain and never widens.
	 * A step may act as its caller, or refuse. Widening needs a delegation
	 * grant checked against the CALLER, which is a record — not a key in the
	 * document being checked.
	 *
	 * 🔴 `runAs` (AUTHORIZATION) first; `triggeredBy` (PROVENANCE) is a
	 * compatibility fallback — see the class docblock, including its removal.
	 *
	 * @param array $context The run context.
	 *
	 * @return string The trimmed identity, '' when the run records none.
	 */
	private function actingIdentity(array $context): string {
		return trim((string)($context['runAs'] ?? ($context['triggeredBy'] ?? '')));
	}//end actingIdentity()

	/**
	 * Run the agent for one item and put its answer and session on the item's json.
	 *
	 * @param AgentStepRun $run  The step run.
	 * @param array        $json The item's record.
	 *
	 * @return array The record with the answer added.
	 *
	 * @throws Throwable When the agent turn fails.
	 */
	private function answerOnto(AgentStepRun $run, array $json): array {
		// A failed turn is NOT caught here. The engine's per-step `onError`
		// policy is what decides — and it only ever sees failures that
		// propagate out of `execute()`.
		//
		// This used to swallow every Throwable into `$answer = ''` while
		// its own comment claimed the run's onError handling would decide.
		// It could not: `FlowEngine::outcomeForFailedStep()` is reached from
		// the `catch (Throwable)` around the step dispatch, so a swallowed
		// failure never reached it. The step reported success, the item
		// carried an empty answer, and a downstream router read '' as
		// "the agent said nothing" rather than "the agent never ran".
		//
		// That is the wrong default for anything the answer gates. hydra's
		// pipeline is the case in point: a failed applier turn would leave
		// `json.stage.verdict` empty, the flow would release its slot and
		// finish clean, and the run would look like a completed tick that
		// simply had nothing to do.
		//
		// Authors who genuinely want the walk to continue past a failed
		// turn already have the supported way to say so — `onError:
		// continue` on the step — and saying it there records the choice in
		// the flow rather than hard-coding it for everyone.
		$answer = $this->scheduleService->runAgentAsOwner(
			owner: $run->owner,
			agentId: $run->agentId(),
			prompt: $this->promptFor(config: $run->config, json: $json),
			organisation: $run->organisation(),
			dryRun: false,
			forceOwner: false,
			anchor: null
		);

		$json[$run->outputKey()] = $this->decode(config: $run->config, answer: $answer);

		return $this->withSessionPointer(json: $json);
	}//end answerOnto()

	/**
	 * Put the session the last turn produced on the item's json, when there is one.
	 *
	 * @param array $json The item's record.
	 *
	 * @return array The record, with `sessionId` when the turn produced a session.
	 */
	private function withSessionPointer(array $json): array {
		// The conversation this turn produced, so the run log can link to
		// it. A POINTER, never a copy: the session is the record, and a
		// copy of its messages in the log would diverge from it the moment
		// the session gains a reply — and would put the whole reasoning
		// into a record kept for months.
		//
		// Empty on a dry run and on the flag-off legacy path, which produce
		// no bindable conversation. Written only when there is one, so an
		// absent key means "no session", not "the link failed".
		$sessionUuid = $this->scheduleService->lastRunConversationUuid();
		if ($sessionUuid !== '') {
			$json['sessionId'] = $sessionUuid;
		}

		return $json;
	}//end withSessionPointer()

	/**
	 * The prompt for one item: the rendered template, plus the JSON instruction when asked.
	 *
	 * @param array $config The step configuration.
	 * @param array $json   The item's record.
	 *
	 * @return string The prompt.
	 */
	private function promptFor(array $config, array $json): string {
		$prompt = (new ItemPlaceholders(json: $json))->render(template: (string)($config['prompt'] ?? ''));
		if (($config['expectJson'] ?? false) === true) {
			$prompt .= "\n\n" . self::JSON_INSTRUCTION;
		}

		return $prompt;
	}//end promptFor()

	/**
	 * Parse the answer when JSON was asked for; keep the raw text otherwise.
	 *
	 * `expectJson` is a declaration by the flow author that a later node will
	 * read FIELDS off this answer. When the answer is not JSON that contract is
	 * broken, so this throws rather than handing back the prose.
	 *
	 * Returning the raw string — what this did before — is the quiet version of
	 * the same failure the swallowed Throwable caused in `execute()`: the step
	 * succeeds, `json.<output>` is a string, every `{{output.field}}` read
	 * resolves to empty, and the router takes its default branch as though the
	 * agent had genuinely decided that. An agent that replies with an apology
	 * instead of a verdict is not a "no" and must not be routed as one.
	 *
	 * @param array $config The step configuration.
	 * @param string $answer The agent's answer.
	 *
	 * @return mixed The decoded value, or the raw string when no JSON was asked for.
	 *
	 * @throws UnexpectedValueException When `expectJson` is set and the answer is not JSON.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	private function decode(array $config, string $answer) {
		if (($config['expectJson'] ?? false) !== true) {
			return $answer;
		}

		$text = $this->withoutCodeFence(text: trim($answer));

		$decoded = json_decode($text, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			// The excerpt is bounded because an agent answer can be long and
			// this message travels into the run log and onto the failed run.
			throw new UnexpectedValueException(
				$this->l10n->t(
					'The agent step asked for JSON and the agent answered with something else: %s',
					[mb_substr(preg_replace('/\s+/', ' ', $text) ?? '', 0, 200)]
				)
			);
		}

		return $decoded;
	}//end decode()

	/**
	 * Strip a markdown code fence around an answer, when it has one.
	 *
	 * @param string $text The trimmed answer.
	 *
	 * @return string The answer without its fence.
	 */
	private function withoutCodeFence(string $text): string {
		if (str_starts_with($text, '```') === true) {
			return trim((string)preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text));
		}

		return $text;
	}//end withoutCodeFence()
}//end class
