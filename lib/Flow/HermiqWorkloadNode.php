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
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata (carries the triggering user).
	 *
	 * @return array The items, each with the workload's result added.
	 *
	 * @throws Throwable When the workload could not be run; `onError` decides.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-command-that-ran-and-failed-is-data-not-a-step-failure
	 */
	public function execute(array $items, array $config, array $context): array {
		// `validateConfig()` only runs when a flow is SAVED — a flow imported
		// or seeded through another path reaches `execute()` unvalidated, and
		// returning the items unchanged would make the step a silent
		// pass-through whose output key is simply absent. A downstream router
		// then takes its default branch exactly as though the command had run.
		$this->assertConfigured(config: $config);

		$outKey = (string)($config['output'] ?? 'stage');
		$owner = trim((string)($config['owner'] ?? ($context['triggeredBy'] ?? '')));

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

		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item['json'] ?? []);
			$json[$outKey] = $this->dispatchFor(config: $config, json: $json, owner: $owner);

			$out[] = [
				'json' => $json,
				'binary' => (array)($item['binary'] ?? []),
				'pairedItem' => ['item' => $index],
			];
		}

		return $out;
	}//end execute()

	/**
	 * Turn ONE item into one stage call, and attribute the result.
	 *
	 * Split out of `execute()` so the loop states what it does — one stage per
	 * item, attributed — while the rendering and credential decisions, which are
	 * where every defect in this node has been, sit together and can be read in
	 * one piece.
	 *
	 * @param array $config The step configuration.
	 * @param array $json The item's record.
	 * @param string $owner The resolved run owner.
	 *
	 * @return array The stage result, with attribution added.
	 *
	 * @throws Throwable When the workload could not be run.
	 */
	private function dispatchFor(array $config, array $json, string $owner): array {
		// RENDERED, like every other configured value, and rendered PER ITEM
		// because a fan-out may carry a different credential per repository.
		//
		// This was the one field the first version left un-rendered, and the
		// failure was invisible: the literal string `{{forgeCredential}}` went
		// to the broker as a credential id, the broker could not find it, and
		// its `catch (Throwable) { $credential = null; }` reported `credential
		// not found` — which reads as a missing credential rather than an
		// unrendered placeholder. It took a logging fix in OpenRegister
		// (openregister#2245) to see the id it was actually given.
		$credentialId = trim($this->render(template: (string)($config['credentialId'] ?? ''), json: $json));

		// THE SECOND CREDENTIAL, and why one could never have done both jobs.
		//
		// `credentialId` is spent on the broker's SERVER-SIDE calls — the tool
		// tree arrives as `GET /repos/*/tarball/*` performed inside
		// OpenRegister. That only works for a host-locked PROXY credential,
		// whose whole point is that `resolveInjectable()` refuses it and its
		// secret never crosses into the container.
		//
		// A push needs the opposite: git speaks the smart-HTTP pack protocol,
		// so there is no single call to proxy and the token has to BE in the
		// container. That is an `inject_only` credential, which the broker will
		// hand over and will not proxy.
		//
		// The two postures are mutually exclusive by construction, so a single
		// `credentialId` cannot express a stage that both fetches a private tool
		// tree and pushes. Hence a second key.
		//
		// ⚠️ It authenticates the CLONE as well as the push — a private target
		// needs a token before the command ever runs. The name says `push`
		// because declaring one is what makes the stage a writing stage; it is
		// not a claim that the token is used only at the end.
		//
		// Absent, it falls back to `credentialId`, so every read-only stage that
		// shipped before this behaves exactly as it did.
		$pushCredentialId = trim($this->render(template: (string)($config['pushCredentialId'] ?? ''), json: $json));

		// ASYNC is a whole second transport; see dispatchAsyncFor().
		if (($config['async'] ?? false) === true) {
			return $this->dispatchAsyncFor($config, $json, $owner, $credentialId, $pushCredentialId);
		}

		$result = $this->stages->dispatch(
			repo: $this->render(template: (string)$config['repo'], json: $json),
			ref: $this->render(template: (string)$config['ref'], json: $json),
			command: array_map(
				fn (string $argument): string => $this->render(template: $argument, json: $json),
				array_values((array)$config['command'])
			),
			uid: $owner,
			credentialId: $credentialId,
			timeoutMs: (int)($config['timeoutMs'] ?? 0),
			// The tool tree, when the command does not live in the tree it runs
			// over. hydra's gate runner is that case: it takes the app to gate
			// as an argument and finds its own helpers beside itself, so gating
			// an app needs hydra's scripts AND the app's tree. The alternative
			// is every app vendoring 3,599 lines of gate runner, which would
			// drift the day after it was copied.
			toolRepo: $this->render(template: (string)($config['toolRepo'] ?? ''), json: $json),
			toolRef: $this->render(template: (string)($config['toolRef'] ?? ''), json: $json),
			// THE WRITE DECLARATION. Its PRESENCE is the switch: the runner
			// withholds the forge credential from the command child and
			// performs the push itself, behind `pushGuard`. A stage that omits
			// it is exactly as read-only as every stage that shipped before this
			// key existed.
			push: $this->renderPush(push: ($config['push'] ?? []), json: $json),
			pushCredentialId: $pushCredentialId,
			// THE MODEL CREDENTIAL. A third credential rather than a reuse of
			// either above, because it is a different vendor: the forge token
			// clones the tree, this one lets the command talk to a model. A
			// stage that names none runs without one, which is every stage that
			// existed before this key.
			llmCredentialId: trim($this->render(template: (string)($config['llmCredentialId'] ?? ''), json: $json)),
			// ASYNC. The stage is STARTED and a handle comes back, instead of
			// the run holding the queue worker for the whole thing.
			//
			// `FlowRunWorker` advances queued runs serially in one PHP process,
			// so a synchronous stage blocks every other flow in that pass —
			// including the lock reaper that exists to clean up after stuck
			// work — and it makes a slot pool decorative, because N slots
			// cannot produce N agents while the thing holding a slot occupies
			// the only worker.
			//
			// ⚠️ The result shape CHANGES with this flag, deliberately: an
			// async dispatch writes `job: {id, status}` and NO `exitCode`, so
			// nothing downstream can read an acknowledgement as a verdict. Pair
			// it with a wait and a `hermiq.workload-collect`.
		);

		return $this->attribute(
			result: $result,
			owner: $owner,
			credentialId: $credentialId,
			pushCredentialId: $pushCredentialId
		);

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
	 * @param array $config The step configuration.
	 * @param array $json The item's record.
	 * @param string $owner The resolved run owner.
	 * @param string $credentialId The rendered broker credential.
	 * @param string $pushCredentialId The rendered injectable push credential.
	 *
	 * @return array The handle, attributed.
	 *
	 * @throws UnexpectedValueException When no async transport is available.
	 */
	private function dispatchAsyncFor(
		array $config,
		array $json,
		string $owner,
		string $credentialId,
		string $pushCredentialId,
	): array {
		if ($this->asyncStages === null) {
			// Refuse rather than silently running synchronously: a step that
			// asked for a handle and got a blocking call back would hold the
			// queue worker for its whole duration while the flow waits for a
			// `job` key that never arrives.
			throw new UnexpectedValueException(
				$this->l10n->t('This step asks for an asynchronous dispatch, but no async transport is available.')
			);
		}

		return $this->attribute(
			result: $this->asyncStages->dispatchAsync(
				repo: $this->render(template: (string)($config['repo'] ?? ''), json: $json),
				ref: $this->render(template: (string)($config['ref'] ?? ''), json: $json),
				command: array_map(
					fn (string $argument): string => $this->render(template: $argument, json: $json),
					array_values((array)($config['command'] ?? []))
				),
				uid: $owner,
				credentialId: $credentialId,
				timeoutMs: (int)($config['timeoutMs'] ?? 0),
				toolRepo: $this->render(template: (string)($config['toolRepo'] ?? ''), json: $json),
				toolRef: $this->render(template: (string)($config['toolRef'] ?? ''), json: $json),
				push: $this->renderPush(push: ($config['push'] ?? []), json: $json),
				pushCredentialId: $pushCredentialId,
				llmCredentialId: trim($this->render(template: (string)($config['llmCredentialId'] ?? ''), json: $json))
			),
			owner: $owner,
			credentialId: $credentialId,
			pushCredentialId: $pushCredentialId
		);
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
	 * @param array $result The stage result.
	 * @param string $owner The resolved run owner.
	 * @param string $credentialId The broker credential, or ''.
	 * @param string $pushCredentialId The injectable credential, or ''.
	 *
	 * @return array The result, with attribution added.
	 */
	private function attribute(array $result, string $owner, string $credentialId, string $pushCredentialId): array {
		$usedCredential = $credentialId;
		if ($pushCredentialId !== '') {
			$usedCredential = $pushCredentialId;
		}

		$credentialOwner = null;
		$credentialName = null;
		if ($usedCredential !== '') {
			$credentialOwner = $owner;
			$credentialName = $usedCredential;
		}

		$result['owner'] = $owner;
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

		$command = ($config['command'] ?? null);
		if (is_array($command) === false || $command === [] || trim((string)($command[0] ?? '')) === '') {
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

		if (isset($push['scope']) === true && is_array($push['scope']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('A workload step\'s push "scope" must be a list of path prefixes.')
			);
		}

	}//end assertPushConfigured()

	/**
	 * Render the push declaration's placeholders against one item.
	 *
	 * Every value a flow author writes is a template, and the push declaration
	 * is the one place where that matters most: `branch` and `issue` together
	 * ARE the allowlist `pushGuard` enforces, and both are derived per item —
	 * a fan-out over issues writes a different branch each time.
	 *
	 * A LIST is rendered element-wise (`scope` is a list of path prefixes); a
	 * nested object is refused by omission rather than walked, because the
	 * runner's contract is flat and quietly passing a shape it ignores is the
	 * dead-config failure this repository keeps meeting.
	 *
	 * @param mixed $push The configured push declaration.
	 * @param array $json The item's record.
	 *
	 * @return array The rendered declaration, or [] when there is none.
	 */
	private function renderPush(mixed $push, array $json): array {
		if (is_array($push) === false || $push === []) {
			return [];
		}

		$out = [];
		foreach ($push as $key => $value) {
			if (is_array($value) === true) {
				$out[$key] = array_values(
					array_map(
						fn (mixed $entry): string => $this->render(template: (string)$entry, json: $json),
						array_filter($value, static fn (mixed $entry): bool => is_array($entry) === false)
					)
				);
				continue;
			}

			$out[$key] = $this->render(template: (string)$value, json: $json);
		}

		return $out;
	}//end renderPush()

	/**
	 * Substitute `{{dotted.path}}` placeholders from the item's json.
	 *
	 * @param string $template The template.
	 * @param array $json The item's record.
	 *
	 * @return string The rendered string.
	 */
	private function render(string $template, array $json): string {
		return (string)preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/',
			static function (array $matches) use ($json): string {
				$value = $json;
				foreach (explode('.', $matches[1]) as $segment) {
					if (is_array($value) === false || array_key_exists($segment, $value) === false) {
						return '';
					}

					$value = $value[$segment];
				}

				if (is_array($value) === true) {
					return (string)json_encode($value);
				}

				return (string)$value;
			},
			$template
		);

	}//end render()
}//end class
