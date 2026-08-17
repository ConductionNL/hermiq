<?php

/**
 * Dispatches one filesystem workload to the `hermiq-llm-runner` ExApp.
 *
 * The transport half of `hermiq.workload-step`. The ExApp's `POST /stage`
 * clones a ref, runs an allowlisted command over it and returns
 * `{exitCode, output, ref}`; this is what gets it there and what decides
 * which of its failure shapes is a step failure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCP\Server;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runs one stage in the runner ExApp and returns its structured result.
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */
class StageDispatchService {

	/**
	 * AppAPI's public dispatch surface.
	 *
	 * @var string
	 */
	// PROTECTED, not private: `AsyncStageDispatchService` extends this to add
	// the start/collect pair, and it must address the SAME ExApp on the SAME
	// route with the SAME ceilings. Copying these into the subclass would let
	// the two halves of one transport drift apart silently — the async path
	// could end up pointed at a route the sync path no longer uses.
	protected const APP_API_PUBLIC_FUNCTIONS = 'OCA\\AppAPI\\PublicFunctions';

	/**
	 * App id of the runner ExApp.
	 *
	 * @var string
	 */
	protected const RUNNER_EXAPP_ID = 'hermiq-llm-runner';

	/**
	 * The runner's stage route.
	 *
	 * @var string
	 */
	protected const RUNNER_ROUTE = '/stage';

	/**
	 * Default ceiling for one stage, in milliseconds.
	 *
	 * A gate run over a real tree does a `composer install` and 59 gates, so
	 * this is minutes rather than seconds. It matches the runner's own default.
	 *
	 * @var int
	 */
	protected const DEFAULT_STAGE_TIMEOUT_MS = (30 * 60 * 1000);

