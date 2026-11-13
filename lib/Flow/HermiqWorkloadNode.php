<?php

/**
 * The workload step, contributed to OpenRegister's flow engine.
 *
 * The agent node's counterpart. `hermiq.agent-step` runs a MODEL TURN;
 * this runs a piece of work that needs a FILESYSTEM — clone a ref, run an
 * allowlisted command over it, put the result on the item.
 *
 * WHY A SECOND NODE RATHER THAN A MODE ON THE FIRST
 * ------------------------------------------------
 * A governed agent turn is invoked with
 * `--disallowedTools Bash,Read,Write,Edit,Glob,Grep` and its sidecar is
 * read_only, mountless and routeless — that is the whole point of the
 * transport. So hydra's builder, reviewer and security stages, which are
 * analysis over a checked-out tree with a `composer install` in it, had no
 * expression in a flow at all.
 *
 * They cannot be expressed as flow nodes either: `run-hydra-gates.sh` is 3,599
 * lines over 59 gates, and re-stating that as a graph would be a rewrite whose
 * two implementations of the same rules are guaranteed to drift.
 *
 * So the shell stays shell, and this node is the seam that lets a flow invoke
 * it: the BUSINESS LOGIC (selection, slots, locks, retry, escalation) is the
 * flow, visible on a canvas; the filesystem work is a command, run in the one
 * place inside Nextcloud that has a filesystem and a toolchain.
 *
 * The operator this is built for has a Kubernetes environment with Nextcloud on
 * it and LLM access, and knows how neither works. There is no host to run a
 * container on and no cluster credential to create a Job with — the ExApp is
 * what they already have.
 *
 * WHAT `push` ADDS, AND WHY IT IS A SECOND KEY RATHER THAN A FLAG
 * --------------------------------------------------------------
 * A stage was read-only for its whole life: clone, run, report. `push` makes it
 * a WRITING stage, and its presence — not its contents — is the switch. The
 * runner reads it and withholds `GIT_FORGE_TOKEN`/`GIT_ASKPASS` from the command
 * child, then performs the push itself once `pushGuard` has cleared the
 * repository, the branch and the change set.
 *
 * That withholding is the load-bearing part. A stage that may write runs a model
 * with a shell in a writable tree; if the child held the credential it could run
 * `git push` itself and no runner-side rule could observe it, let alone refuse
 * it — the fences would be decoration around a hole, and they would pass every
 * unit test, because a test drives the guard functions directly.
 *
 * `pushCredentialId` is separate from `credentialId` for a reason that is not
 * tidiness: see the comment at its use site. One credential cannot be both the
 * broker's host-locked proxy and an injectable token.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\Hermiq\Service\AsyncStageDispatchService;
use OCA\Hermiq\Service\StageDispatchService;
use OCA\Hermiq\Service\StageRequest;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Throwable;
use UnexpectedValueException;

/**
 * Runs one filesystem workload as a step of an OpenRegister flow.
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */
class HermiqWorkloadNode implements IFlowNode {
	/**
	 * Constructor.
	 *
	 * @param StageDispatchService $stages The ExApp stage dispatcher.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 * @param AsyncStageDispatchService|null $asyncStages Optional async dispatcher; added
	 *                                                    LAST so existing positional
	 *                                                    construction keeps binding.
	 *                                                    Null means async is unavailable
	 *                                                    and the node REFUSES, rather than
	 *                                                    quietly running synchronously.
	 */
	public function __construct(
		private readonly StageDispatchService $stages,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		// ADDED LAST, and deliberately. Three unit tests construct this node
		// POSITIONALLY; slotting a parameter in ahead of an existing one
		// silently rebinds that argument to a value of the wrong type — which
		// is the same trap OpenRegister's own FlowEngine constructor documents,
		// and it cost 41 test errors here the first time this went in second.
		private readonly ?AsyncStageDispatchService $asyncStages = null,
	) {
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
	 */
	public function getId(): string {
		return 'hermiq.workload-step';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Workload step');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
	 */
	public function getDescription(): string {
		return $this->l10n->t('Check out a ref and run a command over it, then put the result on the item.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('hermiq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * Available in both scopes.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Reject a workload step that names no repo, ref or command.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a required field is missing.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
	 */
	public function validateConfig(array $config): void {
		$this->assertConfigured(config: $config);

	}//end validateConfig()

	/**
	 * Run the workload once per item, putting its result on each.
	 *
	 * WHAT IS AND IS NOT A STEP FAILURE — the distinction this node turns on:
	 *
	 *   - the command RAN and exited non-zero  ->  DATA. hydra's gate runner
	 *     uses its exit code as a failure COUNT, so a router downstream is
	 *     meant to read it. Throwing here would make a flow unable to express
	 *     "if the gates failed, comment and retry".
	 *   - the workload COULD NOT BE RUN       ->  THROWS. An unreachable
	 *     ExApp, a refused command, a clone that failed. The step's `onError`
	 *     policy then decides, and it only ever sees failures that propagate
	 *     out of `execute()`.
	 *
	 * Collapsing the two is the defect this codebase has now hit in four
	 * shapes: a downstream router cannot tell "the gates found nothing" from
	 * "the gates never ran", and both look like a clean tick.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, each with the workload's result added.
	 *
	 * @throws Throwable When the workload could not be run; `onError` decides.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-command-that-ran-and-failed-is-data-not-a-step-failure
	 */
	public function execute(FlowNodeCall $call): array {
		$config = $call->config;
		// `validateConfig()` only runs when a flow is SAVED — a flow imported
		// or seeded through another path reaches `execute()` unvalidated, and
		// returning the items unchanged would make the step a silent
		// pass-through whose output key is simply absent. A downstream router
		// then takes its default branch exactly as though the command had run.
		$this->assertConfigured(config: $config);

		$outKey = (string)($config['output'] ?? 'stage');
		$owner = $this->attributableOwner(context: $call->context);

		$out = [];
		foreach ($call->items as $index => $item) {
			$slot = new FlowItemSlot(item: $item, index: $index);
			$json = $slot->json();
			$run = new WorkloadStageRun(config: $config, json: $json);
			$json[$outKey] = $this->dispatchFor(run: $run, owner: $owner);
			$out[] = $slot->withJson(json: $json);
		}

		return $out;
	}//end execute()

	/**
	 * The identity the stages act as, refusing a run that records none.
	 *
	 * The identity comes from the run context only, never from the step
	 * configuration; the reasoning is kept verbatim below as line comments.
	 *
	 * @param array $context The run context.
	 *
	 * @return string The trimmed owner.
	 *
	 * @throws UnexpectedValueException When neither `runAs` nor `triggeredBy` names one.
	 */
	private function attributableOwner(array $context): string {
		// 🔴 The flow document does NOT get to name the identity.
		//
		// This read used to be `$config['owner'] ?? $context['triggeredBy']`, and
		// Integriq's FlowOwner names it by hand as "the anti-pattern this class
		// exists to avoid, not the template". A flow document is authored by
		// anyone who may edit flows, so letting it name the identity its steps run
		// as is an authoring-time privilege escalation: write `owner: admin` into
		// a node and the turn executes with admin's rights, attributed to admin.
		//
		// ADR-099: identity NARROWS along an invocation chain and never widens.
		// A step may act as its caller, or refuse. Widening needs a delegation
		// grant checked against the CALLER, which is a record — not a key in the
		// document being checked.
		//
		// 🔴 `runAs` FIRST, `triggeredBy` only as a fallback. The two are
		// deliberately different fields on a run and answer different questions:
		// `triggeredBy` is PROVENANCE — who caused this — and `runAs` is
		// AUTHORIZATION, whose rights the work uses. They are equal for a run a
		// person started and DIFFER for a scheduled one, where the cause is a
		// schedule and the acting identity is the user its trigger declares. A
		// workload stage is an access decision, so it reads the authorization
		// field — and it is also what lands in hydra's record as the stage's
		// credential owner, which must name who the work RAN AS.
		//
		// The fallback is a COMPATIBILITY SHIM with a known end, not a design.
		// `runAs` reaches the node context from openregister#2835; an engine
		// older than that build sets only `triggeredBy`, and on such an engine
		// `triggeredBy` IS the only identity there has ever been — so falling
		// back reproduces exactly the old behaviour rather than inventing one.
		// Reading `runAs` alone would make this node refuse every stage against
		// an engine that has not been upgraded yet, which is an outage rather
		// than a safety property. Remove the fallback once the fleet's target
		// instances are known to carry #2835.
		$owner = trim((string)($context['runAs'] ?? ($context['triggeredBy'] ?? '')));

		// ATTRIBUTION IS MANDATORY, and refusing here is the point rather than a
		// side effect. hydra's record answers "who ran this, on whose
		// credential" out of `cycles[].owner` and `stages[].credential_owner`,
		// and a stage that cannot say costs the record that answer FOREVER —
		// the run is durable, the missing attribution is not recoverable later.
		//
		// An unattributed stage is also the shape a credential misuse takes: a
		// Claude subscription serves its owner and never a pool, so "no owner"
		// is precisely the state in which no credential may be selected. hydra's
		// shell said this with `_owner_candidate_indices`; the flow path says it
		// here, once, before anything is dispatched.
		if ($owner === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('A workload step must be attributable: it names no owner and the run records none.')
			);
		}

		return $owner;
	}//end attributableOwner()

	/**
	 * Turn ONE item into one stage call, and attribute the result.
	 *
	 * Split out of `execute()` so the loop states what it does — one stage per
	 * item, attributed — while the rendering and credential decisions, which are
	 * where every defect in this node has been, sit together and can be read in
	 * one piece.
	 *
	 * @param WorkloadStageRun $run   The step configuration and item placeholders.
	 * @param string           $owner The resolved run owner.
	 *
	 * @return array The stage result, with attribution added.
	 *
	 * @throws Throwable When the workload could not be run.
	 */
	private function dispatchFor(WorkloadStageRun $run, string $owner): array {
		$request = $run->stageRequest(owner: $owner);

		// ASYNC is a whole second transport; see dispatchAsyncFor().
		if ($run->isAsync() === true) {
			return $this->attribute(result: $this->dispatchAsyncFor(request: $request), request: $request);
		}

		return $this->attribute(result: $this->stages->dispatchStage(request: $request), request: $request);

	}//end dispatchFor()

	/**
	 * Start the stage and return a handle, instead of waiting for it.
	 *
	 * `FlowRunWorker` advances queued runs serially in one PHP process, so a
	 * synchronous stage holds the only worker for its whole duration and a slot
	 * pool cannot exceed one agent however many slots it declares. Pair this
	 * with an `openregister.wait` and a `hermiq.workload-collect`.
	 *
	 * Extracted from `dispatchFor()` because inlining it took that method to
	 * 136 lines against a 100 threshold, and phpmd was right: a reader cannot
	 * hold that much at once, and this branch is a second transport rather than
	 * a variation on the first.
	 *
	 * @param StageRequest $request The rendered stage, with its job key.
	 *
	 * @return array The handle.
	 *
	 * @throws UnexpectedValueException When no async transport is available.
	 */
	private function dispatchAsyncFor(StageRequest $request): array {
		if ($this->asyncStages === null) {
			throw new UnexpectedValueException(
				$this->l10n->t('This step asks for an asynchronous dispatch, but no async transport is available.')
			);
		}

		return $this->asyncStages->dispatchStageAsync(request: $request);
	}//end dispatchAsyncFor()


	/**
	 * Record who ran a stage and on whose credential, ON the result.
	 *
	 * The attribution travels WITH the result, not beside it. hydra's record
	 * composer reads one object per stage, and an owner it has to correlate from
	 * run metadata is an owner that goes missing the first time a flow fans out
	 * over several repositories.
	 *
	 * `credential_owner` is the run owner deliberately: the broker only resolves
	 * a credential FOR its owner, so the identity that successfully used it is
	 * the identity it belongs to. Recording anything else would be recording an
	 * assumption.
	 *
	 * ⚠️ THE PUSH CREDENTIAL COUNTS. A stage that names only `pushCredentialId`
	 * uses a credential for both its clone and its push, and recording
	 * `credential_name: null` for it would put the WRITING stages — the ones
	 * attribution exists for — in the unattributed bucket. The name records
	 * which id was used, not which config key held it.
	 *
	 * @param array        $result  The stage result.
	 * @param StageRequest $request The stage it came from: its owner and credentials.
	 *
	 * @return array The result, with attribution added.
	 */
	private function attribute(array $result, StageRequest $request): array {
		$usedCredential = $request->options->injectCredentialId();

		$credentialOwner = null;
		$credentialName = null;
		if ($usedCredential !== '') {
			$credentialOwner = $request->uid;
			$credentialName = $usedCredential;
		}

		$result['owner'] = $request->uid;
		$result['credential_owner'] = $credentialOwner;
		$result['credential_name'] = $credentialName;

		return $result;
	}//end attribute()

	/**
	 * Assert the three fields a workload cannot run without.
	 *
	 * Shared by `validateConfig()` and `execute()` deliberately: an author
	 * saving a flow and a flow arriving through an import must be held to the
	 * same contract, and one copy of it is the only way that stays true.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a required field is missing.
	 */
	private function assertConfigured(array $config): void {
		if (trim((string)($config['repo'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('A workload step needs a repository.'));
		}

		if (trim((string)($config['ref'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('A workload step needs a ref.'));
		}

		if ($this->namesACommand(command: ($config['command'] ?? null)) === false) {
			// An argv ARRAY rather than a command string, so nothing here has
			// to parse a shell. The runner allowlists the first token; a string
			// would invite splitting it here and getting quoting wrong.
			throw new UnexpectedValueException(
				$this->l10n->t('A workload step needs a command, given as an array of arguments.')
			);
		}

		$this->assertPushConfigured(config: $config);

	}//end assertConfigured()

	/**
	 * Whether a configured command is a non-empty argv array with a first token.
	 *
	 * @param mixed $command The configured command.
	 *
	 * @return boolean True when the command can be run.
	 */
	private function namesACommand(mixed $command): bool {
		return is_array($command) === true && $command !== [] && trim((string)($command[0] ?? '')) !== '';
	}//end namesACommand()

	/**
	 * Assert a `push` declaration the runner can actually fence.
	 *
	 * Checked HERE, at save time, rather than left to the runner, because the
	 * runner's refusal arrives as a failed flow run half an hour into a stage —
	 * and, worse, the two fields being checked are the two that define the
	 * fence. `pushGuard` builds its allowlist pattern out of the issue number:
	 * with no issue it fails closed and refuses everything, so a flow missing it
	 * is not a flow with a wider fence, it is a flow that can never push. An
	 * author gets told at the moment they can still fix it.
	 *
	 * `scope` is deliberately NOT required. An absent scope disables the scope
	 * rule only — the forbidden prefixes and the dependency-manifest rule still
	 * apply — and requiring it here would make the common case (a change whose
	 * scope is the whole repository) inexpressible.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the push declaration cannot be fenced.
	 */
	private function assertPushConfigured(array $config): void {
		$push = ($config['push'] ?? null);
		if ($push === null || $push === []) {
			return;
		}

		if (is_array($push) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('A workload step\'s "push" must be an object naming a branch and an issue.')
			);
		}

		$this->assertPushNamesItsFence(push: $push);
		$this->assertPushScopeIsAList(push: $push);

	}//end assertPushConfigured()

	/**
	 * Assert the push names the branch and the issue its allowlist is built from.
	 *
	 * @param array $push The push declaration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the branch or the issue is missing.
	 */
	private function assertPushNamesItsFence(array $push): void {
		if (trim((string)($push['branch'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('A workload step that pushes must name the branch to push to.')
			);
		}

		// Empty rather than non-numeric: the value is usually a `{{placeholder}}`
		// at save time and only becomes a number per item. Refusing a template
		// here would make the only real usage unauthorable.
		if (trim((string)($push['issue'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'A workload step that pushes must name the issue it answers — the push allowlist is built from it.'
				)
			);
		}

	}//end assertPushNamesItsFence()

	/**
	 * Assert a declared push `scope` is a list of path prefixes.
	 *
	 * @param array $push The push declaration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When `scope` is present and not a list.
	 */
	private function assertPushScopeIsAList(array $push): void {
		if (isset($push['scope']) === true && is_array($push['scope']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('A workload step\'s push "scope" must be a list of path prefixes.')
			);
		}

	}//end assertPushScopeIsAList()

}//end class
