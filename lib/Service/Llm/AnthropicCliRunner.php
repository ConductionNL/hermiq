<?php

/**
 * Hermiq AnthropicCliRunner.
 *
 * The `cli` execution mode's link to the `hermiq-llm-runner` ExApp over AppAPI: whether
 * AppAPI and the runner are available (each refusal names the missing component), the
 * subscription credential the CLI runs with (personal scope only, resolved through the
 * OpenRegister credential broker), and the runner request of a turn or a warm-up.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The runner ExApp checks, the subscription credential, and the runner requests.
 *
 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
 */
final class AnthropicCliRunner {

	/**
	 * App id of the AppAPI app — required for any ExApp (`cli` execution mode) detection.
	 *
	 * @var string
	 */
	private const APP_API_ID = 'app_api';

	/**
	 * App id of the `hermiq-llm-runner` ExApp (the `cli` execution-mode backend).
	 *
	 * @var string
	 */
	private const RUNNER_EXAPP_ID = 'hermiq-llm-runner';

	/**
	 * The runner ExApp's single work route (`POST /run`).
	 *
	 * @var string
	 */
	private const RUNNER_ROUTE = '/run';

	/**
	 * AppAPI's public interface — resolved LAZILY by class-name string so Hermiq still boots
	 * and still serves `executionMode: http` on an instance without AppAPI. Mirrors
	 * {@see BrokerHttpClient::BROKER_CLASS}.
	 *
	 * @var string
	 */
	private const APP_API_PUBLIC_FUNCTIONS = 'OCA\\AppAPI\\PublicFunctions';

	/**
	 * The runner's own CLI timeout (SECONDS), mirrored from `runner.js`'s `RUNNER_TIMEOUT_MS`
	 * default (120000ms), after which the runner SIGKILLs the CLI and reports why.
	 *
	 * Known limit, recorded not solved: `RUNNER_TIMEOUT_MS` is a container env var Hermiq
	 * cannot read, so this tracks the runner's DEFAULT rather than its live value.
	 *
	 * ⚠️ Raised 120 -> 300 on 2026-08-17 for AGENTIC turns. A turn that answers one
	 * question by making several tool calls spends roughly 5-10s per round trip, and
	 * a realistic composite ("quote this client for 5 hours of dev work" — find the
	 * client, find or create the lead, read the template, price it, write the file)
	 * needs six or more. Measured: such a turn was cut off at 152s having already
	 * started its pooled process, and the user saw "could not reach the ExApp",
	 * which names the wrong cause entirely.
	 *
	 * The data is not the constraint — the OpenRegister lookups behind those calls
	 * measured 176-364ms each. It is the model's own round trips.
	 *
	 * @var int
	 */
	private const RUNNER_CLI_TIMEOUT_SECONDS = 300;

	/**
	 * How long to wait on a warm-up dispatch, in seconds.
	 *
	 * Short on purpose. The runner answers a warm-up as soon as it has SPAWNED
	 * the process — it does not wait for the CLI to finish initialising — so
	 * this only bounds the HTTP hop. A warm-up that cannot be dispatched
	 * promptly is not worth making the caller wait for: the turn behind it works
	 * either way.
	 *
	 * @var int
	 */
	private const WARM_TIMEOUT_SECONDS = 10;

	/**
	 * The personal credential scope — the ONLY scope an `anthropic-cli` (Claude Max/Pro
	 * subscription) credential may carry, per the Anthropic Terms of Service.
	 *
	 * @var string
	 */
	private const CREDENTIAL_SCOPE_PERSONAL = 'personal';