	/**
	 * Seconds added to the runner's own ceiling for the AppAPI request.
	 *
	 * The transport timeout must EXCEED the workload timeout by construction,
	 * so that when a stage overruns it is the runner that kills it and reports
	 * why — rather than the caller giving up first and turning a real reason
	 * into a generic timeout.
	 *
	 * @var int
	 */
	protected const TRANSPORT_SLACK_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger For operator-facing diagnostics.
	 * @param RunTokenService $runTokenService Mints the per-run token the governed egress
	 *                                         proxy needs. Without one the sidecar has no
	 *                                         identity to present and the PDP refuses every
	 *                                         CONNECT before it evaluates any policy.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly RunTokenService $runTokenService,
	) {

	}//end __construct()

	/**
	 * Run one stage and return its result.
	 *
	 * Every failure here means the workload COULD NOT BE RUN, and every one of
	 * them throws. A command that ran and exited non-zero is not a failure —
	 * it is the result, and it is returned.
	 *
	 * AppAPI's two defaults are traps and both are handled explicitly, neither
	 * being visible in `exAppRequest()`'s signature:
	 *
	 *   1. `timeout` defaults to 3 SECONDS while a stage runs for minutes.
	 *      Omitting it would make the feature 0% functional — every stage would
	 *      fail at 3s while the container ran on to completion.
	 *   2. AppAPI NEVER THROWS. Failure is the RETURN VALUE in three shapes: a
	 *      caught exception returns `['error' => …]`, a missing ExApp returns
	 *      `['error' => 'ExApp … not found']`, and `http_errors => false` makes
	 *      a 502 an ordinary response. So the checks below run in a
	 *      load-bearing order — array, then status, then shape. Any other order
	 *      reads an error string as a stage result.
	 *
	 * @param string $repo Clone URL of the tree the command runs OVER.
	 * @param string $ref The ref to check out.
	 * @param array $command The command and its arguments.
	 * @param string|null $uid The acting user's UID.
	 * @param string $credentialId Broker credential for the clone, or '' for a public repo.
	 * @param int $timeoutMs Ceiling for the stage; 0 for the default.
	 * @param string $toolRepo Clone URL of the tree the COMMAND comes from, when it is
	 *                         not the target. hydra's gate runner is the case this
	 *                         exists for: it takes the tree it gates as an argument
	 *                         and resolves its own helpers out of its own checkout, so
	 *                         gating an app needs both trees at once.
	 * @param string $toolRef Ref for the tool tree; its default branch when empty.
	 * @param array $push Declares that this stage may WRITE:
	 *                    `{branch, issue, scope,
	 *                    allowedRepo, message}`. Empty
	 *                    leaves the stage read-only. Its
	 *                    presence changes the runner's
	 *                    posture — the command child loses
	 *                    the forge credential and the runner
	 *                    performs the push itself, behind
	 *                    the branch/repository/diff fences.
	 * @param string $pushCredentialId The INJECTABLE credential, when it is not the same
	 *                                 one the broker uses server-side. `$credentialId` is spent
	 *                                 on `request()` calls the broker makes itself — the tool
	 *                                 tarball — which only a host-locked PROXY credential can
	 *                                 serve, and `resolveInjectable()` refuses that shape by
	 *                                 design. git speaks the pack protocol, so a push needs the
	 *                                 token IN the container, i.e. an `inject_only` credential.
	 *                                 The two are mutually exclusive, so a stage that fetches a
	 *                                 private tool tree AND pushes needs both. Empty falls back
	 *                                 to `$credentialId`.
	 * @param string $llmCredentialId The INJECTABLE credential for a model, when the stage's
	 *                                command is one that talks to one. Distinct from both of
	 *                                the above because it is a different vendor entirely: the
	 *                                forge token clones, this one lets `claude` answer. Only an
	 *                                `inject_only` credential resolves — `resolveInjectable()`
	 *                                returns null for a host-locked proxy entry by design — so
	 *                                this cannot be used to pull a proxied secret into the
	 *                                container. Empty means the stage runs without a model,
	 *                                which is every stage that shipped before this parameter.
	 * @param array $collect Artefacts to read back out of the clone, or [] to collect
	 *                       nothing. Passed through to the runner only when non-empty.
	 *
	 * @return array{exitCode: int, output: string, ref: string, files?: array} The stage result.
	 *
	 * @throws RuntimeException When the stage could not be run.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)           OCP\Server::get is deliberate lazy resolution
	 *   of AppAPI and the optional OpenRegister broker, so this class stays constructible
	 *   when either is absent.
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The tenth parameter takes this one
	 *   over the threshold, and the remedy the rule implies — bundling some of them into
	 *   an array — is the exact shape in which a field has already been silently dropped
	 *   at this boundary: `toolRepo` existed on both sides and not IN it for a whole
	 *   release, and the symptom was a missing FILE. Typed parameters make that a fatal
	 *   at the call site; an untyped bag makes a misspelt key a no-op. The rule is right
	 *   in general and wrong for a transport seam with this file's measured history.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-command-that-ran-and-failed-is-data-not-a-step-failure
	 */
	public function dispatch(
		string $repo,
		string $ref,
		array $command,
		?string $uid = null,
		string $credentialId = '',
		int $timeoutMs = 0,
		string $toolRepo = '',
		string $toolRef = '',
		array $push = [],
		string $pushCredentialId = '',
		string $llmCredentialId = '',
		array $collect = [],
	): array {
		$ceiling = self::DEFAULT_STAGE_TIMEOUT_MS;
		if ($timeoutMs > 0) {
			$ceiling = $timeoutMs;
		}

		$params = $this->buildParams(
			repo: $repo,
			ref: $ref,
			command: $command,
			uid: $uid,
			credentialId: $credentialId,
			ceiling: $ceiling,
			toolRepo: $toolRepo,
			toolRef: $toolRef,
			push: $push,
			pushCredentialId: $pushCredentialId,
			llmCredentialId: $llmCredentialId,
			collect: $collect
		);

		$result = Server::get(self::APP_API_PUBLIC_FUNCTIONS)->exAppRequest(
			self::RUNNER_EXAPP_ID,
			self::RUNNER_ROUTE,
			$uid,
			'POST',
			$params,
			['timeout' => (intdiv($ceiling, 1000) + self::TRANSPORT_SLACK_SECONDS)]
		);

		// Check 1 — the never-throws failure channel. MUST precede any body read.
		if (is_array($result) === true) {
			$this->logger->warning(
				'[StageDispatchService] stage dispatch failed at the AppAPI transport',
				['reason' => (string)($result['error'] ?? 'unknown')]
			);

			throw new RuntimeException(
				'The workload could not reach the "' . self::RUNNER_EXAPP_ID . '" ExApp. '
				. 'Check that the ExApp is running.'
			);
		}

		// Check 2 — `http_errors => false`, so a 4xx/5xx is an ordinary response.
		// The runner answers 502 when it could not carry the stage out, which is
		// exactly the distinction this method exists to preserve: a stage that
		// RAN and failed comes back 200 with a non-zero exit code.
		$status = $result->getStatusCode();
		if ($status < 200 || $status > 299) {
			$reason = $this->reasonFrom(body: (string)$result->getBody());
			$this->logger->warning(
				'[StageDispatchService] the runner refused or could not run the stage',
				[
					'status' => $status,
					'reason' => $reason,
				]
			);

			throw new RuntimeException('The workload could not be run: ' . $reason);
		}

		// Check 3 — only now is the body a stage result.
		return $this->mapResult(body: (string)$result->getBody());
	}//end dispatch()

	/**
	 * Mint the per-run token a stage needs to get out through the governed proxy.
	 *
	 * `/run` has built a per-run proxy URL since governed egress shipped;
	 * `/stage` never did. Behind the CONNECT proxy that is not a smaller fence
	 * but no route at all — the PDP refuses a token-less CONNECT with
	 * `no_run_token` before it evaluates any policy, and the symptom is a
	 * `git clone` failure that points at the forge rather than at policy.
	 *
	 * The TTL is the stage's OWN ceiling, not the LLM turn's 150 seconds. A
	 * stage runs for up to thirty minutes, so a turn-length token expires
	 * mid-workload: the clone at the start succeeds and the push at the end is
	 * refused `invalid_token`.
	 *
	 * ⚠️ The token lives in `ICacheFactory::createDistributed()`. With no
	 * `memcache.distributed` configured that falls back to APCu, which is PER
	 * PROCESS POOL — so a stage dispatched from a cron-mode background job mints
	 * into the CLI pool while the PDP reads the web pool, and every CONNECT is
	 * refused. Measured on a live instance: a token minted in a CLI process and
	 * POSTed to the PDP within the same second came back 401 `invalid_token`.
	 *
	 * @param string|null $uid The acting user's UID.
	 * @param int $ceiling The stage's ceiling in milliseconds.
	 *
	 * @return string The plaintext run token.
	 */
	private function mintEgressIdentity(?string $uid, int $ceiling): string {
		return $this->runTokenService->mint(
			runId: 'stage-' . bin2hex(random_bytes(8)),
			agentId: '',
			userId: (string)$uid,
			conversationId: '',
			ttlSeconds: (intdiv($ceiling, 1000) + self::TRANSPORT_SLACK_SECONDS)
		);

	}//end mintEgressIdentity()

	/**
	 * Add the TOOL TREE to a stage payload, as a clone or as broker-fetched bytes.
	 *
	 * Extracted from buildParams() because it is a self-contained decision and
	 * buildParams() had grown past the length gate: what tree the command comes
	 * from is a different question from what the stage is allowed to do.
	 *
	 * @param array $params The payload so far.
	 * @param string|null $uid The acting user's UID.
	 * @param string $credentialId Broker credential, or ''.
	 * @param string $toolRepo Tool tree URL, or ''.
	 * @param string $toolRef Tool tree ref, or ''.
	 *
	 * @return array The payload, with the tool tree added when there is one.
	 */
	private function withToolTree(
		array $params,
		?string $uid,
		string $credentialId,
		string $toolRepo,
		string $toolRef,
	): array {
		if ($toolRepo !== '') {
			$params['toolRepo'] = $toolRepo;
			if ($toolRef !== '') {
				$params['toolRef'] = $toolRef;
			}

			// A PRIVATE tool tree cannot be cloned by the ExApp: the brokered
			// forge credential is a host-locked proxy credential, so
			// `resolveInjectable()` returns null for it BY DESIGN and no token
			// can be handed over. The broker can still FETCH, though, and
			// `GET /repos/*/tarball/*` is already covered by its existing
			// `GET /repos/*` rule — so the archive is pulled server-side and
			// only the BYTES cross into the container.
			//
			// The usual objection to a tarball is that it carries no git
			// history, so `--scope-to-diff` diffs against nothing and reports
			// zero failures. That objection is about the TARGET and does not
			// apply here: the tool tree is scripts. The target is cloned
			// normally with its full history and needs no credential, because
			// the repositories being gated are public. Splitting the two is
			// what makes the credential question go away instead of trading it
			// off.
			if ($credentialId !== '') {
				$archiveRef = 'HEAD';
				if ($toolRef !== '') {
					$archiveRef = $toolRef;
				}

				$tarball = $this->fetchToolArchive(
					credentialId: $credentialId,
					uid: $uid,
					repo: $toolRepo,
					ref: $archiveRef
				);

				if ($tarball !== null) {
					$params['toolTarball'] = $tarball;
					// The ExApp prefers the archive, but leaving `toolRepo` in
					// place keeps the log line meaningful about WHICH tree this
					// is, which is otherwise unrecoverable from a blob.
					unset($params['toolRef']);
				}
			}//end if
		}//end if

		return $params;
	}//end withToolTree()

	/**
	 * Assemble the `/stage` payload.
	 *
	 * Extracted from `dispatch()` because building the payload and interpreting
	 * the response are two different jobs, and keeping them in one method put it
	 * past the complexity gate — deservedly. The tool-tree and credential
	 * branches all belong to "what do we send"; the three load-bearing checks
	 * after the call all belong to "what came back".
	 *
	 * `protected`, matching `mapResult()` and `reasonFrom()` below and for the
	 * same stated reason: a shape check nothing exercises is a shape check that
	 * silently stops holding. This method decides whether a stage carries an
	 * egress identity and whether it may write — neither of which is visible
	 * from the response-mapping seams, and both of which are exactly the kind of
	 * field that has been silently dropped at a boundary here before.
	 *
	 * @param string $repo Clone URL of the tree the command runs OVER.
	 * @param string $ref The ref to check out.
	 * @param array $command The command and its arguments.
	 * @param string|null $uid The acting user's UID.
	 * @param string $credentialId Broker credential, or '' for a public repo.
	 * @param int $ceiling Stage timeout in milliseconds.
	 * @param string $toolRepo Tool tree URL, or ''.
	 * @param string $toolRef Tool tree ref, or ''.
	 * @param array $push Push declaration, or [] for a read-only stage.
	 * @param string $pushCredentialId The injectable credential, or '' to reuse $credentialId.
	 * @param string $llmCredentialId The injectable model credential, or '' for a stage that
	 *                                runs without a model.
	 * @param array $collect Artefacts to read back out of the clone, or [].
	 *
	 * @return array The request payload.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors `dispatch()`; see the reason
	 *   stated there. This method is the boundary in question.
	 */
	protected function buildParams(
		string $repo,
		string $ref,
		array $command,
		?string $uid,
		string $credentialId,
		int $ceiling,
		string $toolRepo,
		string $toolRef,
		array $push = [],
		string $pushCredentialId = '',
		string $llmCredentialId = '',
		array $collect = [],
	): array {
		$params = [
			'repo' => $repo,
			'ref' => $ref,
			'command' => array_values($command),
			'timeoutMs' => $ceiling,
			// The per-run egress identity. See mintEgressIdentity().
			'runToken' => $this->mintEgressIdentity(uid: $uid, ceiling: $ceiling),
		];

		// A stage that may WRITE says so, and saying so is what makes the
		// runner withhold the credential from the command child and perform the
		// push itself behind its own fences. An absent `push` leaves the stage
		// exactly as read-only as it has always been.
		if ($push !== []) {
			$params['push'] = $push;
		}

		$params = $this->withCollect(params: $params, collect: $collect);

		$params = $this->withToolTree(
			params: $params,
			uid: $uid,
			credentialId: $credentialId,
			toolRepo: $toolRepo,
			toolRef: $toolRef
		);

		// WHICH credential is asked to INJECT. Not necessarily the one above:
		// `withToolTree()` has just spent `$credentialId` on a broker-side
		// `request()`, which only a host-locked PROXY credential can serve —
		// and `resolveInjectable()` refuses that shape by design. So a stage
		// that fetches a private tool tree AND pushes needs a second,
		// `inject_only` credential, and this is where it is chosen.
		//
		// Falling back to `$credentialId` keeps every stage that shipped before
		// this parameter existed on exactly the path it was on.
		$injectCredentialId = $credentialId;
		if ($pushCredentialId !== '') {
			$injectCredentialId = $pushCredentialId;
		}

		if ($injectCredentialId !== '') {
			// An INJECTABLE token, if this credential is one. Most are not: the
			// brokered forge credential is a host-locked proxy credential, and
			// `resolveInjectable()` returns null for it BY DESIGN — its secret
			// never leaving OpenRegister is the property that makes the proxy
			// path worth having.
			//
			// A null is therefore a ROUTING signal, not a denial, and it must
			// NOT fail the stage. Most targets this pipeline gates are public
			// and need no token at all; the tool tree, which is the private
			// half, already arrived as an archive fetched server-side. Failing
			// here would refuse a stage that has everything it needs.
			//
			// When a target really is private and no token could be injected,
			// the clone fails with git's own words — which is a far better
			// diagnostic than a credential error raised before anything was
			// attempted.
			$token = $this->resolveForgeToken(credentialId: $injectCredentialId, uid: $uid);
			if ($token !== null) {
				// The token reaches the runner in the payload and the runner
				// puts it in the child ENVIRONMENT behind `GIT_ASKPASS` — never
				// on argv, never in a file that outlives the run.
				$params['forgeToken'] = $token;
				$params['forgeUser'] = 'x-access-token';
			}
		}//end if

		$params = $this->withModelCredential(
			params: $params,
			uid: $uid,
			llmCredentialId: $llmCredentialId
		);

		return $params;
	}//end buildParams()

	/**
	 * Attach the model credential, when the stage's command is one that talks to a model.
	 *
	 * Extracted from buildParams() because that method crossed the length gate,
	 * and this is the arm that can be lifted out whole: it is one decision with
	 * one input and one effect.
	 *
	 * @param array $params The stage payload so far.
	 * @param string|null $uid The acting user.
	 * @param string $llmCredentialId The injectable model credential, or '' for none.
	 *
	 * @return array The payload, with `credentialEnv` when one resolved.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	private function withModelCredential(array $params, ?string $uid, string $llmCredentialId): array {
		// THE MODEL CREDENTIAL — the other half of an agent that has a tree.
		//
		// `/run` has been able to give `claude -p` a token since the anthropic
		// provider shipped (`ProviderFactory::…` sends `credentialEnv`, and the
		// runner filters it through `selectCredentialEnv()`), but that path has
		// no repository. `/stage` clones one and could not call a model. The two
		// halves existed in separate endpoints, which is why hydra's build stage
		// could lint a checkout and never author a change in it.
		//
		// 🔑 ONLY AN INJECT-ONLY CREDENTIAL CAN ARRIVE HERE, and that is the
		// guard rather than a limitation. `resolveInjectable()` returns null for
		// a host-locked PROXY credential by design, so pointing this at the
		// `anthropic` API-key entry yields nothing and the stage runs without a
		// model — it cannot be used to smuggle a proxied secret into a container.
		// The resolvable shape is `anthropic-cli`, whose secret is a Claude Max
		// subscription token, which is why the env key is the one the CLI reads.
		//
		// A null is a ROUTING signal, exactly as it is for the forge token in
		// buildParams(): a stage whose command needs no model is a normal stage,
		// and failing here would refuse one that has everything it needs.
		if ($llmCredentialId === '') {
			return $params;
		}

		$llmToken = $this->resolveForgeToken(credentialId: $llmCredentialId, uid: $uid);
		if ($llmToken !== null) {
			$params['credentialEnv'] = ['CLAUDE_CODE_OAUTH_TOKEN' => $llmToken];
		}

		return $params;

	}//end withModelCredential()

	/**
	 * Fetch a tool tree as a base64 archive through the broker.
	 *
	 * This is the answer to "how does a PRIVATE tool tree reach the ExApp
	 * without its credential". It does not: the broker calls the forge
	 * server-side and hands back bytes, so the secret never leaves
	 * OpenRegister — which is the property that made the proxy credential worth
	 * having in the first place.
	 *
	 * Returns null rather than throwing when the archive cannot be had, because
	 * the caller still has `toolRepo` to fall back on: a PUBLIC tool tree clones
	 * perfectly well without any of this, and failing the stage over an
	 * optimisation that was not needed would be worse than not attempting it.
	 * A private tool tree then fails at the clone, with git's own reason.
	 *
	 * `protected` for the same reason `mapResult()` and `buildParams()` are:
	 * WHICH credential is spent here rather than on the injection is a decision
	 * with no other observable seam, and a test that cannot reach it can only
	 * assert that some broker call happened — which is true whichever id was
	 * passed.
	 *
	 * @param string $credentialId The broker credential.
	 * @param string|null $uid The acting user.
	 * @param string $repo The tool repository URL.
	 * @param string $ref The ref to archive.
	 *
	 * @return string|null Base64 `.tar.gz`, or null when it could not be fetched.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) See dispatch().
	 */
	protected function fetchToolArchive(string $credentialId, ?string $uid, string $repo, string $ref): ?string {
		if (class_exists(BrokerHttpClient::BROKER_CLASS) === false) {
			return null;
		}

		// `https://github.com/owner/name(.git)` -> `owner/name`.
		$path = trim((string)parse_url($repo, PHP_URL_PATH), '/');
		$path = preg_replace('/\.git$/', '', $path);
		if ($path === null || $path === '' || substr_count($path, '/') !== 1) {
			return null;
		}

		try {
			$response = Server::get(BrokerHttpClient::BROKER_CLASS)->request(
				$credentialId,
				BrokerHttpClient::APP_ID,
				'GET',
				'/repos/' . $path . '/tarball/' . $ref,
				[],
				null,
				$uid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[StageDispatchService] the tool archive could not be fetched through the broker',
				['reason' => $e->getMessage()]
			);

			return null;
		}

		$status = (int)($response['status'] ?? 0);
		$body = (string)($response['body'] ?? '');
		if ($status < 200 || $status > 299 || $body === '') {
			$this->logger->warning(
				'[StageDispatchService] the forge did not return a tool archive',
				['status' => $status]
			);

			return null;
		}

		return base64_encode($body);
	}//end fetchToolArchive()

	/**
	 * Resolve the forge token for the clone through OpenRegister's broker.
	 *
	 * ⚠️ This only works for an `inject_only` credential. A HOST-LOCKED PROXY
	 * credential — the shape the `github` broker credential uses — is refused
	 * by `resolveInjectable()` on purpose: its secret never leaves
	 * OpenRegister, which is the property that makes the proxy path worth
	 * having. That refusal is surfaced verbatim rather than worked around,
	 * because the alternative is a credential posture decision and it belongs
	 * to the operator, not to this method.
	 *
	 * `protected` so a test can observe WHICH credential was asked to inject.
	 * With `pushCredentialId` in play that is the whole decision, and it is
	 * invisible from every other seam: both ids reach the broker, and a test
	 * that cannot tell them apart cannot tell the fixed code from the code that
	 * passed one id to both calls.
	 *
	 * @param string $credentialId The broker credential UUID.
	 * @param string|null $uid The acting user's UID.
	 *
	 * @return string|null The resolved token, or null when this credential is not injectable —
	 *                     a routing signal meaning "use the broker instead", not a denial. Never logged.
	 *
	 * @throws RuntimeException When the broker is absent or refuses.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) See dispatch().
	 */
	protected function resolveForgeToken(string $credentialId, ?string $uid): ?string {
		if (class_exists(BrokerHttpClient::BROKER_CLASS) === false) {
			throw new RuntimeException(
				'The workload needs a forge credential and the OpenRegister credential broker is not '
				. 'available, so it cannot be resolved.'
			);
		}

		try {
			$token = Server::get(BrokerHttpClient::BROKER_CLASS)
				->resolveInjectable($credentialId, BrokerHttpClient::APP_ID, $uid);
		} catch (Throwable $e) {
			// NOT fatal, for the same reason a null is not: an INJECTED token is
			// an optimisation for a private TARGET, and most targets this
			// pipeline gates are public. The private half — the tool tree —
			// already arrived as an archive the broker fetched server-side.
			//
			// Found by running the assembled sequencer: the broker answered
			// `Request not permitted` here and the whole tick died at
			// `run-stage`, having already claimed a slot and taken a lock, over
			// a token the run did not need. Refusing a stage that has
			// everything it needs is the worse failure.
			//
			// The reason is logged in full — the broker's denials name the guard
			// that refused, which is the most useful thing an operator can be
			// told, and none of them contain the secret. A genuinely private
			// target then fails at the CLONE, with git's own words.
			$this->logger->warning(
				'[StageDispatchService] the broker would not inject a forge token; the clone will be '
				. 'unauthenticated',
				[
					'credentialId' => $credentialId,
					'reason' => $e->getMessage(),
				]
			);

			return null;
		}//end try

		if (is_string($token) === false || $token === '') {
			// NOT an error. `resolveInjectable()` returns null for a credential
			// it will not inject — a host-locked proxy credential, which the
			// brokered forge credential is — and that is a routing signal
			// meaning "use the broker instead", not a refusal. The caller
			// proceeds without a token; a public clone needs none, and a
			// private one fails with git's own reason.
			$this->logger->debug(
				'[StageDispatchService] the credential is not injectable; the clone will be unauthenticated',
				['credentialId' => $credentialId]
			);

			return null;
		}

		return $token;
	}//end resolveForgeToken()

	/**
	 * Pull the runner's error message out of a non-success body.
	 *
	 * `protected` rather than private so a test can reach it directly — the
	 * same seam `ProviderFactory::cliDispatchOptions()` uses, and for the same
	 * reason: a shape check nothing exercises is a shape check that silently
	 * stops holding.
	 *
	 * @param string $body The response body.
	 *
	 * @return string The reason, or a static fallback.
	 */
	protected function reasonFrom(string $body): string {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === true && trim((string)($decoded['error'] ?? '')) !== '') {
			return (string)$decoded['error'];
		}

		return 'the runner gave no reason';
	}//end reasonFrom()

	/**
	 * Map the runner's body onto the stage result.
	 *
	 * The shape is checked rather than assumed. A body that is not a stage
	 * result means the runner answered something this does not understand,
	 * and handing that on as `exitCode: 0` would read downstream as a PASS.
	 *
	 * ⚠️ THIS METHOD IS AN ALLOWLIST, so a key the runner returns and this does
	 * not name is a key no flow can ever see. `push` is carried for exactly that
	 * reason: without it a flow could declare a push, the runner could perform
	 * it, and the run record would say only `exitCode: 0` — leaving "it pushed"
	 * and "it found nothing to push" indistinguishable, which is the conflation
	 * every other seam in this file is written to avoid.
	 *
	 * The key is ABSENT rather than `null` when the stage declared no push, so a
	 * consumer can tell "this stage does not write" from "this stage wrote
	 * nothing".
	 *
	 * @param string $body The response body.
	 *
	 * @return array{exitCode: int, output: string, ref: string} The stage result.
	 *
	 * @throws RuntimeException When the body is not a stage result.
	 */
	/**
	 * Declare WHAT THE STAGE PRODUCES, not just what it printed.
	 *
	 * A reviewer that crashed, ran out of turns, or answered in prose exits 0
	 * exactly like one that reviewed — the artefact it was asked to write is
	 * the only thing that tells them apart, so a flow judging on the exit code
	 * judges nothing.
	 *
	 * The runner has supported this all along; nothing ever sent it. The flow
	 * declared `collect`, the node dropped it, and `stage.files` was therefore
	 * always absent — which the verdict step read as `missing` and blocked on.
	 * Correct refusals, for a reason that was never true.
	 *
	 * Extracted so `buildParams()` stays under the length threshold, and
	 * because the explanation is longer than the code and belongs with the
	 * concept rather than in the middle of a payload builder.
	 *
	 * @param array $params The payload so far.
	 * @param array $collect Artefacts to read back out of the clone, or [].
	 *
	 * @return array The payload, with `collect` set only when one was declared.
	 */
	protected function withCollect(array $params, array $collect): array {
		if ($collect !== []) {
			$params['collect'] = $collect;
		}

		return $params;
	}//end withCollect()

	/**
	 * Map the runner's response body onto a stage result.
	 *
	 * `protected` for the same stated reason as `withCollect()` and `reasonFrom()`:
	 * a shape check nothing exercises is a shape check that silently stops holding.
	 *
	 * A body that is not a stage result throws — a command that ran and exited
	 * non-zero is NOT that case, it is the result, and it is returned.
	 *
	 * @param string $body The raw response body from the runner.
	 *
	 * @return array{exitCode: int, output: string, ref: string, files?: array, push?: array}
	 *         The stage result.
	 *
	 * @throws RuntimeException When the body is not a stage result.
	 */
	protected function mapResult(string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || array_key_exists('exitCode', $decoded) === false) {
			throw new RuntimeException('The runner answered with something that is not a stage result.');
		}

		$result = [
			'exitCode' => (int)$decoded['exitCode'],
			'output' => (string)($decoded['output'] ?? ''),
			'ref' => (string)($decoded['ref'] ?? ''),
		];

		// The collected artefacts, kept under the key the runner used. Dropping
		// them here would make `collect` a no-op that still looks like it works:
		// the runner reads the files, returns them, and the mapper quietly
		// discards them one step before the only consumer.
		if (is_array(($decoded['files'] ?? null)) === true) {
			$result['files'] = $decoded['files'];
		}

		if (is_array(($decoded['push'] ?? null)) === true) {
			$result['push'] = [
				'pushed' => (bool)($decoded['push']['pushed'] ?? false),
				'branch' => (string)($decoded['push']['branch'] ?? ''),
				'commit' => (string)($decoded['push']['commit'] ?? ''),
				'files' => array_values((array)($decoded['push']['files'] ?? [])),
			];
		}

		return $result;
	}//end mapResult()


}//end class
