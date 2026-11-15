<?php

/**
 * One item's workload stage: the step configuration and the item.
 *
 * Built per item by the workload step; it renders the item's stage request
 * from the step configuration with one placeholder substitution.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\Hermiq\Service\StageOptions;
use OCA\Hermiq\Service\StageRequest;

/**
 * The configuration and placeholders one item's stage is built from.
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */
final class WorkloadStageRun {

	/**
	 * The item's placeholder substitution.
	 *
	 * @var ItemPlaceholders
	 */
	private readonly ItemPlaceholders $placeholders;

	/**
	 * Constructor.
	 *
	 * @param array $config The step configuration.
	 * @param array $json   The item's record.
	 */
	public function __construct(
		public readonly array $config,
		array $json,
	) {
		$this->placeholders = new ItemPlaceholders(json: $json);
	}//end __construct()

	/**
	 * A configured value, rendered against the item ('' when not configured).
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The rendered value.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function rendered(string $key): string {
		return $this->placeholders->render(template: (string)($this->config[$key] ?? ''));
	}//end rendered()

	/**
	 * A configured value, rendered against the item and trimmed.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The trimmed rendered value.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function trimmed(string $key): string {
		return trim($this->rendered(key: $key));
	}//end trimmed()

	/**
	 * Whether the step asks for an asynchronous dispatch.
	 *
	 * @return boolean True when `async` is set to true.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function isAsync(): bool {
		return ($this->config['async'] ?? false) === true;
	}//end isAsync()

	/**
	 * The stage one item asks for: the rendered target, command and options.
	 *
	 * @param string $owner The identity the stage acts as.
	 *
	 * @return StageRequest The stage.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function stageRequest(string $owner): StageRequest {
		return new StageRequest(
			repo: $this->rendered(key: 'repo'),
			ref: $this->rendered(key: 'ref'),
			command: array_map(
				fn (string $argument): string => $this->placeholders->render(template: $argument),
				array_values((array)($this->config['command'] ?? []))
			),
			uid: $owner,
			options: $this->options()
		);
	}//end stageRequest()

	/**
	 * The stage's options: credentials, tool tree, push, model credential, collect, job key.
	 *
	 * The two forge credentials are RENDERED, which could never have been one.
	 * The reasoning is long and belongs with the concept rather than in the
	 * middle of a dispatch; it is kept verbatim below as line comments,
	 * because one of them contains a path that would close this block.
	 *
	 * @return StageOptions The options.
	 */
	private function options(): StageOptions {
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

		return new StageOptions(
			credentialId: $this->trimmed(key: 'credentialId'),
			timeoutMs: (int)($this->config['timeoutMs'] ?? 0),
			// The tool tree, when the command does not live in the tree it runs
			// over. hydra's gate runner is that case: it takes the app to gate
			// as an argument and finds its own helpers beside itself, so gating
			// an app needs hydra's scripts AND the app's tree. The alternative
			// is every app vendoring 3,599 lines of gate runner, which would
			// drift the day after it was copied.
			toolRepo: $this->rendered(key: 'toolRepo'),
			toolRef: $this->rendered(key: 'toolRef'),
			// THE WRITE DECLARATION. Its PRESENCE is the switch: the runner
			// withholds the forge credential from the command child and
			// performs the push itself, behind `pushGuard`. A stage that omits
			// it is exactly as read-only as every stage that shipped before this
			// key existed.
			push: $this->push(push: ($this->config['push'] ?? [])),
			pushCredentialId: $this->trimmed(key: 'pushCredentialId'),
			// THE MODEL CREDENTIAL. A third credential rather than a reuse of
			// either above, because it is a different vendor: the forge token
			// clones the tree, this one lets the command talk to a model. A
			// stage that names none runs without one, which is every stage that
			// existed before this key.
			llmCredentialId: $this->trimmed(key: 'llmCredentialId'),
			collect: $this->collect(),
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
			jobKey: $this->jobKey(),
		);
	}//end options()

	/**
	 * The rendered job key of an async stage; a synchronous stage sends none.
	 *
	 * @return string The job key, or ''.
	 */
	private function jobKey(): string {
		if ($this->isAsync() === false) {
			return '';
		}

		return $this->trimmed(key: 'jobKey');
	}//end jobKey()

	/**
	 * What the stage PRODUCED, read out of the clone before it is discarded.
	 *
	 * A reviewer that crashed, ran out of turns, or answered in prose exits 0
	 * exactly like one that reviewed — the artefact is the only thing that
	 * tells them apart, which is why a verdict step reads a file rather than an
	 * exit code.
	 *
	 * Both transports call this, because a stage that produced a verdict must
	 * hand it back whichever one carried it; an async path that quietly dropped
	 * it would judge on nothing.
	 *
	 * ⚠️ USE THE ALIAS FORM. `["hydra-verdict.json"]` keys the file by its own
	 * path, and a flow addresses data with a DOTTED path, so
	 * `files.hydra-verdict.json` splits in the wrong places and is unreachable
	 * — the artefact arrives and the consumer still cannot see it.
	 * `{verdict: "hydra-verdict.json"}` yields `files.verdict`, which a flow
	 * can walk.
	 *
	 * @return array The collect declaration, or [] when the stage produces nothing.
	 */
	private function collect(): array {
		return (array)($this->config['collect'] ?? []);
	}//end collect()

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
	 *
	 * @return array The rendered declaration, or [] when there is none.
	 */
	private function push(mixed $push): array {
		if (is_array($push) === false || $push === []) {
			return [];
		}

		$out = [];
		foreach ($push as $key => $value) {
			if (is_array($value) === true) {
				$out[$key] = $this->pushList(list: $value);
				continue;
			}

			$out[$key] = $this->placeholders->render(template: (string)$value);
		}

		return $out;
	}//end push()

	/**
	 * Render a list value of the push declaration element-wise, dropping nested lists.
	 *
	 * @param array $list The list value.
	 *
	 * @return array<int, string> The rendered list.
	 */
	private function pushList(array $list): array {
		return array_values(
			array_map(
				fn (mixed $entry): string => $this->placeholders->render(template: (string)$entry),
				array_filter($list, static fn (mixed $entry): bool => is_array($entry) === false)
			)
		);
	}//end pushList()
}//end class