	/**
	 * Why a `cli` turn is refused by the runner checks or the credential, by reason: each
	 * names what is missing, because an operator cannot act on a generic "cli unavailable".
	 * All are 503s.
	 *
	 * @var array<string, string>
	 */
	private const REFUSALS = [
		'no_app_manager' => 'Anthropic executionMode "cli" is unavailable: the Nextcloud app manager could not be '
			. 'resolved, so the runner ExApp cannot be detected. Use executionMode "http".',
		'no_app_api' => 'Anthropic executionMode "cli" is unavailable: the AppAPI app ("' . self::APP_API_ID . '") is '
			. 'not enabled. Enable it, or use executionMode "http".',
		'no_app_api_interface' => 'Anthropic executionMode "cli" is unavailable: the AppAPI public interface could not be '
			. 'loaded. Use executionMode "http".',
		'no_runner' => 'Anthropic executionMode "cli" is unavailable: the "' . self::RUNNER_EXAPP_ID . '" ExApp is not '
			. 'installed. Install it, or use executionMode "http".',
		'runner_disabled' => 'Anthropic executionMode "cli" is unavailable: the "' . self::RUNNER_EXAPP_ID . '" ExApp is '
			. 'installed but disabled. Enable it, or use executionMode "http".',
		'no_broker' => 'Anthropic executionMode "cli" is unavailable: the OpenRegister credential broker is not '
			. 'available, so the subscription token cannot be resolved.',
		'broker_refused' => 'Anthropic executionMode "cli" cannot use this credential: the credential broker refused to '
			. 'resolve it. Check that the credential is owned by the acting user and allows the "hermiq" '
			. 'app.',
		'not_injectable' => 'Anthropic executionMode "cli" cannot use this credential: it is not an inject-only '
			. 'credential, so its secret cannot be placed in the CLI\'s environment. Select an '
			. '"anthropic-cli" (Claude Max subscription) credential, or use executionMode "http".',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager|null             $appManager                  Sees whether AppAPI is installed.
	 * @param CredentialScopeResolver|null $credentialResolver          Reads a credential's scope.
	 * @param ContainerInterface|null      $container                   Resolves AppAPI and the broker.
	 * @param LoggerInterface              $logger                      Logger.
	 * @param int                          $poolLifetimeSeconds         How long a pooled process may live.
	 * @param int                          $dispatchSlackSeconds Added to the runner's own timeout.
	 */
	public function __construct(
		private readonly ?IAppManager $appManager,
		private readonly ?CredentialScopeResolver $credentialResolver,
		private readonly ?ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly int $poolLifetimeSeconds,
		private readonly int $dispatchSlackSeconds,
	) {
	}//end __construct()

	/**
	 * The container AppAPI and the OpenRegister credential broker are resolved from.
	 *
	 * Both are other apps' classes, named by class-string and probed with class_exists,
	 * so neither can be a constructor type: hermiq has to stay constructible, and stay
	 * able to serve `http`, on an instance that has neither. They used to come from
	 * `\OCP\Server::get()`, which outside a booted Nextcloud autowires from scratch and
	 * can recurse through a constructor cycle until memory runs out. It is ProviderFactory's
	 * container, which is nullable and trailing there because unit tests build that factory
	 * positionally with its first four collaborators and never reach a cross-app call.
	 *
	 * @return ContainerInterface The injected container.
	 *
	 * @throws ProviderUnavailableException When the factory was built without one, which
	 *                                      is the same dead end as the sibling app being
	 *                                      absent.
	 */
	private function serviceContainer(): ContainerInterface {
		if ($this->container === null) {
			throw new ProviderUnavailableException(
				'This provider is unavailable: the factory was constructed without a container, so '
				. 'AppAPI and the OpenRegister credential broker cannot be resolved.',
				503
			);
		}

		return $this->container;
	}//end serviceContainer()

	/**
	 * Assert that the `cli` transport's components are installed and enabled.
	 *
	 * Each failure names WHICH component is missing — an operator cannot act on a generic
	 * "cli unavailable". Runs before the credential is resolved.
	 *
	 * Note the asymmetry: `app_api` is a normal Nextcloud app and `IAppManager` can see it,
	 * but an ExApp is NOT — ExApps live in AppAPI's own `ex_apps` table and are invisible to
	 * `IAppManager` (`occ app:list` shows only `app_api`). The ExApp is therefore checked
	 * through AppAPI's own public seam, `PublicFunctions::getExApp()`, which reports
	 * `enabled`. Asking `IAppManager` about the runner would report it missing even when it
	 * is deployed and healthy.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When any component is unavailable (503).
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
	 */
	public function assertAvailable(): void {
		$gap = ($this->appApiGap() ?? $this->runnerGap());
		if ($gap !== null) {
			throw new ProviderUnavailableException(self::REFUSALS[$gap], 503);
		}

	}//end assertAvailable()

	/**
	 * What AppAPI lacks, first missing first: the app manager, the AppAPI app, its public interface.
	 *
	 * @return string|null The refusal reason, or null when AppAPI is there.
	 */
	private function appApiGap(): ?string {
		if ($this->appManager === null) {
			return 'no_app_manager';
		}

		if ($this->appManager->isInstalled(self::APP_API_ID) === false) {
			return 'no_app_api';
		}

		if (class_exists(self::APP_API_PUBLIC_FUNCTIONS) === false) {
			return 'no_app_api_interface';
		}

		return null;
	}//end appApiGap()

	/**
	 * What the runner ExApp lacks: being installed, or being enabled.
	 *
	 * @return string|null The refusal reason, or null when the runner is installed and enabled.
	 */
	private function runnerGap(): ?string {
		$exApp = $this->appApiPublicFunctions()->getExApp(self::RUNNER_EXAPP_ID);
		if ($exApp === null) {
			return 'no_runner';
		}

		if (($exApp['enabled'] ?? false) === false) {
			return 'runner_disabled';
		}

		return null;
	}//end runnerGap()

	/**
	 * Resolve the subscription token for the CLI, failing closed.
	 *
	 * Hermiq's first `resolveInjectable()` caller. The broker keeps custody of the secret and
	 * still runs its own guards (owner/IDOR and `allowedApps`) — neither is reimplemented
	 * here. The plaintext token crossing into Hermiq's process is a conscious, bounded
	 * weakening of the broker's "the app never sees the secret" posture: it is forced, because
	 * a CLI needs its token in the process environment, so there is no request to proxy and no
	 * header to substitute.
	 *
	 * The organisation-scope refusal is Hermiq's own: a Claude Max/Pro subscription is
	 * PERSONAL-SCOPE ONLY per the Anthropic Terms of Service. The broker does NOT enforce
	 * this — its Guard 1 deliberately admits any organisation MEMBER for an
	 * organisation-scope credential — so this is the only enforcement point.
	 *
	 * @param string $credentialId The broker credential UUID.
	 * @param string|null $uid The acting user's UID, or null when there is no session.
	 *
	 * @return string The resolved token. Never logged, never returned in an exception.
	 *
	 * @throws ProviderUnavailableException When the scope cannot be verified, the credential is
	 *                                      organisation-scope, or no token can be resolved (503).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The optional OpenRegister broker is named by
	 *   class-string and resolved through the injected container (guarded by class_exists
	 *   above) so this class stays constructible when the broker is absent.
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
	 */
	public function resolveToken(string $credentialId, ?string $uid): string {
		$this->assertPersonalScopeCredential(credentialId: $credentialId);

		if (class_exists(BrokerHttpClient::BROKER_CLASS) === false) {
			throw new ProviderUnavailableException(self::REFUSALS['no_broker'], 503);
		}

		$token = $this->brokerToken(credentialId: $credentialId, uid: $uid);
		if ($token === null || $token === '') {
			throw new ProviderUnavailableException(self::REFUSALS['not_injectable'], 503);
		}

		return $token;
	}//end resolveToken()

	/**
	 * The subscription token as the broker resolves it, through the broker's own guards.
	 *
	 * @param string      $credentialId The broker credential UUID.
	 * @param string|null $uid          The acting user's UID, or null when there is no session.
	 *
	 * @return string|null The token, or null/'' when the credential is not inject-only.
	 *
	 * @throws ProviderUnavailableException When the broker refuses to resolve it (503).
	 */
	private function brokerToken(string $credentialId, ?string $uid): ?string {
		try {
			$broker = $this->serviceContainer()->get(BrokerHttpClient::BROKER_CLASS);
			return $broker->resolveInjectable($credentialId, BrokerHttpClient::APP_ID, $uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ProviderFactory] Anthropic cli credential could not be resolved through the broker',
				[
					'credentialId' => $credentialId,
					'reason' => $e->getMessage(),
				]
			);

			throw new ProviderUnavailableException(self::REFUSALS['broker_refused'], 503);
		}//end try
	}//end brokerToken()

