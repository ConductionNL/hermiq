<?php

/**
 * Hermiq LLM Provider Factory.
 *
 * Ported from the inline provider-selection switch inside OpenRegister's
 * `ResponseGenerationHandler::generateResponse()` (openregister HEAD, lines ~235-460)
 * into a proper factory. Given the `hermiq.llm` config plus an optional agent's
 * model/temperature override, resolves which of the four supported chat drivers
 * (`openai`, `ollama`, `fireworks`, `nextcloud`) to use and returns a `ChatDriver`
 * value object describing it.
 *
 * Adaptations vs. the ported original (documented per the agent-engine-port
 * pause protocol — "document, continue"):
 * - `fireworks` never instantiates an `OpenAIChat` for generation. OR's original
 *   code built one (`new OpenAIChat($config)`, invoked it) purely to immediately
 *   discard the response and overwrite it with the direct-HTTP
 *   `callFireworksChatAPIWithHistory()` result — a redundant network round-trip
 *   with no effect on the returned text. This port skips straight to the
 *   direct-HTTP path; behavior (the text returned to the caller) is identical.
 * - `organizationId`/`temperature` are no longer assigned onto `OpenAIConfig` as
 *   dynamic properties. Neither field is declared on LLPhant's `OpenAIConfig`
 *   class nor read by `OpenAIChat::__construct()` — OR's assignments
 *   (`@psalm-suppress UndefinedPropertyAssignment`-annotated) were silent no-ops.
 *   Temperature IS wired here, correctly, via `$config->modelOptions['temperature']`
 *   (an actually-read config channel) — a genuine fix, not a behavior regression,
 *   since the original never influenced generation with it either.
 *   `organizationId` stays in the persisted `hermiq.llm` config shape (verbatim,
 *   per the port contract) but is not wired anywhere — LLPhant 0.9.x's
 *   `OpenAIChat` has no organization-header hook to give it.
 *
 * The `nextcloud` driver (plan §8 move 1) is additive: `OCP\TaskProcessing\IManager`-
 * backed, guarded by `hasProviders()` (verified against the real OCP 31.0 interface —
 * exact method name, not assumed), non-streaming, background/non-interactive use only
 * (conversation titles/summaries). It never returns an LLPhant chat instance because
 * TaskProcessing has no streaming surface; callers needing SSE chat MUST NOT select it.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use Exception;
use GuzzleHttp\Psr7\Request;
use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;
use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCP\App\IAppManager;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Server;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OpenAI;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Resolves the configured chat provider into a ready-to-use driver.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   A provider factory is, by role,
 * the one seam that touches every LLPhant config/chat class plus the TaskProcessing
 * surface; splitting it would smear provider selection across the Engine.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Each provider adds a
 * driver-build + direct-HTTP branch of its own (openai/ollama/fireworks/anthropic/
 * nextcloud); the aggregate complexity is inherent to being the single provider seam,
 * and each branch stays individually simple.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Anthropic's `cli` transport adds a
 * small family of private, single-purpose helpers (availability, credential, dispatch,
 * mapping). Keeping them here is deliberate: they belong to the provider branch this
 * class already owns, and each stays individually trivial. See
 * `openspec/changes/cli-runner-text-turn-dispatch/design.md` — "The dispatch seam".
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface is the per-provider
 * driver/generation entry points the Engine calls — one per provider transport, not an
 * unfocused API.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Each provider's driver-build and
 * direct-HTTP branch lives here in full (see ExcessiveClassComplexity above); the length
 * is the sum of those individually simple branches.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
class ProviderFactory {

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
	 * Slack (SECONDS) added to the runner's own timeout to form Hermiq's.
	 *
	 * Deliberately positive: Hermiq MUST outwait the runner so the runner's kill-and-report
	 * wins the race and the operator gets the real reason instead of a generic timeout.
	 * Expressing Hermiq's timeout as "the runner's + slack" makes that invariant structural —
	 * it cannot drift by editing one of two unrelated magic numbers.
	 *
	 * @var int
	 */
	private const CLI_DISPATCH_TIMEOUT_SLACK_SECONDS = 30;

	/**
	 * How long a pooled CLI process may live, in seconds, and therefore how long
	 * its run token stays valid.
	 *
	 * ⚠️ THIS IS A SECURITY PARAMETER, not a tuning knob. The CLI reads its
	 * `--mcp-config` once at startup and never re-reads it (measured 2026-08-17,
	 * `REREAD=false`), so a pooled process cannot be handed a fresh token — its
	 * original token must stay valid for as long as the process is reusable. That
	 * is design D0.1 option 1, adopted deliberately, and it widens the window a
	 * leaked token is useful in from one turn to this value.
	 *
	 * Raising it buys a higher pool hit rate and lengthens that window by exactly
	 * the same amount. The runner is told this number rather than keeping its own,
	 * so the process cap and the token TTL cannot drift apart.
	 *
	 * @var int
	 */
	private const POOL_PROCESS_LIFETIME_SECONDS = 600;

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
	 * Hard cap on Anthropic/runner tool-call round-trips per turn — a safety bound so a
	 * model that keeps requesting tools cannot loop forever. LLPhant enforces the same
	 * kind of ceiling internally for the OpenAI/Ollama path.
	 *
	 * @var int
	 */
	private const MAX_TOOL_ITERATIONS = 10;

	/**
	 * Constructor.
	 *
	 * @param LlmSettingsHandler $settingsHandler Reads/writes `hermiq.llm`.
	 * @param IManager $taskManager Nextcloud Assistant task manager
	 *                              (the `nextcloud` driver's
	 *                              backend).
	 * @param IUserSession $userSession Current session —
	 *                                  the broker's
	 *                                  ownership guard needs
	 *                                  an identity to check
	 *                                  the credential
	 *                                  against.
	 * @param LoggerInterface $logger Logger.
	 * @param string $appName App id used when scheduling
	 *                        TaskProcessing tasks.
	 * @param TenantModelPolicyService|null $modelPolicyService Resolves the calling
	 *                                                          organisation's effective ModelPolicy
	 *                                                          (tenant-model-policy). Nullable —
	 *                                                          defaulted so every existing call site
	 *                                                          (all constructed before this change)
	 *                                                          keeps working unchanged; NC's DI
	 *                                                          container autowires a real instance in
	 *                                                          production regardless of the default.
	 *                                                          When `$organisation` is not passed to
	 *                                                          `createChatDriver()`, no enforcement
	 *                                                          call is made at all (opt-in threading,
	 *                                                          see design.md).
	 * @param IAppManager|null $appManager Nextcloud app manager — used ONLY by the
	 *                                     `cli` execution-mode path
	 *                                     (llm-cli-runner-exapp) to detect whether
	 *                                     AppAPI and the `hermiq-llm-runner` ExApp
	 *                                     are installed/enabled before a turn is
	 *                                     dispatched. Nullable/defaulted so every
	 *                                     existing call site (all constructed before
	 *                                     this change) keeps working unchanged; NC's
	 *                                     DI container autowires a real instance in
	 *                                     production. A null manager makes the runner
	 *                                     report unavailable (503), never crash.
	 * @param CredentialScopeResolver|null $credentialResolver Resolves a personal →
	 *                                                         organisation override for the
	 *                                                         broker credential id the
	 *                                                         `openai`/`fireworks` branches use
	 *                                                         (agent-credentials). Nullable —
	 *                                                         defaulted so every existing call
	 *                                                         site (all constructed before this
	 *                                                         change) keeps working unchanged;
	 *                                                         NC's DI container autowires a real
	 *                                                         instance in production regardless
	 *                                                         of the default. Consulted ONLY
	 *                                                         when `$organisation` is passed to
	 *                                                         `createChatDriver()` — the exact
	 *                                                         same opt-in guard
	 *                                                         `enforceModelPolicy()` uses.
	 * @param RunTokenService|null $runTokenService Mints/consumes the per-run bearer
	 *                                              token that authenticates the governed
	 *                                              `cli` MCP + egress endpoints
	 *                                              (cli-runner-governed-mcp-and-egress).
	 *                                              Nullable/defaulted so existing test
	 *                                              call sites keep working; DI autowires
	 *                                              a real instance in production. A null
	 *                                              service makes a tool-requiring `cli`
	 *                                              turn fail loud (503) — never a
	 *                                              text-only downgrade.
	 * @param IURLGenerator|null $urlGenerator Resolves the absolute URL of Hermiq's
	 *                                         governed MCP endpoint written into the
	 *                                         runner's MCP config
	 *                                         (cli-runner-governed-mcp-and-egress).
	 *                                         Nullable/defaulted for the same
	 *                                         backward-compat reason; a null
	 *                                         generator makes a tool-requiring `cli`
	 *                                         turn fail loud (503).
	 * @param IAppConfig|null $appConfig Reads the `mcp_run_base_url` override —
	 *                                   the CONTAINER-reachable origin of the
	 *                                   governed MCP endpoint. Needed because
	 *                                   `IURLGenerator` yields the URL published
	 *                                   to browsers, which the runner container
	 *                                   usually cannot resolve
	 *                                   (cli-runner-governed-mcp-and-egress).
	 *                                   Nullable/defaulted for the same
	 *                                   backward-compat reason; a null config
	 *                                   simply means no override is applied.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator (several nullable/defaulted for backward-compatible
	 *   test call sites), not a logic-bearing argument list.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 * @spec openspec/changes/llm-cli-runner-exapp/specs/llm-cli-runner-exapp/spec.md#requirement-optional-cli-execution-mode-routes-turns-through-the-runner-exapp
	 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	public function __construct(
		private readonly LlmSettingsHandler $settingsHandler,
		private readonly IManager $taskManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly string $appName = 'hermiq',
		private readonly ?TenantModelPolicyService $modelPolicyService = null,
		private readonly ?IAppManager $appManager = null,
		private readonly ?CredentialScopeResolver $credentialResolver = null,
		private readonly ?RunTokenService $runTokenService = null,
		private readonly ?IURLGenerator $urlGenerator = null,
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * Read the current `hermiq.llm` configuration.
	 *
	 * @return array LLM configuration (see LlmSettingsHandler::getLLMSettingsOnly()).
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
	 */
	public function getLlmConfig(): array {
		return $this->settingsHandler->getLLMSettingsOnly();
	}//end getLlmConfig()

	/**
	 * Resolve the configured `chatProvider` into a ready-to-use ChatDriver.
	 *
	 * @param array $llmConfig The `hermiq.llm` configuration
	 *                         (LlmSettingsHandler::getLLMSettingsOnly()).
	 * @param string|null $agentModel Agent-level model override, when set and non-empty.
	 * @param float|null $agentTemperature Agent-level temperature override.
	 * @param string|null $organisation The calling agent's organisation
	 *                                  (tenant-model-policy). When non-null (including
	 *                                  `''` for an organisation-less agent/instance
	 *                                  scope), the resolved (provider, model) pair is
	 *                                  checked against the effective ModelPolicy for
	 *                                  that organisation BEFORE the driver is returned
	 *                                  — this is the single enforcement chokepoint every
	 *                                  trigger path (schedule, Run now, conversation,
	 *                                  flow listener) shares. When null, no check is made
	 *                                  (opt-in; existing callers that do not pass an
	 *                                  organisation see zero behavior change).
	 * @param int|null $agentMaxTokens Agent-level max-tokens override, applied to the
	 *                                 resolved driver when set and non-null.
	 *
	 * @return ChatDriver The resolved driver.
	 *
	 * @throws ProviderUnavailableException When no provider is configured, the selected
	 *                                      provider is missing required credentials, or
	 *                                      the provider identifier is not recognised.
	 * @throws ModelPolicyViolationException When `$organisation` is given and the resolved
	 *                                       (provider, model) pair falls outside its
	 *                                       effective ModelPolicy.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
	 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 */
	public function createChatDriver(
		array $llmConfig,
		?string $agentModel = null,
		?float $agentTemperature = null,
		?string $organisation = null,
		?int $agentMaxTokens = null,
	): ChatDriver {
		$chatProvider = $llmConfig['chatProvider'] ?? null;

		if (empty($chatProvider) === true) {
			throw new ProviderUnavailableException(
				'Chat provider is not configured. Please configure OpenAI, Anthropic, Fireworks AI, Ollama, or Nextcloud Assistant in settings.',
				503
			);
		}

		$driver = match ($chatProvider) {
			'ollama' => $this->createOllamaDriver(
				ollamaConfig: $llmConfig['ollamaConfig'] ?? [],
				agentModel: $agentModel,
				agentTemperature: $agentTemperature,
				agentMaxTokens: $agentMaxTokens
			),
			'openai' => $this->createOpenAiDriver(
				openaiConfig: $llmConfig['openaiConfig'] ?? [],
				agentModel: $agentModel,
				agentTemperature: $agentTemperature,
				credentialOverride: $this->resolveCredentialOverride(provider: 'openai', organisation: $organisation),
				agentMaxTokens: $agentMaxTokens
			),
			'fireworks' => $this->createFireworksDriver(
				fireworksConfig: $llmConfig['fireworksConfig'] ?? [],
				agentModel: $agentModel,
				credentialOverride: $this->resolveCredentialOverride(provider: 'fireworks', organisation: $organisation)
			),
			'anthropic' => $this->createAnthropicDriver(
				anthropicConfig: $llmConfig['anthropicConfig'] ?? [],
				agentModel: $agentModel,
				agentMaxTokens: $agentMaxTokens
			),
			'nextcloud' => $this->createNextcloudDriver(),
			default => throw new ProviderUnavailableException("Unsupported chat provider: {$chatProvider}"),
		};// End match.

		// Tenant-model-policy: the single enforcement chokepoint. Runs AFTER the
		// agent override is applied (createOllamaDriver()/createOpenAiDriver()/
		// createFireworksDriver() already resolved agentModel ?? providerConfig
		// into $driver->model) so a policy cannot be bypassed by leaving the
		// per-agent model field blank — see design.md "Decisions". Runs BEFORE
		// any network call: driver construction above only builds client value
		// objects, it never sends a request.
		$this->enforceModelPolicy(organisation: $organisation, provider: $driver->provider, model: $driver->model);

		return $driver;
	}//end createChatDriver()

	/**
	 * Check a resolved (provider, model) pair against the calling organisation's
	 * effective ModelPolicy; throw when out of policy. A no-op when either
	 * `$organisation` is null (caller opted out of enforcement, e.g. a purely
	 * instance-wide background call) or no policy service was injected
	 * (backward-compatible default for pre-existing call sites/tests).
	 *
	 * @param string|null $organisation The calling organisation, or null to skip the check.
	 * @param string $provider The resolved provider.
	 * @param string $model The resolved model id.
	 *
	 * @return void
	 *
	 * @throws ModelPolicyViolationException When the pair is outside the effective policy.
	 *
	 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 */
	private function enforceModelPolicy(?string $organisation, string $provider, string $model): void {
		if ($organisation === null || $this->modelPolicyService === null) {
			return;
		}

		if ($this->modelPolicyService->isAllowed(organisation: $organisation, provider: $provider, model: $model) === true) {
			return;
		}

		$orgLabel = $organisation;
		if ($orgLabel === '') {
			$orgLabel = '(instance-wide)';
		}

		throw new ModelPolicyViolationException(
			sprintf(
				"Model policy violation: organisation '%s' does not permit provider '%s' model '%s'.",
				$orgLabel,
				$provider,
				$model
			),
			422
		);

	}//end enforceModelPolicy()

	/**
	 * Resolve a personal → organisation credential override for a provider,
	 * via `CredentialScopeResolver` (agent-credentials). A no-op (returns
	 * null, meaning "use the configured instance credential unchanged") when
	 * either `$organisation` is null (caller opted out, e.g. a purely
	 * instance-wide background call — the same opt-in guard
	 * `enforceModelPolicy()` uses) or no resolver was injected
	 * (backward-compatible default for pre-existing call sites/tests).
	 *
	 * @param string $provider The provider identifier (e.g. "openai", "fireworks").
	 * @param string|null $organisation The calling organisation, or null to skip resolution.
	 *
	 * @return string|null The overriding credential uuid, or null to keep the configured
	 *                     `hermiq.llm.<provider>Config.credentialId` unchanged.
	 *
	 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	private function resolveCredentialOverride(string $provider, ?string $organisation): ?string {
		if ($organisation === null || $this->credentialResolver === null) {
			return null;
		}

		return $this->credentialResolver->resolve(
			provider: $provider,
			actingUserId: $this->currentUid(),
			organisation: $organisation
		);

	}//end resolveCredentialOverride()

	/**
	 * Call Fireworks AI's chat completions endpoint directly with full message history.
	 *
	 * Ported verbatim from `ResponseGenerationHandler::callFireworksChatAPIWithHistory()`
	 * (direct HTTP via curl, bypassing the OpenAI client library — the original code's
	 * comment: "avoid OpenAI library error handling bugs"). Function calling is not
	 * supported for Fireworks; `$functions` is accepted only so the Engine's call site
	 * does not need a provider-specific branch, and is logged + ignored when non-empty.
	 *
	 * @param string $credentialId Broker credential UUID — NOT a key. Hermiq has no
	 *                             Fireworks key; the broker holds it and injects it.
	 * @param string $model Model identifier.
	 * @param string $baseUrl Base API URL.
	 * @param array $messageHistory Array of LLPhant Message objects.
	 * @param array $functions Function definitions (ignored; logged when present).
	 *
	 * @return string Generated response text.
	 *
	 * @throws \Exception If the HTTP call fails or the response is malformed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  API call requires handling many response scenarios
	 * @SuppressWarnings(PHPMD.NPathComplexity)       API call requires handling many response scenarios
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) API error handling requires verbose code
	 * (all three mirror the suppressions on the OR original this was ported from)
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
	 */
	public function callFireworksChat(
		string $credentialId,
		string $model,
		string $baseUrl,
		array $messageHistory,
		array $functions = [],
	): string {
		$url = rtrim($baseUrl, '/') . '/chat/completions';

		if (empty($functions) === false) {
			$this->logger->warning(
				message: '[ProviderFactory] Function calling not yet supported for Fireworks AI. Tools will be ignored.',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'functionCount' => count($functions),
				]
			);
		}

		$messages = [];
		foreach ($messageHistory as $msg) {
			$messages[] = [
				'role' => $msg->role->value,
				'content' => $msg->content,
			];
		}

		$payload = [
			'model' => $model,
			'messages' => $messages,
		];

		// Through the broker, not straight out. Hermiq has no Fireworks key to send: the
		// broker holds it, checks the allow-rules, and injects the Authorization header
		// server-side.
		$client = new BrokerHttpClient(
			credentialId: $credentialId,
			logger: $this->logger,
			actingUserId: $this->currentUid()
		);

		try {
			$psrResponse = $client->sendRequest(
				new Request(
					'POST',
					$url,
					['Content-Type' => 'application/json'],
					(string)json_encode($payload)
				)
			);
		} catch (Throwable $e) {
			throw new Exception('Fireworks API request failed: ' . $e->getMessage());
		}

		$httpCode = $psrResponse->getStatusCode();
		$response = (string)$psrResponse->getBody();

		if ($httpCode !== 200) {
			$errorData = [];
			if (is_string($response) === true) {
				$errorData = json_decode($response, true);
			}

			$fallbackError = 'Unknown error';
			if (is_string($response) === true) {
				$fallbackError = $response;
			}

			$errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? $fallbackError;

			if ($httpCode === 401 || $httpCode === 403) {
				throw new Exception('Authentication failed. Please check your Fireworks API key.');
			}

			if ($httpCode === 404) {
				throw new Exception("Model not found: {$model}. Please check the model name.");
			}

			if ($httpCode === 429) {
				throw new Exception('Rate limit exceeded. Please try again later.');
			}

			throw new Exception("Fireworks API error (HTTP {$httpCode}): {$errorMessage}");
		}//end if

		$data = [];
		if (is_string($response) === true) {
			$data = json_decode($response, true);
		}

		if (isset($data['choices'][0]['message']['content']) === false) {
			$responseStr = 'Invalid response';
			if (is_string($response) === true) {
				$responseStr = $response;
			}

			throw new Exception('Unexpected Fireworks API response format: ' . $responseStr);
		}

		return $data['choices'][0]['message']['content'];
	}//end callFireworksChat()

	/**
	 * Build the Anthropic auth + version headers for the given `authMode`.
	 *
	 * Pure header selection, split out so it is unit-testable without a live broker:
	 *
	 *   - `api_key` → `x-api-key: <broker placeholder>` + `anthropic-version`.
	 *   - `oauth`   → `Authorization: Bearer <broker placeholder>` + `anthropic-version`
	 *                 + `anthropic-beta: oauth-2025-04-20` (the Claude Max / subscription
	 *                 flow the Claude CLI uses).
	 *
	 * The auth-carrying header value is ALWAYS `BrokerHttpClient::BROKER_MANAGED_KEY`, a
	 * recognisable placeholder — never a real secret. `BrokerHttpClient` strips the
	 * broker-owned auth header (`authorization` / `x-api-key`) before egress and the broker
	 * injects the vault-held secret server-side; the non-auth headers (`anthropic-version`,
	 * `anthropic-beta`, `Content-Type`) pass through untouched, which is why they must be
	 * set here rather than left to the broker.
	 *
	 * @param string $authMode The Anthropic auth mode: `api_key` (default) or `oauth`.
	 *
	 * @return array<string, string> The request headers.
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
	 */
	public function buildAnthropicHeaders(string $authMode): array {
		$headers = [
			'Content-Type' => 'application/json',
			'anthropic-version' => '2023-06-01',
		];

		if ($authMode === 'oauth') {
			// Claude Max / subscription token — the Bearer + beta-flag flow.
			$headers['Authorization'] = 'Bearer ' . BrokerHttpClient::BROKER_MANAGED_KEY;
			$headers['anthropic-beta'] = 'oauth-2025-04-20';

			return $headers;
		}

		// Standard Console / API key.
		$headers['x-api-key'] = BrokerHttpClient::BROKER_MANAGED_KEY;

		return $headers;
	}//end buildAnthropicHeaders()

	/**
	 * Call Anthropic's Messages endpoint (`POST /v1/messages`) directly with the full
	 * message history, through the credential broker — with full tool-use support.
	 *
	 * Sibling of `callFireworksChat()`: no LLPhant `AnthropicChat` instance is used
	 * (LLPhant requires a concrete Guzzle client and exposes no seam for the OAuth
	 * header set). Hermiq's LLPhant message history is mapped to the Messages API shape:
	 * `system` messages are hoisted into the top-level `system` field (Anthropic keeps
	 * the system prompt out of `messages`), and `user`/`assistant` turns become
	 * `messages[{role, content}]`.
	 *
	 * Tool use (anthropic-agent-provider follow-up): when `$functions` (OpenAI-style
	 * `{name, description, parameters}`) AND a `$toolExecutor` are both supplied, the
	 * OpenAI-style schema is mapped to Anthropic `tools: [{name, description,
	 * input_schema}]`. When the model stops with `stop_reason: "tool_use"`, each
	 * `content[]` block of `type: "tool_use"` is executed through `$toolExecutor` — which
	 * is Hermiq's governed engine (guardrails, approval gate, redaction, tracing all
	 * stay there) — and the result is fed back as an Anthropic `tool_result` content
	 * block, looping until the model ends its turn. The driver only translates the wire
	 * format both ways; it NEVER executes a tool itself. The return contract mirrors the
	 * OpenAI/Fireworks path exactly: the final assistant text as a plain string.
	 *
	 * @param string $credentialId Broker credential UUID — NOT a key. Hermiq has
	 *                             no Anthropic secret; the broker holds it and
	 *                             injects it.
	 * @param string $model Model identifier (e.g. `claude-opus-4-8`).
	 * @param string $baseUrl Base API URL (e.g. `https://api.anthropic.com/v1`).
	 * @param array $messageHistory Array of LLPhant Message objects.
	 * @param string $authMode Auth mode: `api_key` (default) or `oauth`.
	 * @param int|null $maxTokens Max output tokens; null uses the 4096 default.
	 *                            The Messages API requires a value, so null is
	 *                            resolved at the request rather than omitted.
	 * @param array $functions OpenAI-style function/tool definitions.
	 * @param callable|null $toolExecutor `fn(string $name, array $input): string` — Hermiq's
	 *                                    governed tool executor. When null, tools are NOT
	 *                                    offered to the model (a pure text turn), so the
	 *                                    model can never request a tool Hermiq cannot run.
	 * @param string $executionMode Transport: `http` (default — the direct Messages
	 *                              API, unchanged) or `cli` (the `hermiq-llm-runner`
	 *                              ExApp running the official `claude` CLI). Defaulted
	 *                              so the signature stays backward-compatible: a call
	 *                              site that does not pass it keeps today's exact
	 *                              behaviour. On `cli`, a text-only turn runs
	 *                              directly and a tool-requiring turn is served
	 *                              over Hermiq's governed MCP endpoint —
	 *                              {@see callAnthropicCli()}.
	 * @param string|null $agentId The acting agent's UUID (cli-runner-governed-mcp-and-egress).
	 *                             Required to govern a tool-requiring `cli` turn: it
	 *                             binds the per-run token and lets the governed MCP
	 *                             endpoint resolve the run's granted tools. Null on the
	 *                             `http` path and on text-only `cli` turns, where it is
	 *                             unused.
	 * @param string $conversationId The conversation this turn belongs to. Threaded through
	 *                               so a tool step published on the run-step bus reaches
	 *                               the stream watching this conversation.
	 *
	 * @return string Generated response text (the final assistant turn).
	 *
	 * @throws \Exception If the HTTP call fails or the response is malformed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)   API call requires handling many response scenarios
	 * @SuppressWarnings(PHPMD.NPathComplexity)        API call requires handling many response scenarios
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Tool-loop orchestration plus API error handling
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors callFireworksChat's direct-HTTP signature
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-execution-mode-selects-the-anthropic-transport-and-defaults-to-http
	 */
	public function callAnthropicChat(
		string $credentialId,
		string $model,
		string $baseUrl,
		array $messageHistory,
		string $authMode = 'api_key',
		?int $maxTokens = null,
		array $functions = [],
		?callable $toolExecutor = null,
		string $executionMode = 'http',
		?string $agentId = null,
		string $conversationId = '',
	): string {
		// `cli` routes the turn through the hermiq-llm-runner ExApp instead of the direct
		// Messages API. Branch BEFORE any HTTP assembly: the two transports share nothing
		// below this point, and `http` must stay bit-for-bit unaffected.
		if ($executionMode === 'cli') {
			return $this->callAnthropicCli(
				credentialId: $credentialId,
				model: $model,
				messageHistory: $messageHistory,
				functions: $functions,
				agentId: $agentId,
				conversationId: $conversationId
			);
		}

		$url = rtrim($baseUrl, '/') . '/messages';

		// Anthropic keeps the system prompt OUT of `messages`; hoist system turns into
		// the top-level `system` field and map user/assistant turns through.
		$mapped = $this->mapHistoryToAnthropicMessages(messageHistory: $messageHistory);
		$system = $mapped['system'];
		$messages = $mapped['messages'];

		// Tools are offered ONLY when both a schema AND an executor are present — the
		// model must never be told about a tool Hermiq cannot then run for it.
		$tools = [];
		if (empty($functions) === false && $toolExecutor !== null) {
			$tools = $this->buildAnthropicTools(functions: $functions);
		} elseif (empty($functions) === false) {
			$this->logger->warning(
				message: '[ProviderFactory] Anthropic turn has tools but no executor; running text-only.',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'functionCount' => count($functions),
				]
			);
		}

		$headers = $this->buildAnthropicHeaders(authMode: $authMode);
		$text = '';

		for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++) {
			$payload = [
				'model' => $model,
				'max_tokens' => ($maxTokens ?? 4096),
				'messages' => $messages,
			];

			if ($system !== '') {
				$payload['system'] = $system;
			}

			if (empty($tools) === false) {
				$payload['tools'] = $tools;
			}

			$data = $this->postToAnthropic(credentialId: $credentialId, url: $url, headers: $headers, payload: $payload, model: $model);
			$parsed = $this->parseAnthropicResponse(data: $data);
			$text = $parsed['text'];

			// No tool call requested (or no executor to run one) — the turn is complete.
			if ($tools === [] || $toolExecutor === null || $parsed['stopReason'] !== 'tool_use' || $parsed['toolCalls'] === []) {
				break;
			}

			// Echo the assistant's tool_use turn back verbatim, run each requested tool
			// through Hermiq's governed engine (the executor), and feed the results back.
			$messages[] = [
				'role' => 'assistant',
				'content' => $parsed['content'],
			];
			$toolResults = [];
			foreach ($parsed['toolCalls'] as $toolCall) {
				$result = (string)$toolExecutor($toolCall['name'], $toolCall['input']);
				$toolResults[] = [
					'tool_use_id' => $toolCall['id'],
					'content' => $result,
				];
			}

			$messages[] = [
				'role' => 'user',
				'content' => $this->buildAnthropicToolResultBlocks(toolResults: $toolResults),
			];
		}//end for

		return $text;
	}//end callAnthropicChat()

	/**
	 * Run ONE Anthropic turn through the `hermiq-llm-runner` ExApp's `claude` CLI.
	 *
	 * The `cli` sibling of the `http` path above, under the same seam: the Engine, the
	 * handlers and the SSE controller never learn which transport ran — this returns the
	 * completion as a plain string exactly as `callAnthropicChat()` does.
	 *
	 * It exists because Anthropic categorically refuses a Claude Max/Pro subscription OAuth
	 * token on the raw Messages API (HTTP 429 `rate_limit_error` carrying no rate-limit
	 * counters — see the 429 handler below); the official CLI is the ToS-sanctioned path for
	 * a subscription.
	 *
	 * **Text-only turns run directly; tool-requiring turns are GOVERNED, never refused.**
	 * `claude -p` accepts no tool schema, so custom tools reach it only via MCP. When
	 * `$functions` is non-empty this method mints a per-run token, assembles the governed
	 * MCP server config (Hermiq's own `POST /api/mcp/run`, bearer-authenticated by that
	 * token), and hands both to the runner in the `/run` payload — so every tool call the
	 * CLI makes lands back in Hermiq's `FacadeToolInvoker`. It is the exact inverse of the
	 * link-2 refusal: instead of failing a tool-using agent, it governs it.
	 *
	 * It still FAILS LOUD — `ProviderUnavailableException` (503), never a text-only
	 * downgrade — when a tool-requiring turn cannot be governed: no agent identity to bind
	 * the run to, no user context, the token cannot be minted, or the MCP endpoint URL
	 * cannot be resolved (so the config cannot be written). This deliberately does NOT copy
	 * the `http` path's fail-open (tools + no executor → warn → run text-only): a tool-less
	 * agent looks completely healthy and simply never calls a tool.
	 *
	 * Order is load-bearing — availability and the run token are established BEFORE the
	 * subscription credential is resolved, so a doomed turn pulls no secret from the vault
	 * and spends no subscription quota. The run token is consumed in a `finally` so it never
	 * outlives the turn.
	 *
	 * @param string $credentialId Broker credential UUID — NOT a secret.
	 * @param string $model Model identifier; empty ⇒ the CLI's own default.
	 * @param array $messageHistory Array of LLPhant Message objects.
	 * @param array $functions OpenAI-style tool definitions. Non-empty ⇒ governed MCP turn.
	 * @param string|null $agentId The acting agent's UUID (binds the per-run token). Required
	 *                             for a tool-requiring turn; unused for a text-only one.
	 * @param string $conversationId The conversation this turn belongs to, so a tool step
	 *                               published on the run-step bus reaches the right stream.
	 *
	 * @return string The completion text.
	 *
	 * @throws ProviderUnavailableException When a tool-requiring turn cannot be governed (503),
	 *                                      the runner or AppAPI is unavailable (503), the
	 *                                      credential cannot be resolved or is organisation-scope
	 *                                      (503), or the dispatch fails (503).
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-cli-completion-is-mapped-back-into-the-driver-response-and-the-sse-envelope
	 */
	private function callAnthropicCli(
		string $credentialId,
		string $model,
		array $messageHistory,
		array $functions = [],
		?string $agentId = null,
		string $conversationId = '',
	): string {
		$governedMcpConfig = null;
		$runToken = null;

		// A turn is POOLABLE only when it belongs to an identified conversation.
		// Without one there is nothing safe to key a remembering process by, so the
		// turn takes the cold path — the behaviour that shipped before pooling.
		$poolKey = $this->poolKeyFor(conversationId: $conversationId, agentId: $agentId, model: $model);

		// 1. Availability — each component named separately, before any secret is touched.
		$this->assertCliRunnerAvailable();

		$uid = $this->currentUid();

		try {
			// 2. A tool-requiring turn is GOVERNED, not refused: mint a run token and
			// assemble the MCP server config the runner hands to the CLI. Done BEFORE the
			// subscription credential is resolved, so a turn that cannot be governed pulls
			// no secret from the vault. Fails LOUD when governance is impossible — never a
			// silent text-only downgrade.
			//
			// An EMPTY $functions here means the agent is legitimately tool-less: an agent
			// whose grants were configured but matched nothing never reaches this point,
			// because `ToolLoop::listAgentFunctions()` raises ToolGrantResolutionException
			// at the resolution site. That distinction cannot be recovered here — both
			// cases arrive as an empty array — which is exactly why it is enforced there.
			// EVERY cli turn needs a run token, not only a tool-requiring one: it is also
			// the identity the egress proxy presents to the PDP, and the runner container
			// has no default route — a turn without one cannot reach api.anthropic.com at
			// all. The two mints differ in STRICTNESS, deliberately:
			//
			// - a GOVERNED turn must have an agent (its grants are what the token
			// resolves) and fails LOUD without one;
			// - a text-only turn may legitimately have NO agent — conversation-title
			// generation calls this path with `agentId: null` — so it gets a tolerant
			// egress-only identity instead. Minting strictly here would 503 every
			// title generation the moment `executionMode: cli` is switched on.
			if (empty($functions) === false) {
				$runToken = $this->mintGovernedRunToken(
					agentId: $agentId,
					uid: $uid,
					conversationId: $conversationId,
					pooled: ($poolKey !== '')
				);
				$governedMcpConfig = $this->buildGovernedMcpConfig(runToken: $runToken);
			}

			if (empty($functions) === true) {
				$runToken = $this->mintEgressRunToken(agentId: $agentId, uid: $uid);
			}

			// 3. Credential (subscription token). Local variable only: never stored on the
			// ChatDriver (handlers hold that object), never logged, never in an exception
			// message, never in a trace.
			$token = $this->resolveCliToken(credentialId: $credentialId, uid: $uid);

			// 4+5. Dispatch and map.
			return $this->dispatchCliTurn(
				model: $model,
				messageHistory: $messageHistory,
				token: $token,
				uid: $uid,
				mcpConfig: $governedMcpConfig,
				runToken: $runToken,
				poolKey: $poolKey
			);
		} finally {
			// The run token dies with the turn (success, error, or timeout), so a token
			// outliving its run has no legitimate caller.
			//
			// EXCEPT on a poolable turn. The CLI reads `--mcp-config` ONCE at startup
			// and never re-reads it (measured 2026-08-17, `REREAD=false`), so a pooled
			// process cannot be handed a fresh token: consuming here would kill the
			// token its own next turn must present, and the failure is silent — the
			// model simply reports it has no tools.
			//
			// This is design D0.1 option 1, adopted deliberately: the token's lifetime
			// follows the pooled process instead of the turn. The window is bounded by
			// the pool's idle/hard cap, which is therefore a SECURITY parameter and not
			// merely a performance one. The token still expires on its own TTL if the
			// runner never reaps.
			$consume = ($runToken !== null && $this->runTokenService !== null);
			if ($consume === true && $poolKey === '') {
				$this->runTokenService->consume(token: $runToken);
			}
		}//end try

	}//end callAnthropicCli()

	/**
	 * Derive the key a pooled CLI process is reused under, or '' for the cold path.
	 *
	 * The key is the CONVERSATION, plus the agent and model whose process it is.
	 * That is narrower than the (agent, user) key design D1 originally proposed,
	 * and the narrowing is not caution — it is required. A stream-json process
	 * REMEMBERS its turns: measured 2026-08-17, a second turn recalled a canary
	 * word with no history re-sent. Any key wider than one conversation therefore
	 * carries one conversation's context into another's turn.
	 *
	 * The agent is in the key because a process's granted tool set is fixed at
	 * startup (the CLI never re-reads `--mcp-config`), so two agents must never
	 * share a process. The model is in the key because it is a startup argument.
	 *
	 * An empty conversation id yields '' — no pooling, cold path, previous
	 * behaviour. That is the correct answer for title generation and any other
	 * caller with no conversation to belong to.
	 *
	 * @param string      $conversationId The conversation UUID, or '' when unknown.
	 * @param string|null $agentId        The acting agent UUID, or null.
	 * @param string      $model          The model id (a startup argument).
	 *
	 * @return string The pool key, or '' when the turn must not be pooled.
	 */
	private function poolKeyFor(string $conversationId, ?string $agentId, string $model): string {
		if ($conversationId === '' || $agentId === null || $agentId === '') {
			return '';
		}

		// Hashed so the key carries no identifier into the runner's logs.
		return hash('sha256', $conversationId . '|' . $agentId . '|' . $model);
	}//end poolKeyFor()

	/**
	 * Mint the per-run token that authenticates the governed MCP + egress endpoints for a
	 * tool-requiring `cli` turn. Fails LOUD (503) when the turn cannot be governed: no agent
	 * identity to bind it to, no user context, or the token service is unavailable.
	 *
	 * @param string|null $agentId The acting agent's UUID.
	 * @param string|null $uid The acting user's UID.
	 * @param string      $conversationId The conversation the run belongs to, or ''.
	 * @param bool        $pooled Whether the turn may be served by a pooled process,
	 *                    in which case the token must outlive the turn (D0.1 option 1).
	 *
	 * @return string The minted run token (never logged, never on argv).
	 *
	 * @throws ProviderUnavailableException When the turn cannot be governed (503).
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$pooled` is a genuine two-mode
	 *   selector, not a switch over two behaviours that wanted separate methods: a
	 *   pooled token binds to a warmed process that outlives the turn, a per-turn
	 *   token does not, and every call site passes it BY NAME (`pooled: true`).
	 */
	private function mintGovernedRunToken(
		?string $agentId,
		?string $uid,
		string $conversationId = '',
		bool $pooled = false,
	): string {
		if ($this->runTokenService === null) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot serve a tool-requiring turn: the governed run-token '
				. 'service is unavailable, so the turn cannot be governed. It was refused rather than run '
				. 'without its tools.',
				503
			);
		}

		if ($agentId === null || $agentId === '') {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot govern a tool-requiring turn without an agent '
				. 'identity to bind the run to. It was refused rather than run without its tools.',
				503
			);
		}

		if ($uid === null || $uid === '') {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot govern a tool-requiring turn without a user context '
				. 'to bind the run token to. It was refused rather than run without its tools.',
				503
			);
		}

		try {
			// A poolable turn's token must stay valid for as long as the process
			// that holds it can be reused, because the CLI never re-reads the config
			// it was given at startup. Otherwise the default per-run TTL applies and
			// the token still dies with the turn.
			$ttlSeconds = null;
			if ($pooled === true) {
				$ttlSeconds = self::POOL_PROCESS_LIFETIME_SECONDS + self::CLI_DISPATCH_TIMEOUT_SLACK_SECONDS;
			}

			return $this->runTokenService->mint(
				runId: bin2hex(random_bytes(16)),
				agentId: $agentId,
				userId: $uid,
				conversationId: $conversationId,
				ttlSeconds: $ttlSeconds
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[ProviderFactory] Anthropic cli run token could not be minted',
				['reason' => $e->getMessage()]
			);

			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot serve a tool-requiring turn: the per-run token could '
				. 'not be minted. It was refused rather than run without its tools.',
				503
			);
		}//end try

	}//end mintGovernedRunToken()

	/**
	 * The token and MCP config a warm-up must be built with.
	 *
	 * ⚠️ Exactly ONE token is minted per call, and which one depends on the
	 * posture. An earlier attempt at removing the `else` this replaced hoisted
	 * the egress mint above the branch so both paths could share it — which
	 * quietly minted a second, unused token on every governed warm-up. Minting
	 * is a side effect, not a default value, and it does not belong in the slot
	 * where a default would go.
	 *
	 * @param bool $governed Whether the warmed process is governed.
	 * @param string|null $agentId The acting agent's UUID.
	 * @param string|null $uid The acting user's UID.
	 * @param string $conversationId The conversation being warmed for.
	 *
	 * @return array{0: string, 1: array|null} The run token and the MCP config, if any.
	 */
	private function mintWarmupToken(
		bool $governed,
		?string $agentId,
		?string $uid,
		string $conversationId
	): array {
		if ($governed === false) {
			return [$this->mintEgressRunToken(agentId: $agentId, uid: $uid), null];
		}

		$runToken = $this->mintGovernedRunToken(
			agentId: $agentId,
			uid: $uid,
			conversationId: $conversationId,
			pooled: true
		);

		return [$runToken, $this->buildGovernedMcpConfig(runToken: $runToken)];
	}//end mintWarmupToken()

	/**
	 * Mint the egress-only run identity for a TEXT-ONLY cli turn.
	 *
	 * Deliberately tolerant where `mintGovernedRunToken()` is strict. A text-only
	 * turn may legitimately have no agent at all — conversation-title generation
	 * reaches this path with `agentId: null` — and it has no tools to lose, so
	 * there is nothing to fail loud about. It still needs an identity to get out
	 * of the container, because the proxy is the only route and it denies an
	 * identity-less connection.
	 *
	 * Returns '' (rather than throwing) whenever a token cannot be minted, so the
	 * turn is never blocked by the absence of a capability it does not need:
	 *   - no `RunTokenService` (an older DI wiring) — nothing to mint with;
	 *   - no acting user — nothing to bind to.
	 * With an empty token the runner injects no proxy env. Under the governed
	 * posture the CLI then has no way out and the turn fails as a provider error
	 * (correct: fail-closed); under the legacy jail posture it is a no-op.
	 *
	 * The token binds `agentId: ''` when there is no agent. That is safe: the MCP
	 * endpoint resolves the granted tool set FROM the bound agent, so a token with
	 * no agent resolves to no tools — it can open connections policy allows, and
	 * nothing else. A text-only turn is never handed the MCP endpoint's address
	 * anyway (it carries no `mcpConfig`).
	 *
	 * @param string|null $agentId The acting agent's UUID, when there is one.
	 * @param string|null $uid The acting user's UID.
	 *
	 * @return string The token, or '' when one could not be minted.
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
	 */
	private function mintEgressRunToken(?string $agentId, ?string $uid): string {
		if ($this->runTokenService === null || $uid === null || $uid === '') {
			return '';
		}

		try {
			return $this->runTokenService->mint(
				runId: bin2hex(random_bytes(16)),
				agentId: ($agentId ?? ''),
				userId: $uid
			);
		} catch (Throwable $e) {
			// Never fatal: a text-only turn has no tools to protect, and the
			// network layer already denies anything this token would have allowed.
			$this->logger->warning(
				'[ProviderFactory] Anthropic cli egress run token could not be minted',
				['reason' => $e->getMessage()]
			);

			return '';
		}//end try

	}//end mintEgressRunToken()

	/**
	 * Assemble the governed MCP server config the runner writes to a 0600 file and hands to
	 * the CLI. The bearer token rides in the `headers`, never on argv. Fails LOUD (503) when
	 * the endpoint URL cannot be resolved (so the config cannot be written).
	 *
	 * @param string $runToken The minted per-run token.
	 *
	 * @return array<string, mixed> The `{mcpServers: {...}}` config.
	 *
	 * @throws ProviderUnavailableException When the MCP endpoint URL cannot be resolved (503).
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-cli-is-locked-to-hermiqs-governance-by-its-invocation-flags
	 */
	private function buildGovernedMcpConfig(string $runToken): array {
		if ($this->urlGenerator === null) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot serve a tool-requiring turn: the governed MCP '
				. 'endpoint URL could not be resolved, so the MCP config could not be written. It was '
				. 'refused rather than run without its tools.',
				503
			);
		}

		$mcpUrl = $this->urlGenerator->linkToRouteAbsolute('hermiq.mcpRun.handle');

		// The linkToRouteAbsolute() call returns the URL Nextcloud publishes to BROWSERS
		// (overwrite.cli.url / the trusted domain). The CLI dials this endpoint from
		// INSIDE the runner container, where that host frequently does not resolve to
		// Nextcloud — a stock dev instance publishes `http://localhost`, which inside
		// the container is the container itself, so every tool call would fail with a
		// connection error that looks like a broken endpoint. AppAPI already records
		// the container-facing origin (its daemon's `nextcloud_url`, e.g.
		// `http://nextcloud`); `mcp_run_base_url` lets the operator pin the same value
		// here. Unset → the published URL is used unchanged (correct whenever
		// Nextcloud's public origin IS reachable from the container).
		$baseOverride = trim($this->appConfig?->getValueString('hermiq', 'mcp_run_base_url', '') ?? '');
		if ($baseOverride !== '' && $mcpUrl !== '') {
			$path = (string)parse_url($mcpUrl, PHP_URL_PATH);
			if ($path !== '') {
				$mcpUrl = rtrim($baseOverride, '/') . $path;
			}
		}

		if ($mcpUrl === '') {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot serve a tool-requiring turn: the governed MCP '
				. 'endpoint URL resolved empty. It was refused rather than run without its tools.',
				503
			);
		}

		return [
			'mcpServers' => [
				'hermiq' => [
					'type' => 'http',
					'url' => $mcpUrl,
					'headers' => [
						'Authorization' => 'Bearer ' . $runToken,
						'OCS-APIRequest' => 'true',
					],
				],
			],
		];

	}//end buildGovernedMcpConfig()

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
	private function assertCliRunnerAvailable(): void {
		if ($this->appManager === null) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" is unavailable: the Nextcloud app manager could not be '
				. 'resolved, so the runner ExApp cannot be detected. Use executionMode "http".',
				503
			);
		}

		if ($this->appManager->isInstalled(self::APP_API_ID) === false) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" is unavailable: the AppAPI app ("' . self::APP_API_ID . '") is '
				. 'not enabled. Enable it, or use executionMode "http".',
				503
			);
		}

		// Resolved lazily by class-name string, never a hard `use` or constructor type:
		// Hermiq MUST still boot and still serve `http` with AppAPI absent.
		if (class_exists(self::APP_API_PUBLIC_FUNCTIONS) === false) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" is unavailable: the AppAPI public interface could not be '
				. 'loaded. Use executionMode "http".',
				503
			);
		}

		$exApp = $this->appApiPublicFunctions()->getExApp(self::RUNNER_EXAPP_ID);
		if ($exApp === null) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" is unavailable: the "' . self::RUNNER_EXAPP_ID . '" ExApp is not '
				. 'installed. Install it, or use executionMode "http".',
				503
			);
		}

		if (($exApp['enabled'] ?? false) === false) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" is unavailable: the "' . self::RUNNER_EXAPP_ID . '" ExApp is '
				. 'installed but disabled. Enable it, or use executionMode "http".',
				503
			);
		}

	}//end assertCliRunnerAvailable()

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
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Server::get is deliberate lazy resolution
	 *   of the optional OpenRegister broker (guarded by class_exists above) so this class
	 *   stays constructible when the broker is absent.
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
	 */
	private function resolveCliToken(string $credentialId, ?string $uid): string {
		$this->assertPersonalScopeCredential(credentialId: $credentialId);

		if (class_exists(BrokerHttpClient::BROKER_CLASS) === false) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" is unavailable: the OpenRegister credential broker is not '
				. 'available, so the subscription token cannot be resolved.',
				503
			);
		}

		try {
			$broker = Server::get(BrokerHttpClient::BROKER_CLASS);
			$token = $broker->resolveInjectable($credentialId, BrokerHttpClient::APP_ID, $uid);
		} catch (Throwable $e) {
			// The broker's own denial reasons (owner/allowedApps) are operator-relevant but
			// may name internals; log server-side, surface a static message (ADR-005).
			$this->logger->warning(
				'[ProviderFactory] Anthropic cli credential could not be resolved through the broker',
				[
					'credentialId' => $credentialId,
					'reason' => $e->getMessage(),
				]
			);

			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot use this credential: the credential broker refused to '
				. 'resolve it. Check that the credential is owned by the acting user and allows the "hermiq" '
				. 'app.',
				503
			);
		}//end try

		// A null is a ROUTING signal from the broker ("not inject-only — use request()
		// instead"), not a denial. But a CLI has no request() fallback, so here it is fatal.
		// Do NOT fall back to `http` with a subscription token the Messages API refuses anyway.
		if ($token === null || $token === '') {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" cannot use this credential: it is not an inject-only '
				. 'credential, so its secret cannot be placed in the CLI\'s environment. Select an '
				. '"anthropic-cli" (Claude Max subscription) credential, or use executionMode "http".',
				503
			);
		}

		return $token;
	}//end resolveCliToken()

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
	 * Start the pooled CLI process for a conversation WITHOUT running a turn.
	 *
	 * The first question of a conversation is always the slow one, because
	 * nobody has paid the process start yet — measured on this instance, 11.2s
	 * for a trivial prompt cold against 3.2s warm. Called when the chat opens or
	 * an agent is picked, this moves that cost to while the user is still
	 * typing.
	 *
	 * Costs a process start and NOTHING else: no prompt is sent, so there is no
	 * inference, no tokens and no vendor request. It is not a "hello" turn.
	 *
	 * Best-effort by contract — a warm-up that fails must be invisible, because
	 * the turn that follows works exactly as it does today without it.
	 *
	 * @param string      $credentialId The credential to resolve.
	 * @param string      $model        The model id (part of the pool key).
	 * @param string|null $agentId      The acting agent's UUID.
	 * @param string      $conversationId The conversation being opened.
	 * @param bool        $governed     Whether the agent holds tools, so the
	 *                    warmed process carries the same governed argv the real
	 *                    turn will need.
	 *
	 * @return bool True when a warm-up was dispatched.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$governed` is a genuine
	 *   two-mode selector. The warmed process must be built EXACTLY as the first
	 *   turn will build it — same argv, same token, same mcp config — so the
	 *   posture is an input to one procedure rather than two procedures sharing a
	 *   name. Splitting it would be the way to get the two out of step, which is
	 *   the failure the warm-up exists to avoid. Passed by name at the call site.
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-the-cli-process-is-warmed-before-the-first-question-not-by-it
	 */
	public function warmAnthropicCli(
		string $credentialId,
		string $model,
		?string $agentId,
		string $conversationId,
		bool $governed = true,
	): bool {
		$poolKey = $this->poolKeyFor(conversationId: $conversationId, agentId: $agentId, model: $model);
		if ($poolKey === '') {
			return false;
		}

		try {
			$this->assertCliRunnerAvailable();
			$uid = $this->currentUid();

			// The warmed process must be built EXACTLY as the first turn will
			// build it — same governed argv, same token, same mcp config — or it
			// is a process that turn cannot use and the warm-up is worse than
			// useless: it occupies a pool slot and saves nothing.
			[$runToken, $governedMcpConfig] = $this->mintWarmupToken(
				governed: $governed,
				agentId: $agentId,
				uid: $uid,
				conversationId: $conversationId
			);

			$token = $this->resolveCliToken(credentialId: $credentialId, uid: $uid);

			$this->appApiPublicFunctions()->exAppRequest(
				self::RUNNER_EXAPP_ID,
				self::RUNNER_ROUTE,
				$uid,
				'POST',
				[
					'provider' => 'anthropic',
					'model' => $model,
					'messages' => [],
					'credentialEnv' => ['CLAUDE_CODE_OAUTH_TOKEN' => $token],
					'mcpConfig' => $governedMcpConfig,
					'runToken' => $runToken,
					'poolKey' => $poolKey,
					'poolLifetimeSeconds' => self::POOL_PROCESS_LIFETIME_SECONDS,
					'warmOnly' => true,
				],
				['timeout' => self::WARM_TIMEOUT_SECONDS]
			);

			return true;
		} catch (Throwable $e) {
			// Silent by design. A failed warm-up costs the user nothing but the
			// speed-up they would have had.
			$this->logger->debug(
				'[ProviderFactory] CLI pool warm-up did not start',
				['reason' => $e->getMessage()]
			);

			return false;
		}//end try

	}//end warmAnthropicCli()

	/**
	 * POST one turn to the `hermiq-llm-runner` ExApp and return its completion.
	 *
	 * The single place that speaks to the runner, so the request shape — and in
	 * particular the exact `credentialEnv` key the runner's Anthropic adapter
	 * allowlists — is stated once. A key outside that allowlist is dropped
	 * WITHOUT an error, producing an unauthenticated CLI rather than a 400, so
	 * this is not a detail that tolerates a second spelling elsewhere.
	 *
	 * @param string $model The model identifier; empty ⇒ the CLI's own default.
	 * @param array $messageHistory Array of LLPhant Message objects.
	 * @param string $token The broker-issued OAuth token, passed as credentialEnv.
	 * @param string|null $uid The acting user, for the runner's own accounting.
	 * @param array|null $mcpConfig The governed MCP config for a tool-requiring turn;
	 *                              null for a text-only one.
	 * @param string $runToken The per-run bearer token the MCP endpoint verifies.
	 * @param string $poolKey Selects the runner's warmed process; empty ⇒ unpooled.
	 *
	 * Two AppAPI defaults are traps here and BOTH are overridden explicitly; neither is
	 * visible in `exAppRequest()`'s signature:
	 *
	 * 1. **`timeout` defaults to 3 SECONDS** (`AppAPIService::prepareRequestToExApp()`, guarded
	 *    by `if (!isset($options['timeout']))`) while the runner allows the CLI 120s. Omitting
	 *    it makes the feature 0% functional: every turn fails at 3s while the container runs to
	 *    completion and bills the user's real subscription. An explicit timeout is passed
	 *    instead ({@see cliDispatchOptions()}) — the runner's own 120s plus slack, so it is
	 *    GREATER than the runner's kill by construction and the runner's kill-and-report wins
	 *    the race, giving the operator the real reason instead of a generic timeout.
	 * 2. **AppAPI NEVER throws** — failure is the RETURN VALUE, in three shapes: a caught
	 *    `\Exception` returns `['error' => ...]` (timeouts included), a missing ExApp returns
	 *    `['error' => 'ExApp ... not found']`, and `http_errors => false` means a 502 arrives as
	 *    an ordinary IResponse. The checks below therefore run in a load-bearing order — array
	 *    error, then status, then a usable `text`. Any other order reads an error string as the
	 *    model's answer.
	 *
	 * @return string The completion text.
	 *
	 * @throws ProviderUnavailableException On any transport, status, or shape failure (503).
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-cli-is-locked-to-hermiqs-governance-by-its-invocation-flags
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
	 */
	private function dispatchCliTurn(
		string $model,
		array $messageHistory,
		string $token,
		?string $uid,
		?array $mcpConfig = null,
		string $runToken = '',
		string $poolKey = '',
	): string {
		// `credentialEnv`'s key is EXACTLY the one the runner's anthropic adapter allowlists.
		// A key outside the allowlist is dropped WITHOUT an error, which would yield an
		// unauthenticated CLI rather than a 400 — so this string has to be exactly right.
		$params = [
			'provider' => 'anthropic',
			'model' => $model,
			'messages' => $this->mapHistoryToCliMessages(messageHistory: $messageHistory),
			'credentialEnv' => ['CLAUDE_CODE_OAUTH_TOKEN' => $token],
		];

		// A tool-requiring turn carries the governed MCP config; the runner writes it to a
		// 0600 scratch file and locks the CLI down with `--tools "" --strict-mcp-config
		// --mcp-config <path>`. A text-only turn omits it entirely (unchanged link-2 path).
		if ($mcpConfig !== null) {
			$params['mcpConfig'] = $mcpConfig;
		}

		// The run identity for the egress PEP. The runner turns this into
		// `HTTPS_PROXY=http://run:<token>@<proxy>` in the CLI's ENVIRONMENT — never on
		// argv, where the process table would expose it. Sent on every turn: the proxy
		// is the container's only route out.
		if ($runToken !== '') {
			$params['runToken'] = $runToken;
		}

		// The pool key is the CONVERSATION, never the agent or the user. A
		// stream-json process REMEMBERS its turns (measured: a second turn recalled
		// a canary with no history re-sent), so any key wider than one conversation
		// carries one conversation's context into another's turn. Absent key = the
		// runner takes the cold path, which is always correct and never pooled.
		if ($poolKey !== '') {
			$params['poolKey'] = $poolKey;
			// Sent rather than configured separately in the runner: this bounds BOTH
			// the process's reusable life and its token's validity, and the two
			// drifting apart is precisely the failure that ends in a live process
			// holding a dead token.
			$params['poolLifetimeSeconds'] = self::POOL_PROCESS_LIFETIME_SECONDS;
		}

		$result = $this->appApiPublicFunctions()->exAppRequest(
			self::RUNNER_EXAPP_ID,
			self::RUNNER_ROUTE,
			$uid,
			'POST',
			$params,
			$this->cliDispatchOptions()
		);

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

		// Check 3 — only now is the body the model's answer.
		return $this->mapCliCompletion(body: (string)$result->getBody());
	}//end dispatchCliTurn()

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
		return ['timeout' => (self::RUNNER_CLI_TIMEOUT_SECONDS + self::CLI_DISPATCH_TIMEOUT_SLACK_SECONDS)];
	}//end cliDispatchOptions()

	/**
	 * Map the runner's 200 body into the turn's answer.
	 *
	 * The runner returns `{text, toolCalls, usage}`. Only `text` is read:
	 *
	 * - `toolCalls` is structurally ALWAYS `[]` — the runner's `pickToolCalls()` reads a key
	 *   nothing populates, because `run()` has no `tools` — so it is ignored rather than
	 *   treated as reachable behaviour.
	 * - `usage` is the CLI's own object. It is deliberately NOT threaded: the `http` Anthropic
	 *   branch records latency only (`ResponseGenerationHandler`'s `lastUsage`), and this path
	 *   mirrors that shape exactly. Threading richer usage would close a pre-existing gap that
	 *   is present on BOTH branches and is not this change's to close.
	 *
	 * @param string $body The runner's raw 200 response body.
	 *
	 * @return string The completion text.
	 *
	 * @throws ProviderUnavailableException When the body is not decodable or carries no usable text (503).
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-cli-completion-is-mapped-back-into-the-driver-response-and-the-sse-envelope
	 */
	private function mapCliCompletion(string $body): string {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" failed: the runner returned a response Hermiq could not '
				. 'read.',
				503
			);
		}

		$text = ($decoded['text'] ?? null);
		if (is_string($text) === false || $text === '') {
			throw new ProviderUnavailableException(
				'Anthropic executionMode "cli" failed: the runner returned no completion text.',
				503
			);
		}

		return $text;
	}//end mapCliCompletion()

	/**
	 * Flatten an LLPhant message history into the runner's `messages` shape.
	 *
	 * Deliberately NOT {@see mapHistoryToAnthropicMessages()}: that hoists system turns into a
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
	 * on an instance with no AppAPI installed. Mirrors `BrokerHttpClient`'s pattern for the
	 * credential broker. Callers assert {@see APP_API_PUBLIC_FUNCTIONS} exists first.
	 *
	 * `AppAPIService` internals were read as EVIDENCE for this dispatch's traps; they are not
	 * an API to call. `PublicFunctions` is the supported seam.
	 *
	 * @return object AppAPI's `PublicFunctions`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Server::get is deliberate lazy resolution
	 *   of the optional AppAPI interface so Hermiq still boots and serves `http` on an
	 *   instance without AppAPI installed.
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
	 */
	private function appApiPublicFunctions(): object {
		return Server::get(self::APP_API_PUBLIC_FUNCTIONS);
	}//end appApiPublicFunctions()

	/**
	 * Split an LLPhant message history into Anthropic's `{system, messages}` shape.
	 *
	 * System turns are concatenated into the top-level `system` string (Anthropic keeps
	 * the system prompt out of `messages`); user/assistant turns become
	 * `{role, content}` entries.
	 *
	 * @param array $messageHistory Array of LLPhant Message objects.
	 *
	 * @return array{system: string, messages: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function mapHistoryToAnthropicMessages(array $messageHistory): array {
		$systemParts = [];
		$messages = [];
		foreach ($messageHistory as $msg) {
			$role = $msg->role->value;
			if ($role === 'system') {
				$systemParts[] = (string)$msg->content;
				continue;
			}

			// @todo llm-cli-runner-exapp / anthropic-agent-provider — Hermiq's engine never
			// stores prior tool_use/tool_result turns as LLPhant messages today (tool turns
			// live only inside this call's Anthropic loop). If a future history carries them
			// (e.g. LLPhant Message::toolCalls), map them to tool_use/tool_result content
			// blocks here. Until then, pass text turns through — the common path.
			$messages[] = [
				'role' => $role,
				'content' => (string)$msg->content,
			];
		}

		return [
			'system' => implode("\n\n", $systemParts),
			'messages' => $messages,
		];

	}//end mapHistoryToAnthropicMessages()

	/**
	 * Map OpenAI-style function definitions to Anthropic `tools`.
	 *
	 * OpenAI's `{name, description, parameters}` becomes Anthropic's `{name, description,
	 * input_schema}` (the ONLY structural difference is `parameters` → `input_schema`).
	 * Entries without a name are skipped; a missing schema defaults to an empty object
	 * schema so the model still sees a valid, argument-less tool.
	 *
	 * @param array $functions OpenAI-style function definitions.
	 *
	 * @return array<int, array<string, mixed>> Anthropic tool definitions.
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function buildAnthropicTools(array $functions): array {
		$tools = [];
		foreach ($functions as $function) {
			if (is_array($function) === false || isset($function['name']) === false) {
				continue;
			}

			$schema = $function['parameters'] ?? null;
			if (is_array($schema) === false) {
				$schema = ['type' => 'object'];
			}

			if (isset($schema['type']) === false) {
				$schema['type'] = 'object';
			}

			// Anthropic requires `input_schema.properties` to be a JSON object.
			// An argument-less tool has an empty properties map, and PHP's
			// json_encode emits an empty PHP array as `[]` (a JSON array), which
			// Anthropic rejects with "Input should be an object". Force an empty
			// stdClass so it serialises as `{}`.
			// `isset()` is already false for a null properties, so an explicit
			// `=== null` here would be unreachable.
			if (isset($schema['properties']) === false || $schema['properties'] === []) {
				$schema['properties'] = new stdClass();
			}

			$tools[] = [
				'name' => (string)$function['name'],
				'description' => (string)($function['description'] ?? ''),
				'input_schema' => $schema,
			];
		}//end foreach

		return $tools;
	}//end buildAnthropicTools()

	/**
	 * Parse an Anthropic Messages API response into the engine-facing shape.
	 *
	 * Concatenates every `type: "text"` content block into `text`, extracts every
	 * `type: "tool_use"` block into `toolCalls` (`{id, name, input}` — the same
	 * descriptor shape the runner path normalises into), and carries the `stop_reason`,
	 * the raw `content` array (echoed back verbatim as the assistant tool_use turn on the
	 * next loop pass), and token `usage`.
	 *
	 * @param array $data Decoded Anthropic Messages API response.
	 *
	 * @return array{text: string, toolCalls: array<int, array<string, mixed>>,
	 *     stopReason: string, content: array<int, mixed>, usage: array<string, int>}
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function parseAnthropicResponse(array $data): array {
		$content = [];
		if (is_array($data['content'] ?? null) === true) {
			$content = $data['content'];
		}

		$text = '';
		$toolCalls = [];
		foreach ($content as $block) {
			if (is_array($block) === false) {
				continue;
			}

			$type = ($block['type'] ?? '');
			if ($type === 'text') {
				$text .= (string)($block['text'] ?? '');
				continue;
			}

			if ($type === 'tool_use') {
				$input = ($block['input'] ?? []);
				if (is_array($input) === false) {
					$input = [];
				}

				$toolCalls[] = [
					'id' => (string)($block['id'] ?? ''),
					'name' => (string)($block['name'] ?? ''),
					'input' => $input,
				];
			}
		}//end foreach

		$usage = [];
		$rawUsage = ($data['usage'] ?? []);
		if (is_array($rawUsage) === true) {
			$usage = [
				'promptTokens' => (int)($rawUsage['input_tokens'] ?? 0),
				'completionTokens' => (int)($rawUsage['output_tokens'] ?? 0),
			];
		}

		return [
			'text' => $text,
			'toolCalls' => $toolCalls,
			'stopReason' => (string)($data['stop_reason'] ?? ''),
			'content' => $content,
			'usage' => $usage,
		];

	}//end parseAnthropicResponse()

	/**
	 * Build the Anthropic `tool_result` content blocks for one user turn.
	 *
	 * Each `{tool_use_id, content, is_error?}` becomes a `{type: "tool_result",
	 * tool_use_id, content}` block (with `is_error` passed through when set). This is the
	 * inverse of `parseAnthropicResponse()`'s tool_use extraction — a governed tool
	 * result round-trips back into an Anthropic content block.
	 *
	 * @param array $toolResults `[{tool_use_id: string, content: string, is_error?: bool}, ...]`.
	 *
	 * @return array<int, array<string, mixed>> Anthropic tool_result content blocks.
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function buildAnthropicToolResultBlocks(array $toolResults): array {
		$blocks = [];
		foreach ($toolResults as $toolResult) {
			if (is_array($toolResult) === false) {
				continue;
			}

			$block = [
				'type' => 'tool_result',
				'tool_use_id' => (string)($toolResult['tool_use_id'] ?? ''),
				'content' => (string)($toolResult['content'] ?? ''),
			];

			if (($toolResult['is_error'] ?? false) === true) {
				$block['is_error'] = true;
			}

			$blocks[] = $block;
		}

		return $blocks;
	}//end buildAnthropicToolResultBlocks()

	/**
	 * POST one Anthropic Messages request through the broker and return the decoded body.
	 *
	 * Extracted so the tool loop can issue multiple round-trips without duplicating the
	 * broker wiring or the HTTP error mapping.
	 *
	 * @param string $credentialId Broker credential UUID.
	 * @param string $url The `/messages` endpoint URL.
	 * @param array<string,string> $headers Auth/version headers from `buildAnthropicHeaders()`.
	 * @param array<string,mixed> $payload The request payload.
	 * @param string $model Model id (for error messages only).
	 *
	 * @return array<string, mixed> The decoded response body.
	 *
	 * @throws \Exception If the HTTP call fails or the response is malformed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)   API error handling requires many branches
	 * @SuppressWarnings(PHPMD.NPathComplexity)        API error handling requires many branches
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Direct-HTTP request needs each of these
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
	 */
	private function postToAnthropic(string $credentialId, string $url, array $headers, array $payload, string $model): array {
		// Through the broker, not straight out. Hermiq has no Anthropic secret to send: the
		// broker holds it, checks the allow-rules, and injects the auth header server-side.
		$client = new BrokerHttpClient(
			credentialId: $credentialId,
			logger: $this->logger,
			actingUserId: $this->currentUid()
		);

		try {
			$psrResponse = $client->sendRequest(
				new Request(
					'POST',
					$url,
					$headers,
					(string)json_encode($payload)
				)
			);
		} catch (Throwable $e) {
			throw new Exception('Anthropic API request failed: ' . $e->getMessage());
		}

		$httpCode = $psrResponse->getStatusCode();
		$response = (string)$psrResponse->getBody();

		if ($httpCode !== 200) {
			$errorData = json_decode($response, true);
			if (is_array($errorData) === false) {
				$errorData = [];
			}

			$errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? $response;
			if (is_string($errorMessage) === false) {
				$errorMessage = 'Unknown error';
			}

			if ($httpCode === 401 || $httpCode === 403) {
				throw new Exception('Authentication failed. Please check your Anthropic credential.');
			}

			if ($httpCode === 404) {
				throw new Exception("Model not found: {$model}. Please check the model name.");
			}

			if ($httpCode === 429) {
				// `retry-after` only reaches us because BrokerHttpClient forwards the
				// provider's response headers; without it a 429 is opaque.
				$retryAfter = trim($psrResponse->getHeaderLine('retry-after'));
				if ($retryAfter !== '') {
					throw new Exception('Rate limit exceeded. Please try again in ' . $retryAfter . ' seconds.');
				}

				// No retry-after and no `anthropic-ratelimit-*` counters means Anthropic is
				// refusing this credential for this endpoint rather than reporting a usage
				// window — the signature of a subscription (Claude Max) OAuth token, which
				// is not entitled to the direct Messages API. Say so instead of implying
				// that waiting will help.
				if ($psrResponse->hasHeader('anthropic-ratelimit-requests-remaining') === false
					&& $psrResponse->hasHeader('anthropic-ratelimit-tokens-remaining') === false
				) {
					throw new Exception(
						'Anthropic refused this credential for the Messages API (rate_limit_error with no '
						. 'rate-limit counters). A Claude Max/Pro subscription OAuth token is not entitled to '
						. 'the direct API — use an Anthropic API key, or run the subscription through the '
						. 'hermiq-llm-runner ExApp (executionMode: cli), which uses the official Claude CLI.'
					);
				}

				throw new Exception('Rate limit exceeded. Please try again later.');
			}//end if

			throw new Exception("Anthropic API error (HTTP {$httpCode}): {$errorMessage}");
		}//end if

		$data = json_decode($response, true);
		if (is_array($data) === false) {
			throw new Exception('Unexpected Anthropic API response format: ' . $response);
		}

		return $data;
	}//end postToAnthropic()

	/**
	 * Generate text via the `nextcloud` TaskProcessing driver.
	 *
	 * Non-streaming, background/non-interactive only (conversation titles,
	 * summaries) — never call this for SSE chat. Runs the `core:text2text` task
	 * type synchronously via `IManager::runTask()`.
	 *
	 * @param string $prompt The prompt text.
	 * @param string|null $userId The user id scheduling the task (optional).
	 * @param string|null $customId Optional custom task id for correlation.
	 *
	 * @return string The generated text.
	 *
	 * @throws ProviderUnavailableException When no TaskProcessing provider is installed,
	 *                                      or the task fails/returns no output.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
	 */
	public function generateViaNextcloud(string $prompt, ?string $userId = null, ?string $customId = null): string {
		if ($this->taskManager->hasProviders() === false) {
			throw new ProviderUnavailableException('No Nextcloud Assistant (TaskProcessing) provider is installed.', 503);
		}

		$task = new Task(TextToText::ID, ['input' => $prompt], $this->appName, $userId, ($customId ?? ''));

		try {
			$result = $this->taskManager->runTask($task);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ProviderFactory] Nextcloud Assistant task failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			throw new ProviderUnavailableException('Nextcloud Assistant task failed: ' . $e->getMessage(), 0, $e);
		}

		$output = $result->getOutput()['output'] ?? null;
		if (is_string($output) === false || $output === '') {
			throw new ProviderUnavailableException('Nextcloud Assistant returned no output.', 503);
		}

		return $output;
	}//end generateViaNextcloud()

	/**
	 * Generate free text against whichever chat provider `hermiq.llm` currently
	 * selects — one blocking (non-streaming) call, resolving the driver and
	 * dispatching per provider (openai/ollama via LLPhant's `generateText()`,
	 * fireworks via direct HTTP, nextcloud via TaskProcessing).
	 *
	 * This is the shared entry point for background/non-interactive generation
	 * that is NOT part of the ported ChatService orchestration — used by Hermiq's
	 * TaskProcessing PROVIDER implementations (plan §8 move 2), which run the
	 * whole instance's text2text work through Hermiq's configured LLM.
	 *
	 * @param string $prompt The prompt text.
	 * @param string|null $userId The user id (forwarded to the nextcloud driver).
	 * @param bool $allowNextcloud When false, selecting the `nextcloud` driver is
	 *                             rejected. TaskProcessing providers pass false: a
	 *                             Hermiq TaskProcessing provider backed by the
	 *                             `nextcloud` (TaskProcessing) driver would recurse
	 *                             into TaskProcessing endlessly.
	 * @param string|null $organisation Agent-evals: when non-null (including `''` for an
	 *                                  organisation-less scope), the resolved (provider,
	 *                                  model) pair is checked against that organisation's
	 *                                  effective ModelPolicy before generating — the SAME
	 *                                  `createChatDriver()` enforcement chokepoint an
	 *                                  agent-under-test call goes through, so an
	 *                                  LLM-as-judge call is model-policy-governed exactly
	 *                                  like any other Hermiq LLM call. Default `null`
	 *                                  preserves every pre-existing caller's behaviour
	 *                                  unchanged (opt-in, no enforcement).
	 *
	 * @return string The generated text.
	 *
	 * @throws ProviderUnavailableException When no provider is configured/reachable, or
	 *                                      `nextcloud` is selected while `$allowNextcloud`
	 *                                      is false.
	 * @throws ModelPolicyViolationException When `$organisation` is given and the resolved
	 *                                       (provider, model) pair falls outside its
	 *                                       effective ModelPolicy.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)        LLPhant's Message::user() factory is the
	 * library's public API — there is no injectable seam.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$allowNextcloud` is a genuine
	 * two-mode recursion guard (background-work caller allows the TaskProcessing
	 * driver; a TaskProcessing provider-backed caller forbids it to avoid recursing),
	 * not a responsibility split — both modes share the identical generation path.
	 * Mirrors the accepted `ScheduleService::runNow($bypassApprovalGate)` precedent.
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
	 * @spec openspec/changes/agent-evals/tasks.md#task-4-providerfactorygeneratetext-optional-organisation-param
	 */
	public function generateText(string $prompt, ?string $userId = null, bool $allowNextcloud = true, ?string $organisation = null): string {
		$llmConfig = $this->getLlmConfig();
		$driver = $this->createChatDriver(llmConfig: $llmConfig, organisation: $organisation);

		if ($driver->provider === 'fireworks') {
			return $this->callFireworksChat(
				credentialId: (string)$driver->credentialId,
				model: $driver->model,
				baseUrl: (string)$driver->baseUrl,
				messageHistory: [LLPhantMessage::user($prompt)]
			);
		}

		if ($driver->provider === 'anthropic') {
			return $this->callAnthropicChat(
				credentialId: (string)$driver->credentialId,
				model: $driver->model,
				baseUrl: (string)$driver->baseUrl,
				messageHistory: [LLPhantMessage::user($prompt)],
				authMode: (string)$driver->authMode,
				executionMode: $driver->executionMode
			);
		}

		if ($driver->provider === 'nextcloud') {
			if ($allowNextcloud === false) {
				$message = "The 'nextcloud' chat provider cannot back a Nextcloud TaskProcessing provider ";
				$message .= '(it would recurse). Configure openai, ollama, or fireworks.';
				throw new ProviderUnavailableException($message, 400);
			}

			return $this->generateViaNextcloud(prompt: $prompt, userId: $userId);
		}

		// OpenAI / Ollama: driver->chat is a ready LLPhant chat instance.
		return $driver->chat->generateText($prompt);
	}//end generateText()

	/**
	 * Build the `ollama` driver: native OllamaConfig + OllamaChat.
	 *
	 * @param array $ollamaConfig The `ollamaConfig` sub-block.
	 * @param string|null $agentModel Agent model override.
	 * @param float|null $agentTemperature Agent temperature override.
	 * @param int|null $agentMaxTokens Agent max-tokens override (maps to `num_predict`).
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When the Ollama URL is not configured.
	 */
	private function createOllamaDriver(array $ollamaConfig, ?string $agentModel, ?float $agentTemperature, ?int $agentMaxTokens = null): ChatDriver {
		if (empty($ollamaConfig['url']) === true) {
			throw new ProviderUnavailableException('Ollama URL is not configured');
		}

		$config = new OllamaConfig();
		$config->url = rtrim($ollamaConfig['url'], '/') . '/api/';

		$config->model = ($ollamaConfig['chatModel'] ?? 'llama2');
		if (empty($agentModel) === false) {
			$config->model = $agentModel;
		}

		if ($agentTemperature !== null) {
			$config->modelOptions['temperature'] = $agentTemperature;
		}

		// The agent's own ceiling, wired the same way temperature already was.
		// Without this the field was stored, versioned and shown in the UI while
		// having no effect on a single request.
		if ($agentMaxTokens !== null) {
			$config->modelOptions['num_predict'] = $agentMaxTokens;
		}

		$chat = new OllamaChat($config);

		return new ChatDriver(provider: 'ollama', chat: $chat, model: $config->model, maxTokens: $agentMaxTokens);
	}//end createOllamaDriver()

	/**
	 * Build the `openai` driver: OpenAIConfig + OpenAIChat.
	 *
	 * @param array $openaiConfig The `openaiConfig` sub-block.
	 * @param string|null $agentModel Agent model override.
	 * @param float|null $agentTemperature Agent temperature override.
	 * @param string|null $credentialOverride Personal/organisation broker credential id
	 *                                        (agent-credentials) that overrides
	 *                                        `$openaiConfig['credentialId']` when non-empty.
	 * @param int|null $agentMaxTokens Agent max-tokens override (maps to `max_tokens`).
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When the OpenAI API key is not configured.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BrokerHttpClient::isAvailable() is that class's
	 *   own static feature-detection seam for the optional OpenRegister broker — checked
	 *   here so the driver fails loud (503) instead of at request time.
	 *
	 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	private function createOpenAiDriver(
		array $openaiConfig,
		?string $agentModel,
		?float $agentTemperature,
		?string $credentialOverride = null,
		?int $agentMaxTokens = null,
	): ChatDriver {
		$credentialId = (string)($openaiConfig['credentialId'] ?? '');
		if (empty($credentialOverride) === false) {
			$credentialId = $credentialOverride;
		}

		if ($credentialId === '') {
			throw new ProviderUnavailableException(
				'OpenAI has no credential. Select one from the credential broker in the Hermiq LLM settings.',
				503
			);
		}

		if (BrokerHttpClient::isAvailable() === false) {
			throw new ProviderUnavailableException(
				'OpenAI cannot be used: the OpenRegister credential broker is not available.',
				503
			);
		}

		$config = new OpenAIConfig();

		// The openai-php client REQUIRES an api key and sets it as a Bearer header before
		// our client ever sees the request. BrokerHttpClient strips that header and the
		// broker injects the real secret, so what we hand it here is a placeholder, not a
		// key — Hermiq has none to give.
		$config->apiKey = BrokerHttpClient::BROKER_MANAGED_KEY;

		// The seam that makes this possible without rewriting LLPhant: OpenAIChat uses
		// `$config->client` when set, and OpenAI::factory() accepts any PSR-18 client.
		$config->client = OpenAI::factory()
			->withApiKey(BrokerHttpClient::BROKER_MANAGED_KEY)
			->withHttpClient(
				new BrokerHttpClient(
					credentialId: $credentialId,
					logger: $this->logger,
					actingUserId: $this->currentUid()
				)
			)
			->make();

		$config->model = ($openaiConfig['chatModel'] ?? 'gpt-4o-mini');
		if (empty($agentModel) === false) {
			$config->model = $agentModel;
		}

		if ($agentTemperature !== null) {
			$config->modelOptions['temperature'] = $agentTemperature;
		}

		// The agent's own ceiling, wired the same way temperature already was.
		if ($agentMaxTokens !== null) {
			$config->modelOptions['max_tokens'] = $agentMaxTokens;
		}

		$chat = new OpenAIChat($config);

		// `credentialId` is carried on the driver for OpenAI too (previously only
		// fireworks/anthropic did, since they need it for their own direct-HTTP call) —
		// it is otherwise baked opaquely into `$config->client`'s BrokerHttpClient with no
		// public accessor, and this is the only externally-observable proof of WHICH
		// credential a personal/organisation override actually resolved to
		// (agent-credentials). Nothing reads `$driver->credentialId` on the openai path
		// today; this is metadata only, not a behaviour change.
		return new ChatDriver(provider: 'openai', chat: $chat, model: $config->model, credentialId: $credentialId, maxTokens: $agentMaxTokens);
	}//end createOpenAiDriver()

	/**
	 * Build the `fireworks` driver descriptor. No LLPhant chat instance is
	 * created — generation goes through `callFireworksChat()` (direct HTTP);
	 * see the class docblock for why.
	 *
	 * @param array $fireworksConfig The `fireworksConfig` sub-block.
	 * @param string|null $agentModel Agent model override.
	 * @param string|null $credentialOverride Personal/organisation broker credential id
	 *                                        (agent-credentials) that overrides
	 *                                        `$fireworksConfig['credentialId']` when non-empty.
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When the Fireworks API key is not configured.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BrokerHttpClient::isAvailable() is that class's
	 *   own static feature-detection seam for the optional OpenRegister broker — checked
	 *   here so the driver fails loud (503) instead of at request time.
	 *
	 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	private function createFireworksDriver(array $fireworksConfig, ?string $agentModel, ?string $credentialOverride = null): ChatDriver {
		$credentialId = (string)($fireworksConfig['credentialId'] ?? '');
		if (empty($credentialOverride) === false) {
			$credentialId = $credentialOverride;
		}

		if ($credentialId === '') {
			throw new ProviderUnavailableException(
				'Fireworks AI has no credential. Select one from the credential broker in the Hermiq LLM settings.',
				503
			);
		}

		if (BrokerHttpClient::isAvailable() === false) {
			throw new ProviderUnavailableException(
				'Fireworks AI cannot be used: the OpenRegister credential broker is not available.',
				503
			);
		}

		$model = ($fireworksConfig['chatModel'] ?? 'accounts/fireworks/models/llama-v3p1-8b-instruct');
		if (empty($agentModel) === false) {
			$model = $agentModel;
		}

		$baseUrl = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
		if (str_ends_with($baseUrl, '/v1') === false) {
			$baseUrl .= '/v1';
		}

		// `apiKey` carries the CREDENTIAL UUID now, not a key. Only the broker can turn it
		// into a secret, and only server-side. The property keeps its name so the ChatDriver
		// value object and its call sites stay unchanged; callFireworksChat() treats it as
		// what it is.
		return new ChatDriver(
			provider: 'fireworks',
			chat: null,
			model: $model,
			credentialId: $credentialId,
			baseUrl: $baseUrl
		);

	}//end createFireworksDriver()

	/**
	 * Build the `anthropic` driver descriptor. No LLPhant chat instance is created —
	 * generation goes through `callAnthropicChat()` (direct HTTP), the same shape as
	 * the Fireworks driver, because LLPhant's `AnthropicChat` requires a concrete Guzzle
	 * client and exposes no seam for the OAuth header set. The resolved model still flows
	 * through `enforceModelPolicy()` at the `createChatDriver()` chokepoint (unchanged).
	 *
	 * @param array $anthropicConfig The `anthropicConfig` sub-block.
	 * @param string|null $agentModel Agent model override.
	 * @param int|null $agentMaxTokens Agent max-tokens override, applied when set.
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When the credential is missing (503) or the
	 *                                      OpenRegister credential broker is unavailable (503),
	 *                                      mirroring createOpenAiDriver().
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BrokerHttpClient::isAvailable() is that class's
	 *   own static feature-detection seam for the optional OpenRegister broker — checked
	 *   here so the driver fails loud (503) instead of at request time.
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	private function createAnthropicDriver(array $anthropicConfig, ?string $agentModel, ?int $agentMaxTokens = null): ChatDriver {
		$credentialId = (string)($anthropicConfig['credentialId'] ?? '');
		if ($credentialId === '') {
			throw new ProviderUnavailableException(
				'Anthropic has no credential. Select one from the credential broker in the Hermiq LLM settings.',
				503
			);
		}

		if (BrokerHttpClient::isAvailable() === false) {
			throw new ProviderUnavailableException(
				'Anthropic cannot be used: the OpenRegister credential broker is not available.',
				503
			);
		}

		$model = $this->resolveAnthropicModel(
			configuredModel: ($anthropicConfig['chatModel'] ?? 'claude-opus-4-8'),
			agentModel: $agentModel
		);

		$authMode = ($anthropicConfig['authMode'] ?? 'api_key');
		if ($authMode !== 'oauth') {
			$authMode = 'api_key';
		}

		// `executionMode: cli` (llm-cli-runner-exapp) routes the turn through the
		// hermiq-llm-runner ExApp's `claude` CLI instead of the direct Messages API; `http`
		// (the default) is unchanged. Anything other than `cli` normalises to `http`, so an
		// unrecognised value can never select a transport that does not exist.
		$executionMode = ($anthropicConfig['executionMode'] ?? 'http');
		if ($executionMode !== 'cli') {
			$executionMode = 'http';
		}

		$baseUrl = rtrim($anthropicConfig['baseUrl'] ?? 'https://api.anthropic.com/v1', '/');

		// `credentialId` is a broker reference, not a secret — the key or OAuth token lives
		// in the vault, and is either injected server-side by BrokerHttpClient at egress
		// (`http`) or resolved for the CLI's environment at dispatch (`cli`). The token is
		// NEVER carried on this driver: handlers hold this object.
		return new ChatDriver(
			provider: 'anthropic',
			chat: null,
			model: $model,
			credentialId: $credentialId,
			baseUrl: $baseUrl,
			authMode: $authMode,
			executionMode: $executionMode,
			maxTokens: $agentMaxTokens
		);

	}//end createAnthropicDriver()

	/**
	 * Resolve the model for an Anthropic turn, ignoring foreign agent overrides.
	 *
	 * 🔴 An agent's `model` is provider-agnostic free text — most agents on an
	 * instance carry an Ollama tag such as `qwen3.5-optimized:latest`. Applying
	 * that override unconditionally handed it straight to the runner, which ran
	 * `claude -p --model qwen3.5-optimized:latest` and exited 1; the caller saw
	 * only "the runner returned an error while executing the turn" (measured
	 * 2026-07-29 — every governed turn on an Ollama-tagged agent failed this way,
	 * while ungoverned title-generation calls on `claude-opus-4-8` succeeded).
	 *
	 * A foreign override is dropped in favour of the provider's configured model
	 * and said out loud: falling back keeps chat working for agents authored
	 * against a different provider, whereas honouring the override can only ever
	 * produce an opaque exit 1.
	 *
	 * @param string $configuredModel The provider's configured `chatModel`.
	 * @param string|null $agentModel The agent-level override, when set.
	 *
	 * @return string The model id to send to Anthropic.
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	private function resolveAnthropicModel(string $configuredModel, ?string $agentModel): string {
		if (empty($agentModel) === true) {
			return $configuredModel;
		}

		// Anthropic model ids are all `claude-*`; anything else belongs to
		// another provider and cannot be served here.
		if (str_starts_with($agentModel, 'claude-') === true) {
			return $agentModel;
		}

		$this->logger->warning(
			message: '[ProviderFactory] Ignoring the agent\'s model override for an Anthropic turn: it is not '
				. 'an Anthropic model id. Using the provider\'s configured chatModel instead — set the agent\'s '
				. 'model to a claude-* id to control it.',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'agentModel' => $agentModel,
				'configuredModel' => $configuredModel,
			]
		);

		return $configuredModel;
	}//end resolveAnthropicModel()

	/**
	 * The calling user's UID, when there is a session.
	 *
	 * The broker's ownership guard needs an identity to check the credential against. On
	 * the scheduled-agent path there is no session; the credential owner has to be carried
	 * on the run instead.
	 *
	 * @return string|null The UID, or null when there is no session.
	 */
	private function currentUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end currentUid()

	/**
	 * Build the `nextcloud` driver descriptor. No LLPhant chat instance — the
	 * caller must use `generateViaNextcloud()` instead of `$driver->chat`.
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When no TaskProcessing provider is installed.
	 */
	private function createNextcloudDriver(): ChatDriver {
		if ($this->taskManager->hasProviders() === false) {
			throw new ProviderUnavailableException('No Nextcloud Assistant (TaskProcessing) provider is installed.', 503);
		}

		return new ChatDriver(provider: 'nextcloud', chat: null, model: TextToText::ID);
	}//end createNextcloudDriver()
}//end class