	/**
	 * Assert the credential is personal-scope — an Anthropic Terms of Service constraint.
	 *
	 * A Claude Max/Pro subscription serves only its owner and MUST be refused at organisation
	 * scope. This cannot be delegated to the broker: `resolveInjectable()` returns a bare
	 * `string|null`, its `scopeOf()` is private, and its Guard 1 ADMITS any organisation
	 * member for an organisation-scope credential. Fails CLOSED when the scope cannot be
	 * established at all.
	 *
	 * @param string $credentialId The broker credential UUID.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When the scope is organisation, unknown, or unverifiable (503).
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
	 */
	private function assertPersonalScopeCredential(string $credentialId): void {
		if ($this->credentialResolver === null) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" is unavailable: the credential scope could not be verified, '
				. 'and a Claude Max/Pro subscription may only be used at personal scope.',
				503
			);
		}

		$scope = $this->credentialResolver->scopeOfCredential($credentialId);
		if ($scope === null) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot use this credential: it could not be found in the '
				. 'credential broker, so its scope cannot be verified.',
				503
			);
		}

		if ($scope !== self::CREDENTIAL_SCOPE_PERSONAL) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" refuses this credential: it is organisation-scope, and a '
				. 'Claude Max/Pro subscription is PERSONAL-SCOPE ONLY under the Anthropic Terms of Service — '
				. 'it may serve only its owner. Use a personal credential, or an Anthropic API key over '
				. 'executionMode "http".',
				503
			);
		}

	}//end assertPersonalScopeCredential()

	/**
	 * Dispatch one turn to the runner ExApp and return its body once the runner answered.
	 *
	 * The two failure checks run before the body is read: the AppAPI transport error
	 * (returned, never thrown), then the status. Reading the body as the model's answer
	 * is the caller's third check.
	 *
	 * @param CliTurn $turn  The turn: model, history, user, run token, MCP config and pool key.
	 * @param string  $token The subscription token (credential env only).
	 *
	 * @return string The response body.
	 *
	 * @throws ProviderUnavailableException When the runner was not reached or failed (503).
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
	 */
	public function send(CliTurn $turn, string $token): string {
		$result = $this->appApiPublicFunctions()->exAppRequest(
			self::RUNNER_EXAPP_ID,
			self::RUNNER_ROUTE,
			$turn->uid,
			'POST',
			$this->dispatchParams(turn: $turn, token: $token),
			$this->cliDispatchOptions()
		);

		$this->assertRunnerAnswered(result: $result);

		return (string)$result->getBody();
	}//end send()

	/**
	 * Ask the runner to start the pooled process of a warm-up, with a short timeout.
	 *
	 * @param CliTurn $turn  The warm-up.
	 * @param string  $token The subscription token (credential env only).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-the-cli-process-is-warmed-before-the-first-question-not-by-it
	 */
	public function warm(CliTurn $turn, string $token): void {
		$this->appApiPublicFunctions()->exAppRequest(
			self::RUNNER_EXAPP_ID,
			self::RUNNER_ROUTE,
			$turn->uid,
			'POST',
			$this->warmupParams(turn: $turn, token: $token),
			['timeout' => self::WARM_TIMEOUT_SECONDS]
		);
	}//end warm()

	/**
	 * The runner request of a warm-up: no messages, the same token, MCP config and
	 * pool key the first turn will present, and the warm-only flag.
	 *
	 * @param CliTurn $turn  The warm-up.
	 * @param string  $token The subscription token.
	 *
	 * @return array<string, mixed> The request parameters.
	 */
	private function warmupParams(CliTurn $turn, string $token): array {
		return [
			'provider' => 'anthropic',
			'model' => $turn->model,
			'messages' => [],
			'credentialEnv' => ['CLAUDE_CODE_OAUTH_TOKEN' => $token],
			'mcpConfig' => $turn->mcpConfig,
			'runToken' => $turn->runToken,
			'poolKey' => $turn->poolKey,
			'poolLifetimeSeconds' => $this->poolLifetimeSeconds,
			'warmOnly' => true,
		];
	}//end warmupParams()

	/**
	 * The runner request of a turn: the mapped history and subscription token, and the
	 * MCP config, run token and pool key when the turn has them.
	 *
	 * @param CliTurn $turn  The turn.
	 * @param string  $token The subscription token.
	 *
	 * @return array<string, mixed> The request parameters.
	 */
	private function dispatchParams(CliTurn $turn, string $token): array {
		// `credentialEnv`'s key is EXACTLY the one the runner's anthropic adapter allowlists.
		// A key outside the allowlist is dropped WITHOUT an error, which would yield an
		// unauthenticated CLI rather than a 400 — so this string has to be exactly right.
		$params = [
			'provider' => 'anthropic',
			'model' => $turn->model,
			'messages' => $this->mapHistoryToCliMessages(messageHistory: $turn->messageHistory),
			'credentialEnv' => ['CLAUDE_CODE_OAUTH_TOKEN' => $token],
		];

		// A tool-requiring turn carries the governed MCP config; the runner writes it to a
		// 0600 scratch file and locks the CLI down with `--tools "" --strict-mcp-config
		// --mcp-config <path>`. A text-only turn omits it entirely (unchanged link-2 path).
		if ($turn->mcpConfig !== null) {
			$params['mcpConfig'] = $turn->mcpConfig;
		}

		// The run identity for the egress PEP. The runner turns this into
		// `HTTPS_PROXY=http://run:<token>@<proxy>` in the CLI's ENVIRONMENT — never on
		// argv, where the process table would expose it. Sent on every turn: the proxy
		// is the container's only route out.
		if ((string)$turn->runToken !== '') {
			$params['runToken'] = $turn->runToken;
		}

		// The pool key is the CONVERSATION, never the agent or the user. A
		// stream-json process REMEMBERS its turns (measured: a second turn recalled
		// a canary with no history re-sent), so any key wider than one conversation
		// carries one conversation's context into another's turn. Absent key = the
		// runner takes the cold path, which is always correct and never pooled.
		if ($turn->poolKey !== '') {
			$params['poolKey'] = $turn->poolKey;
			// Sent rather than configured separately in the runner: this bounds BOTH
			// the process's reusable life and its token's validity, and the two
			// drifting apart is precisely the failure that ends in a live process
			// holding a dead token.
			$params['poolLifetimeSeconds'] = $this->poolLifetimeSeconds;
		}

		return $params;
	}//end dispatchParams()

	/**
	 * Refuse a dispatch the runner did not answer: AppAPI's never-throws error array,
	 * or a non-success status. The order is load-bearing.
	 *
	 * @param mixed $result What exAppRequest() returned.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When the runner was not reached or failed (503).
	 *
	 * @psalm-assert \OCP\Http\Client\IResponse $result
	 */
	private function assertRunnerAnswered(mixed $result): void {
		// Check 1 — the never-throws failure channel. MUST precede any body read.
		if (is_array($result) === true) {
			$this->logger->warning(
				'[ProviderFactory] Anthropic cli dispatch failed at the AppAPI transport',
				['reason' => (string)($result['error'] ?? 'unknown')]
			);

			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" could not reach the "' . self::RUNNER_EXAPP_ID . '" ExApp. '
				. 'Check that the ExApp is running.',
				503
			);
		}

		// Check 2 — http_errors => false, so a 4xx/5xx is an ordinary response.
		$status = $result->getStatusCode();
		if ($status < 200 || $status > 299) {
			$this->logger->warning(
				'[ProviderFactory] Anthropic cli runner returned a non-success status',
				['status' => $status]
			);

			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" failed: the runner returned an error while executing the '
				. 'turn. Check the ExApp\'s logs.',
				503
			);
		}
	}//end assertRunnerAnswered()

	/**
	 * Build the AppAPI request options for a `cli` dispatch.
	 *
	 * A named seam purely so the 3s-default trap is directly assertable: a test can pin that
	 * `timeout` is PRESENT and EXCEEDS the runner's own CLI timeout, so it cannot silently
	 * regress to AppAPI's default and take the feature to 0% functional.
	 *
	 * @return array{timeout: int} AppAPI request options.
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
	 */
	private function cliDispatchOptions(): array {
		return ['timeout' => (self::RUNNER_CLI_TIMEOUT_SECONDS + $this->dispatchSlackSeconds)];
	}//end cliDispatchOptions()

	/**
	 * Flatten an LLPhant message history into the runner's `messages` shape.
	 *
	 * Deliberately NOT {@see AnthropicMessageFormat::mapHistoryToAnthropicMessages()}: that hoists system turns into a
	 * separate top-level `system` field, which the Messages API requires but the runner has NO
	 * field for. Reusing it here would SILENTLY DROP the system prompt — the agent's entire
	 * persona and instructions — on every `cli` turn. The runner's `buildPrompt()` renders each
	 * message as `ROLE: content`, so the system turn is carried in-band instead, exactly as the
	 * runner's contract specifies (`messages: [{role: "system|user|assistant", content}]`).
	 *
	 * @param array $messageHistory Array of LLPhant Message objects.
	 *
	 * @return array<int, array<string, string>> The runner's `messages` array.
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-cli-completion-is-mapped-back-into-the-driver-response-and-the-sse-envelope
	 */
	private function mapHistoryToCliMessages(array $messageHistory): array {
		$messages = [];
		foreach ($messageHistory as $msg) {
			$content = (string)$msg->content;
			if ($content === '') {
				continue;
			}

			$messages[] = [
				'role' => $msg->role->value,
				'content' => $content,
			];
		}

		return $messages;
	}//end mapHistoryToCliMessages()

	/**
	 * Resolve AppAPI's public interface lazily, by class-name string.
	 *
	 * Never a hard `use` or a constructor type — Hermiq MUST still boot and still serve `http`
	 * on an instance with no AppAPI installed. Resolved by class-name string through the
	 * injected container, mirroring `BrokerHttpClient`'s pattern for the credential broker.
	 * Callers assert {@see APP_API_PUBLIC_FUNCTIONS} exists first.
	 *
	 * `AppAPIService` internals were read as EVIDENCE for this dispatch's traps; they are not
	 * an API to call. `PublicFunctions` is the supported seam.
	 *
	 * @return object AppAPI's `PublicFunctions`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The optional AppAPI interface is named by
	 *   class-string and resolved through the injected container, so Hermiq still boots
	 *   and serves `http` on an instance without AppAPI installed.
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
	 */
	private function appApiPublicFunctions(): object {
		return $this->serviceContainer()->get(self::APP_API_PUBLIC_FUNCTIONS);
	}//end appApiPublicFunctions()
}//end class
