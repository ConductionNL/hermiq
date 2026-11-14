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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-2
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
use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Service\AiFeature\DataUseGate;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\Credential\PinnedCredentialRefusedException;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCP\App\IAppManager;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OpenAI;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
 */
class ProviderFactory implements ProviderFactoryInterface {

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
	 * Why a `cli` turn is refused, by reason: each names what is missing, because an
	 * operator cannot act on a generic "cli unavailable". All are 503s.
	 *
	 * @var array<string, string>
	 */
	private const CLI_REFUSALS = [
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
	 * The personal credential scope — the ONLY scope an `anthropic-cli` (Claude Max/Pro
	 * subscription) credential may carry, per the Anthropic Terms of Service.
	 *
	 * @var string
	 */
	private const CREDENTIAL_SCOPE_PERSONAL = 'personal';

	/**
	 * Tool calls per turn on the Anthropic loop when the caller names no cap: the
	 * default of Agent.maxToolCalls (agents-switch-off-and-stop). The agent's own cap,
	 * passed as `maxToolCalls`, replaces it.
	 *
	 * @var int
	 */
	private const MAX_TOOL_ITERATIONS = 10;

	/**
	 * Tokens the most recent callFireworksChat()/callAnthropicChat() reported, as
	 * `promptTokens` and `completionTokens`; empty when the provider reported none.
	 *
	 * Read by ResponseGenerationHandler right after the call and recorded as the
	 * run's usage, which BudgetService sums against a token budget (hermiq#985).
	 * Reset at the start of every call so one turn never inherits another's count.
	 *
	 * @var array<string, int>
	 */
	private array $lastCallUsage = [];

	/**
	 * Whether the last Anthropic turn stopped at its tool call cap.
	 *
	 * @var boolean
	 */
	private bool $lastCallHitToolCap = false;

	/**
	 * The run tokens of `cli` turns, built on first use.
	 *
	 * @var CliRunTokens|null
	 */
	private ?CliRunTokens $cliRunTokens = null;

	/**
	 * The Anthropic Messages API wire shapes, built on first use.
	 *
	 * @var AnthropicMessageFormat|null
	 */
	private ?AnthropicMessageFormat $anthropicFormat = null;

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
	 * @param OrganisationModelPolicyService|null $modelPolicyService Resolves the calling
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
	 * @param ContainerInterface|null $container The app container AppAPI and the
	 *                                          OpenRegister credential broker are
	 *                                          resolved from, both being optional
	 *                                          sibling apps named by class-string.
	 *                                          Nullable and trailing for the same
	 *                                          backward-compat reason: the unit tests
	 *                                          build this factory positionally with
	 *                                          its first four collaborators.
	 * @param FeatureProviderResolver|null $featureResolver Joins the AiFeature register to
	 *                                                      the ModelPolicy: resolves a
	 *                                                      feature's own provider binding
	 *                                                      and refuses a run whose provider
	 *                                                      runs outside the residency the
	 *                                                      feature requires. Nullable and
	 *                                                      trailing for the same
	 *                                                      backward-compat reason; a null
	 *                                                      resolver leaves every existing
	 *                                                      call site unchanged.
	 * @param DataUseGate|null $dataUseGate The data-use step on the path without an AI
	 *                                      feature (models-no-training-guarantee).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator (several nullable/defaulted for backward-compatible
	 *   test call sites), not a logic-bearing argument list.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 * @spec openspec/changes/llm-cli-runner-exapp/specs/llm-cli-runner-exapp/spec.md#requirement-optional-cli-execution-mode-routes-turns-through-the-runner-exapp
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	public function __construct(
		private readonly LlmSettingsHandler $settingsHandler,
		private readonly IManager $taskManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly string $appName = 'hermiq',
		private readonly ?OrganisationModelPolicyService $modelPolicyService = null,
		private readonly ?IAppManager $appManager = null,
		private readonly ?CredentialScopeResolver $credentialResolver = null,
		private readonly ?RunTokenService $runTokenService = null,
		private readonly ?IURLGenerator $urlGenerator = null,
		private readonly ?IAppConfig $appConfig = null,
		private readonly ?ContainerInterface $container = null,
		private readonly ?FeatureProviderResolver $featureResolver = null,
		private readonly ?DataUseGate $dataUseGate = null,
	) {
	}//end __construct()

	/**
	 * The container AppAPI and the OpenRegister credential broker are resolved from.
	 *
	 * Both are other apps' classes, named by class-string and probed with class_exists,
	 * so neither can be a constructor type: hermiq has to stay constructible, and stay
	 * able to serve `http`, on an instance that has neither. They used to come from
	 * `\OCP\Server::get()`, which outside a booted Nextcloud autowires from scratch and
	 * can recurse through a constructor cycle until memory runs out. The container is
	 * nullable and trailing because eight unit tests build this factory positionally
	 * with its first four collaborators and never reach a cross-app call.
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
	 * The run tokens of `cli` turns and warm-ups.
	 *
	 * @return CliRunTokens
	 */
	private function cliRunTokens(): CliRunTokens {
		if ($this->cliRunTokens === null) {
			$this->cliRunTokens = new CliRunTokens(
				runTokenService: $this->runTokenService,
				urlGenerator: $this->urlGenerator,
				appConfig: $this->appConfig,
				logger: $this->logger,
				pooledTokenTtl: (self::POOL_PROCESS_LIFETIME_SECONDS + self::CLI_DISPATCH_TIMEOUT_SLACK_SECONDS)
			);
		}

		return $this->cliRunTokens;
	}//end cliRunTokens()

	/**
	 * The Anthropic Messages API wire shapes.
	 *
	 * @return AnthropicMessageFormat
	 */
	private function anthropicFormat(): AnthropicMessageFormat {
		if ($this->anthropicFormat === null) {
			$this->anthropicFormat = new AnthropicMessageFormat();
		}

		return $this->anthropicFormat;
	}//end anthropicFormat()

	/**
	 * The tokens the most recent hosted chat call reported.
	 *
	 * @return array<string, int> `promptTokens` and `completionTokens`, or `[]` when the
	 *                            provider reported no usage.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-7-the-ensemble-turn-and-its-budget
	 */
	public function lastCallUsage(): array {
		return $this->lastCallUsage;
	}//end lastCallUsage()

	/**
	 * Whether the last callAnthropicChat() stopped because the turn used every tool
	 * call it may make (agents-switch-off-and-stop), so the caller can record it on
	 * the run trace.
	 *
	 * @return bool True when the tool call cap ended the turn.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function lastCallHitToolCap(): bool {
		return $this->lastCallHitToolCap;
	}//end lastCallHitToolCap()

	/**
	 * Add one request's usage to a running total.
	 *
	 * @param array<string, int> $total The running total.
	 * @param mixed $promptTokens This request's prompt (input) tokens.
	 * @param mixed $completionTokens This request's completion (output) tokens.
	 *
	 * @return array<string, int> The new total; `[]` while nothing has been counted.
	 */
	private function addUsage(array $total, mixed $promptTokens, mixed $completionTokens): array {
		$prompt = ((int)($total['promptTokens'] ?? 0) + (int)$promptTokens);
		$completion = ((int)($total['completionTokens'] ?? 0) + (int)$completionTokens);
		if (($prompt + $completion) === 0) {
			return [];
		}

		return ['promptTokens' => $prompt, 'completionTokens' => $completion];
	}//end addUsage()

	/**
	 * Read the current `hermiq.llm` configuration.
	 *
	 * @return array LLM configuration (see LlmSettingsHandler::getLLMSettingsOnly()).
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
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
	 * @param string|null $aiFeature The slug of the registered AI feature this run belongs
	 *                               to, when the run names one. It selects the feature's
	 *                               own provider binding and the residency that feature
	 *                               requires (a-provider-and-a-place-per-ai-feature). null
	 *                               leaves every pre-existing call site unchanged.
	 * @param string|null $documentReference The document this run was handed, when it was
	 *                               handed one. A feature declaring `requiresRedaction`
	 *                               is refused unless filinq has redacted it; a run with
	 *                               no document reference passes that gate untouched.
	 *
	 * @return ChatDriver The resolved driver.
	 *
	 * @throws ProviderUnavailableException When no provider is configured, the selected
	 *                                      provider is missing required credentials, or
	 *                                      the provider identifier is not recognised.
	 * @throws ModelPolicyViolationException When `$organisation` is given and the resolved
	 *                                       (provider, model) pair falls outside its
	 *                                       effective ModelPolicy.
	 * @throws \OCA\Hermiq\Service\AiFeature\ResidencyViolationException When `$aiFeature`
	 *                                       names a feature that requires a residency the
	 *                                       resolved provider does not carry.
	 * @throws \OCA\Hermiq\Service\AiFeature\RedactionRequiredException When the feature
	 *                                       reads no unredacted document and the one it was
	 *                                       handed has no recorded redaction.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-2
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 */
	/**
	 * Build the driver for one named provider.
	 *
	 * Extracted from createChatDriver() verbatim: every arm, and the default
	 * that refuses an unknown name, are the same. It was split out because the
	 * five arms plus the default carried createChatDriver() over the cyclomatic
	 * complexity gate, and the provider CHOICE is a separable question from the
	 * feature binding above it and the model policy below it.
	 *
	 * @param ChatDriverRequest $request The resolution: the provider name (already resolved and
	 *                                   non-empty), the instance's LLM configuration, the agent's
	 *                                   overrides, the organisation and the pinned credentials.
	 *
	 * @return ChatDriver The driver for that provider.
	 *
	 * @throws ProviderUnavailableException When the provider name is not one this factory builds.
	 *
	 * @spec exclude extracted verbatim from createChatDriver(); covered by its tests
	 */
	private function instantiateChatDriver(ChatDriverRequest $request): ChatDriver {
		$llmConfig = $request->llmConfig;
		return match ((string)$request->chatProvider) {
			'ollama' => $this->createOllamaDriver(ollamaConfig: $llmConfig['ollamaConfig'] ?? [], request: $request),
			'openai' => $this->createOpenAiDriver(openaiConfig: $llmConfig['openaiConfig'] ?? [], request: $request),
			'fireworks' => $this->createFireworksDriver(fireworksConfig: $llmConfig['fireworksConfig'] ?? [], request: $request),
			'anthropic' => $this->createAnthropicDriver(anthropicConfig: $llmConfig['anthropicConfig'] ?? [], request: $request),
			'nextcloud' => $this->createNextcloudDriver(),
			default => throw new ProviderUnavailableException("Unsupported chat provider: {$request->chatProvider}"),
		};// End match.
	}//end instantiateChatDriver()

	/**
	 * Resolve an AI feature's own provider and model binding, if it has one.
	 *
	 * Step 1 of the order `a-provider-and-a-place-per-ai-feature` specifies. It
	 * only RESOLVES: the model policy ceiling is applied by the caller, after
	 * this, so a binding can narrow the policy and never widen it.
	 *
	 * Extracted from createChatDriver() unchanged. `featureBound` is true
	 * whenever a feature and an organisation were both given and a resolver
	 * exists, INCLUDING when the resolver answered with nothing — that is the
	 * existing behaviour and the caller depends on it.
	 *
	 * @param ChatDriverRequest $request The resolution: its feature slug and organisation, and
	 *                                   the provider and model resolved so far, which a binding
	 *                                   replaces.
	 *
	 * @return void
	 *
	 * @spec exclude extracted verbatim from createChatDriver(); covered by its tests
	 */
	private function resolveFeatureBinding(ChatDriverRequest $request): void {
		if ($request->aiFeature === null || $request->aiFeature === '' || $this->featureResolver === null || $request->organisation === null) {
			return;
		}

		$request->featureBound = true;
		$binding = $this->featureResolver->bindingFor(featureSlug: $request->aiFeature, organisation: $request->organisation);
		if ($binding['provider'] !== null && $binding['model'] !== null) {
			$request->chatProvider = $binding['provider'];
			$request->agentModel = $binding['model'];
		}
	}//end resolveFeatureBinding()

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
	 * @param string|null $aiFeature The slug of the registered AI feature this run belongs
	 *                               to, when the run names one. It selects the feature's
	 *                               own provider binding and the residency that feature
	 *                               requires (a-provider-and-a-place-per-ai-feature). null
	 *                               leaves every pre-existing call site unchanged.
	 * @param string|null $documentReference The document this run was handed, when it was
	 *                               handed one. A feature declaring `requiresRedaction`
	 *                               is refused unless filinq has redacted it; a run with
	 *                               no document reference passes that gate untouched.
	 * @param array<string, string>|null $agentCredentialIds The agent's own credential per
	 *                               provider (`credentialIds`). A pin for the resolved
	 *                               provider is used first and never bypassed.
	 * @param array<int, string> $attachmentReferences The file ids attached to this turn,
	 *                               each checked by the feature's gates on its own.
	 *
	 * @return ChatDriver The resolved driver.
	 *
	 * @throws ProviderUnavailableException When no provider is configured, the selected
	 *                                      provider is missing required credentials, or
	 *                                      the provider identifier is not recognised.
	 * @throws ModelPolicyViolationException When `$organisation` is given and the resolved
	 *                                       (provider, model) pair falls outside its
	 *                                       effective ModelPolicy.
	 * @throws \OCA\Hermiq\Service\AiFeature\ResidencyViolationException When `$aiFeature`
	 *                                       names a feature that requires a residency the
	 *                                       resolved provider does not carry.
	 * @throws \OCA\Hermiq\Service\AiFeature\RedactionRequiredException When the feature
	 *                                       reads no unredacted document and the one it was
	 *                                       handed has no recorded redaction.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-2
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function createChatDriver(
		array $llmConfig,
		?string $agentModel = null,
		?float $agentTemperature = null,
		?string $organisation = null,
		?int $agentMaxTokens = null,
		?string $aiFeature = null,
		?string $documentReference = null,
		?array $agentCredentialIds = null,
		array $attachmentReferences = [],
	): ChatDriver {
		$request = new ChatDriverRequest(llmConfig: $llmConfig, organisation: $organisation);
		$request->agentModel = $agentModel;
		$request->agentTemperature = $agentTemperature;
		$request->agentMaxTokens = $agentMaxTokens;
		$request->aiFeature = $aiFeature;
		$request->documentReference = $documentReference;
		$request->agentCredentialIds = $agentCredentialIds;
		$request->attachmentReferences = $attachmentReferences;

		return $this->resolveChatDriver(request: $request);
	}//end createChatDriver()

	/**
	 * Resolve the driver in the specified order: the feature's own binding, the
	 * provider's driver, then the gates.
	 *
	 * Spec a-provider-and-a-place-per-ai-feature, step 1 of the specified order:
	 * resolve the feature's own binding. It only resolves; the ceiling is applied
	 * after the driver is built, so a binding can narrow the policy and never widen it.
	 *
	 * @param ChatDriverRequest $request The resolution.
	 *
	 * @return ChatDriver The resolved driver.
	 *
	 * @throws ProviderUnavailableException When no provider is configured or it cannot be built.
	 */
	private function resolveChatDriver(ChatDriverRequest $request): ChatDriver {
		$this->resolveFeatureBinding(request: $request);

		if (empty($request->chatProvider) === true) {
			throw new ProviderUnavailableException(
				'Chat provider is not configured. Please configure OpenAI, Anthropic, Fireworks AI, Ollama, or Nextcloud Assistant in settings.',
				503
			);
		}

		$driver = $this->instantiateChatDriver(request: $request);
		$this->enforceDriverGates(request: $request, driver: $driver);
		return $driver;
	}//end resolveChatDriver()

	/**
	 * Tenant-model-policy: the single enforcement chokepoint, and steps 2 and 3 of
	 * this change's specified order. Runs AFTER the agent override is applied (the
	 * driver builders already resolved agentModel ?? providerConfig into
	 * $driver->model) so a policy cannot be bypassed by leaving the per-agent model
	 * field blank — see design.md "Decisions". Runs BEFORE any network call: driver
	 * construction only builds client value objects, it never sends a request.
	 *
	 * When the run names an AI feature the resolver applies both gates in order and
	 * names the one that refuses; with no feature named the model-policy gate alone
	 * runs, exactly as before, followed by the data-use step (models-no-training-
	 * guarantee), so a run that names no AI feature cannot reach a provider that
	 * may train.
	 *
	 * @param ChatDriverRequest $request The resolution.
	 * @param ChatDriver        $driver  The built driver.
	 *
	 * @return void
	 */
	private function enforceDriverGates(ChatDriverRequest $request, ChatDriver $driver): void {
		if ($request->featureBound === true && $this->featureResolver !== null && $request->organisation !== null) {
			$this->enforceFeatureGates(request: $request, driver: $driver);
			return;
		}

		$this->enforceModelPolicy(organisation: $request->organisation, provider: $driver->provider, model: $driver->model);

		if ($request->organisation !== null) {
			$this->dataUseGate?->enforce(organisation: $request->organisation, provider: $driver->provider);
		}
	}//end enforceDriverGates()

	/**
	 * Apply the feature's gates to the run's document and then, one call per file,
	 * to every attachment, so a redacted first file cannot carry an unredacted
	 * second one past the gate (chat-attachments-and-images).
	 *
	 * @param ChatDriverRequest $request The resolution (feature-bound, with an organisation).
	 * @param ChatDriver        $driver  The built driver.
	 *
	 * @return void
	 */
	private function enforceFeatureGates(ChatDriverRequest $request, ChatDriver $driver): void {
		$this->featureResolver?->enforceForRun(
			featureSlug: (string)$request->aiFeature,
			organisation: (string)$request->organisation,
			provider: $driver->provider,
			model: $driver->model,
			documentReference: $request->documentReference
		);

		foreach ($request->attachmentReferences as $attachmentReference) {
			$this->featureResolver?->enforceForRun(
				featureSlug: (string)$request->aiFeature,
				organisation: (string)$request->organisation,
				provider: $driver->provider,
				model: $driver->model,
				documentReference: (string)$attachmentReference
			);
		}
	}//end enforceFeatureGates()

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
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
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
				"Refused by the model-policy check: organisation '%s' does not permit provider '%s' model '%s'.",
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
	 * @param array<string, string>|null $pinned The agent's pinned credential per provider.
	 *                     A pin for `$provider` is resolved even without an organisation,
	 *                     and a pin nothing can check is refused, never ignored.
	 *
	 * @return string|null The overriding credential uuid, or null to keep the configured
	 *                     `hermiq.llm.<provider>Config.credentialId` unchanged.
	 *
	 * @throws PinnedCredentialRefusedException When the pin for `$provider` cannot be used.
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
	 */
	private function resolveCredentialOverride(string $provider, ?string $organisation, ?array $pinned = null): ?string {
		$hasPin = is_string($pinned[$provider] ?? null) === true && trim($pinned[$provider]) !== '';
		if ($this->credentialResolver === null) {
			if ($hasPin === true) {
				throw new PinnedCredentialRefusedException(provider: $provider);
			}

			return null;
		}

		if ($organisation === null && $hasPin === false) {
			return null;
		}

		return $this->credentialResolver->resolve(
			provider: $provider,
			actingUserId: $this->currentUid(),
			organisation: $organisation,
			pinned: $pinned
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
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-2
	 */
	public function callFireworksChat(
		string $credentialId,
		string $model,
		string $baseUrl,
		array $messageHistory,
		array $functions = [],
	): string {
		$this->lastCallUsage = [];
		$url = rtrim($baseUrl, '/') . '/chat/completions';
		$this->warnIgnoredFireworksTools(functions: $functions);

		$payload = [
			'model' => $model,
			'messages' => $this->mapHistoryToFireworksMessages(messageHistory: $messageHistory),
		];
		$psrResponse = $this->sendToFireworks(
			credentialId: $credentialId,
			request: new Request('POST', $url, ['Content-Type' => 'application/json'], (string)json_encode($payload))
		);

		$httpCode = $psrResponse->getStatusCode();
		$response = (string)$psrResponse->getBody();
		if ($httpCode !== 200) {
			$error = ($this->fireworksStatusError(httpCode: $httpCode, model: $model)
				?? new Exception("Fireworks API error (HTTP {$httpCode}): " . $this->fireworksErrorMessage(response: $response)));
			throw ProviderCallFailedException::fromHttpStatus(
				status: $httpCode,
				message: $error->getMessage(),
				retryAfter: $psrResponse->getHeaderLine('retry-after')
			);
		}

		return $this->fireworksAnswer(response: $response);
	}//end callFireworksChat()

	/**
	 * Function calling is not supported for Fireworks AI: say so when a turn
	 * brings tools, which are then ignored.
	 *
	 * @param array<int, mixed> $functions The turn's function definitions.
	 *
	 * @return void
	 */
	private function warnIgnoredFireworksTools(array $functions): void {
		if (empty($functions) === true) {
			return;
		}

		$this->logger->warning(
			message: '[ProviderFactory] Function calling not yet supported for Fireworks AI. Tools will be ignored.',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'functionCount' => count($functions),
			]
		);
	}//end warnIgnoredFireworksTools()

	/**
	 * Send one chat completions request. Through the broker, not straight out:
	 * Hermiq has no Fireworks key to send — the broker holds it, checks the
	 * allow-rules, and injects the Authorization header server-side.
	 *
	 * @param string  $credentialId Broker credential UUID.
	 * @param Request $request      The request.
	 *
	 * @return ResponseInterface
	 *
	 * @throws \Exception If the request cannot be sent.
	 */
	private function sendToFireworks(string $credentialId, Request $request): ResponseInterface {
		$client = new BrokerHttpClient(
			credentialId: $credentialId,
			logger: $this->logger,
			actingUserId: $this->currentUid(),
			container: $this->container
		);

		try {
			return $client->sendRequest($request);
		} catch (Throwable $e) {
			throw self::transportFailure(prefix: 'Fireworks API request failed: ', failure: $e);
		}
	}//end sendToFireworks()

	/**
	 * The failure of a refused key, an unknown model or a rate limit, or null for
	 * any other status.
	 *
	 * @param int    $httpCode The response status.
	 * @param string $model    Model id (for the message only).
	 *
	 * @return Exception|null
	 */
	private function fireworksStatusError(int $httpCode, string $model): ?Exception {
		if ($httpCode === 401 || $httpCode === 403) {
			return new Exception('Authentication failed. Please check your Fireworks API key.');
		}

		if ($httpCode === 404) {
			return new Exception("Model not found: {$model}. Please check the model name.");
		}

		if ($httpCode === 429) {
			return new Exception('Rate limit exceeded. Please try again later.');
		}

		return null;
	}//end fireworksStatusError()

	/**
	 * The error message a Fireworks error body carries, or the raw body.
	 *
	 * @param string $response The raw response body.
	 *
	 * @return string
	 */
	private function fireworksErrorMessage(string $response): string {
		$errorData = json_decode($response, true);
		return (string)($errorData['error']['message'] ?? $errorData['error'] ?? $response);
	}//end fireworksErrorMessage()

	/**
	 * The answer text of a chat completions response, keeping its `usage` so a
	 * token budget counts this run.
	 *
	 * @param string $response The raw response body.
	 *
	 * @return string
	 *
	 * @throws \Exception When the response carries no answer.
	 */
	private function fireworksAnswer(string $response): string {
		$data = json_decode($response, true);
		if (isset($data['choices'][0]['message']['content']) === false) {
			throw new Exception('Unexpected Fireworks API response format: ' . $response);
		}

		$usage = ($data['usage'] ?? []);
		if (is_array($usage) === true) {
			$this->lastCallUsage = $this->addUsage(
				total: [],
				promptTokens: ($usage['prompt_tokens'] ?? 0),
				completionTokens: ($usage['completion_tokens'] ?? 0)
			);
		}

		return $data['choices'][0]['message']['content'];
	}//end fireworksAnswer()

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
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
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
	 * @param int|null $maxToolCalls The tool calls this turn may make (Agent.maxToolCalls);
	 *                               null is the default of 10. Past it no tool runs and
	 *                               the turn ends with the model's last answer.
	 *
	 * @return string Generated response text (the final assistant turn).
	 *
	 * @throws \Exception If the HTTP call fails or the response is malformed.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors callFireworksChat's direct-HTTP signature
	 *
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
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
		?int $maxToolCalls = null,
	): string {
		$this->resetCallState();
		$run = new AnthropicChatRun(credentialId: $credentialId, model: $model);
		$run->baseUrl = $baseUrl;
		$run->messageHistory = $messageHistory;
		$run->authMode = $authMode;
		$run->maxTokens = $maxTokens;
		$run->functions = $functions;
		$run->toolExecutor = $toolExecutor;
		$run->agentId = $agentId;
		$run->conversationId = $conversationId;
		$run->maxToolCalls = $maxToolCalls;

		// `cli` routes the turn through the hermiq-llm-runner ExApp instead of the direct
		// Messages API. Branch BEFORE any HTTP assembly: the two transports share nothing
		// below this point, and `http` must stay bit-for-bit unaffected.
		if ($executionMode === 'cli') {
			return $this->callAnthropicCliTurn(run: $run);
		}

		return $this->callAnthropicHttp(run: $run);
	}//end callAnthropicChat()

	/**
	 * Clear what the previous call recorded: its usage and whether it hit the
	 * tool call cap.
	 *
	 * @return void
	 */
	private function resetCallState(): void {
		$this->lastCallUsage = [];
		$this->lastCallHitToolCap = false;
	}//end resetCallState()

	/**
	 * Hand the turn to the `cli` transport, {@see callAnthropicCli()}.
	 *
	 * @param AnthropicChatRun $run The turn.
	 *
	 * @return string The completion text.
	 */
	private function callAnthropicCliTurn(AnthropicChatRun $run): string {
		return $this->callAnthropicCli(
			credentialId: $run->credentialId,
			model: $run->model,
			messageHistory: $run->messageHistory,
			functions: $run->functions,
			agentId: $run->agentId,
			conversationId: $run->conversationId
		);
	}//end callAnthropicCliTurn()

	/**
	 * Run one Anthropic turn on the direct Messages API: at most one request per
	 * allowed tool call plus the closing one.
	 *
	 * Anthropic keeps the system prompt OUT of `messages`; system turns are hoisted
	 * into the top-level `system` field and user/assistant turns mapped through.
	 *
	 * @param AnthropicChatRun $run The turn.
	 *
	 * @return string Generated response text (the final assistant turn).
	 *
	 * @throws \Exception If the HTTP call fails or the response is malformed.
	 */
	private function callAnthropicHttp(AnthropicChatRun $run): string {
		$loop = new AnthropicToolLoop(run: $run, defaultCap: self::MAX_TOOL_ITERATIONS);
		$mapped = $this->anthropicFormat()->mapHistoryToAnthropicMessages(messageHistory: $run->messageHistory);
		$loop->system = $mapped['system'];
		$loop->messages = $mapped['messages'];
		$loop->tools = $this->offeredAnthropicTools(run: $run);
		$loop->headers = $this->buildAnthropicHeaders(authMode: $run->authMode);

		for ($iteration = 0; $iteration <= $loop->cap; $iteration++) {
			if ($this->anthropicRound(loop: $loop) === false) {
				break;
			}
		}

		if ($this->lastCallHitToolCap === true && trim($loop->text) === '') {
			return 'Tool call limit reached for this turn.';
		}

		return $loop->text;
	}//end callAnthropicHttp()

	/**
	 * The tools offered to the model. Tools are offered ONLY when both a schema AND
	 * an executor are present — the model must never be told about a tool Hermiq
	 * cannot then run for it.
	 *
	 * @param AnthropicChatRun $run The turn.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function offeredAnthropicTools(AnthropicChatRun $run): array {
		if (empty($run->functions) === true) {
			return [];
		}

		if ($run->toolExecutor !== null) {
			return $this->anthropicFormat()->buildAnthropicTools(functions: $run->functions);
		}

		$this->logger->warning(
			message: '[ProviderFactory] Anthropic turn has tools but no executor; running text-only.',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'functionCount' => count($run->functions),
			]
		);
		return [];
	}//end offeredAnthropicTools()

	/**
	 * One request of the tool loop, and the tool calls it asks for.
	 *
	 * @param AnthropicToolLoop $loop The loop.
	 *
	 * @return bool True when the loop needs another request.
	 */
	private function anthropicRound(AnthropicToolLoop $loop): bool {
		$parsed = $this->anthropicFormat()->parseAnthropicResponse(
			data: $this->postToAnthropic(loop: $loop, payload: $this->anthropicPayload(loop: $loop))
		);
		$loop->text = $parsed['text'];

		// Every request of the tool loop is billed, so every request counts.
		$this->lastCallUsage = $this->addUsage(
			total: $this->lastCallUsage,
			promptTokens: ($parsed['usage']['promptTokens'] ?? 0),
			completionTokens: ($parsed['usage']['completionTokens'] ?? 0)
		);

		if ($this->anthropicTurnComplete(loop: $loop, parsed: $parsed) === true) {
			return false;
		}

		$this->runAnthropicToolCalls(loop: $loop, parsed: $parsed);
		return true;
	}//end anthropicRound()

	/**
	 * Whether the turn is over after a response: no tool call requested (or no
	 * executor to run one), or the turn used every tool call it may make — then no
	 * tool runs and the model's last answer stands (agents-switch-off-and-stop).
	 *
	 * @param AnthropicToolLoop    $loop   The loop.
	 * @param array<string, mixed> $parsed The parsed response.
	 *
	 * @return bool
	 */
	private function anthropicTurnComplete(AnthropicToolLoop $loop, array $parsed): bool {
		if ($loop->tools === [] || $loop->run->toolExecutor === null || $parsed['stopReason'] !== 'tool_use' || $parsed['toolCalls'] === []) {
			return true;
		}

		if ($loop->executed >= $loop->cap) {
			$this->lastCallHitToolCap = true;
			return true;
		}

		return false;
	}//end anthropicTurnComplete()

	/**
	 * The payload of the loop's next request.
	 *
	 * @param AnthropicToolLoop $loop The loop.
	 *
	 * @return array<string, mixed>
	 */
	private function anthropicPayload(AnthropicToolLoop $loop): array {
		$payload = [
			'model' => $loop->run->model,
			'max_tokens' => ($loop->run->maxTokens ?? 4096),
			'messages' => $loop->messages,
		];

		if ($loop->system !== '') {
			$payload['system'] = $loop->system;
		}

		if (empty($loop->tools) === false) {
			$payload['tools'] = $loop->tools;
		}

		return $payload;
	}//end anthropicPayload()

	/**
	 * Echo the assistant's tool_use turn back verbatim, run each requested tool
	 * through Hermiq's governed engine (the executor), and feed the results back.
	 *
	 * @param AnthropicToolLoop    $loop   The loop.
	 * @param array<string, mixed> $parsed The parsed response asking for tools.
	 *
	 * @return void
	 */
	private function runAnthropicToolCalls(AnthropicToolLoop $loop, array $parsed): void {
		$loop->messages[] = [
			'role' => 'assistant',
			'content' => $parsed['content'],
		];
		$toolResults = [];
		foreach ($parsed['toolCalls'] as $toolCall) {
			$toolResults[] = [
				'tool_use_id' => $toolCall['id'],
				'content' => $this->anthropicToolResult(loop: $loop, toolCall: $toolCall),
			];
		}

		$loop->messages[] = [
			'role' => 'user',
			'content' => $this->anthropicFormat()->buildAnthropicToolResultBlocks(toolResults: $toolResults),
		];
	}//end runAnthropicToolCalls()

	/**
	 * Run one requested tool, unless the turn has used every tool call it may make.
	 *
	 * @param AnthropicToolLoop    $loop     The loop.
	 * @param array<string, mixed> $toolCall The requested call (`id`, `name`, `input`).
	 *
	 * @return string The tool result.
	 */
	private function anthropicToolResult(AnthropicToolLoop $loop, array $toolCall): string {
		if ($loop->executed >= $loop->cap) {
			return '{"isError":true,"result":{"error":"Tool call limit reached for this turn"}}';
		}

		$result = (string)($loop->run->toolExecutor)($toolCall['name'], $toolCall['input']);
		$loop->executed++;
		return $result;
	}//end anthropicToolResult()

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
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
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
		$turn = new CliTurn(model: $model, agentId: $agentId);
		$turn->credentialId = $credentialId;
		$turn->messageHistory = $messageHistory;
		$turn->functions = $functions;
		$turn->conversationId = $conversationId;
		return $this->answerCliTurn(turn: $turn);
	}//end callAnthropicCli()

	/**
	 * Serve one `cli` turn: the pool key, the runner check, the turn's run token
	 * (and MCP config), the subscription token, the dispatch.
	 *
	 * @param CliTurn $turn The turn.
	 *
	 * @return string The completion text.
	 *
	 * @throws ProviderUnavailableException When the runner, governance, the credential or the dispatch fails (503).
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
	 */
	private function answerCliTurn(CliTurn $turn): string {
		// A turn is POOLABLE only when it belongs to an identified conversation.
		// Without one there is nothing safe to key a remembering process by, so the
		// turn takes the cold path — the behaviour that shipped before pooling.
		$turn->poolKey = $this->poolKeyFor(conversationId: $turn->conversationId, agentId: $turn->agentId, model: $turn->model);

		// 1. Availability — each component named separately, before any secret is touched.
		$this->assertCliRunnerAvailable();

		$turn->uid = $this->currentUid();

		try {
			// 2. The turn's run token (and, for a governed turn, its MCP config).
			$this->cliRunTokens()->mintTurnTokens(turn: $turn);

			// 3. Credential (subscription token). Local variable only: never stored on the
			// ChatDriver (handlers hold that object), never logged, never in an exception
			// message, never in a trace.
			$token = $this->resolveCliToken(credentialId: $turn->credentialId, uid: $turn->uid);

			// 4+5. Dispatch and map.
			return $this->dispatchCliTurn(turn: $turn, token: $token);
		} finally {
			$this->cliRunTokens()->consumeColdRunToken(turn: $turn);
		}
	}//end answerCliTurn()

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
	public function assertCliRunnerAvailable(): void {
		$gap = ($this->appApiGap() ?? $this->runnerGap());
		if ($gap !== null) {
			throw new ProviderUnavailableException(self::CLI_REFUSALS[$gap], 503);
		}

	}//end assertCliRunnerAvailable()

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
	private function resolveCliToken(string $credentialId, ?string $uid): string {
		$this->assertPersonalScopeCredential(credentialId: $credentialId);

		if (class_exists(BrokerHttpClient::BROKER_CLASS) === false) {
			throw new ProviderUnavailableException(self::CLI_REFUSALS['no_broker'], 503);
		}

		$token = $this->brokerToken(credentialId: $credentialId, uid: $uid);
		if ($token === null || $token === '') {
			throw new ProviderUnavailableException(self::CLI_REFUSALS['not_injectable'], 503);
		}

		return $token;
	}//end resolveCliToken()

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

			throw new ProviderUnavailableException(self::CLI_REFUSALS['broker_refused'], 503);
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
		$turn = new CliTurn(model: $model, agentId: $agentId);
		$turn->credentialId = $credentialId;
		$turn->conversationId = $conversationId;
		$turn->governed = $governed;
		return $this->warmCliProcess(turn: $turn);

	}//end warmAnthropicCli()

	/**
	 * Start the pooled process a conversation's first turn will use, best-effort.
	 *
	 * @param CliTurn $turn The warm-up.
	 *
	 * @return bool True when a warm-up was dispatched.
	 */
	private function warmCliProcess(CliTurn $turn): bool {
		$turn->poolKey = $this->poolKeyFor(conversationId: $turn->conversationId, agentId: $turn->agentId, model: $turn->model);
		if ($turn->poolKey === '') {
			return false;
		}

		try {
			$this->assertCliRunnerAvailable();
			$turn->uid = $this->currentUid();

			// The warmed process must be built EXACTLY as the first turn will
			// build it — same governed argv, same token, same mcp config — or it
			// is a process that turn cannot use and the warm-up is worse than
			// useless: it occupies a pool slot and saves nothing.
			$this->cliRunTokens()->mintWarmupToken(turn: $turn);
			$token = $this->resolveCliToken(credentialId: $turn->credentialId, uid: $turn->uid);

			$this->appApiPublicFunctions()->exAppRequest(
				self::RUNNER_EXAPP_ID,
				self::RUNNER_ROUTE,
				$turn->uid,
				'POST',
				$this->warmupParams(turn: $turn, token: $token),
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
	}//end warmCliProcess()

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
			'poolLifetimeSeconds' => self::POOL_PROCESS_LIFETIME_SECONDS,
			'warmOnly' => true,
		];
	}//end warmupParams()

	/**
	 * POST one turn to the `hermiq-llm-runner` ExApp and return its completion.
	 *
	 * The single place that speaks to the runner, so the request shape — and in
	 * particular the exact `credentialEnv` key the runner's Anthropic adapter
	 * allowlists — is stated once. A key outside that allowlist is dropped
	 * WITHOUT an error, producing an unauthenticated CLI rather than a 400, so
	 * this is not a detail that tolerates a second spelling elsewhere.
	 *
	 * @param CliTurn $turn  The turn: model, history, user, MCP config, run token and pool key.
	 * @param string  $token The subscription token (never logged).
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
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-the-cli-is-locked-to-hermiqs-governance-by-its-invocation-flags
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
	 */
	private function dispatchCliTurn(CliTurn $turn, string $token): string {
		$result = $this->appApiPublicFunctions()->exAppRequest(
			self::RUNNER_EXAPP_ID,
			self::RUNNER_ROUTE,
			$turn->uid,
			'POST',
			$this->dispatchParams(turn: $turn, token: $token),
			$this->cliDispatchOptions()
		);

		$this->assertRunnerAnswered(result: $result);

		// Check 3 — only now is the body the model's answer.
		return $this->mapCliCompletion(body: (string)$result->getBody());
	}//end dispatchCliTurn()

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
			$params['poolLifetimeSeconds'] = self::POOL_PROCESS_LIFETIME_SECONDS;
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
	 * - `usage` is the CLI's own object. Its `input_tokens` and `output_tokens` are kept in
	 *   `lastCallUsage`, the same shape the `http` branch records, so a token budget counts a
	 *   `cli` turn too (hermiq#985).
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

		$usage = ($decoded['usage'] ?? []);
		if (is_array($usage) === true) {
			$this->lastCallUsage = $this->addUsage(
				total: [],
				promptTokens: ($usage['input_tokens'] ?? 0),
				completionTokens: ($usage['output_tokens'] ?? 0)
			);
		}

		return $text;
	}//end mapCliCompletion()

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

	/**
	 * Map LLPhant history onto Fireworks' OpenAI-compatible `messages`.
	 *
	 * Text turns become `{role, content}`. A turn with native parts carries the
	 * OpenAI content parts (text, then each image as an `image_url` data URL).
	 *
	 * @param array $messageHistory Array of LLPhant Message objects.
	 *
	 * @return list<array<string, mixed>> The messages.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function mapHistoryToFireworksMessages(array $messageHistory): array {
		$messages = [];
		foreach ($messageHistory as $msg) {
			if ($msg instanceof AttachmentMessage) {
				$messages[] = ['role' => $msg->role->value, 'content' => $msg->openAiContent()];
				continue;
			}

			$messages[] = [
				'role' => $msg->role->value,
				'content' => $msg->content,
			];
		}

		return $messages;
	}//end mapHistoryToFireworksMessages()

	/**
	 * POST one Anthropic Messages request through the broker and return the decoded body.
	 *
	 * Extracted so the tool loop can issue multiple round-trips without duplicating the
	 * broker wiring or the HTTP error mapping.
	 *
	 * @param AnthropicToolLoop   $loop    The loop: credential, endpoint, headers and model.
	 * @param array<string,mixed> $payload The request payload.
	 *
	 * @return array<string, mixed> The decoded response body.
	 *
	 * @throws \Exception If the HTTP call fails or the response is malformed.
	 *
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-2
	 */
	private function postToAnthropic(AnthropicToolLoop $loop, array $payload): array {
		$psrResponse = $this->sendToAnthropic(loop: $loop, payload: $payload);
		$httpCode = $psrResponse->getStatusCode();
		$response = (string)$psrResponse->getBody();

		if ($httpCode !== 200) {
			$error = ($this->anthropicStatusError(httpCode: $httpCode, model: $loop->run->model)
				?? $this->anthropicRateLimitError(psrResponse: $psrResponse)
				?? new Exception("Anthropic API error (HTTP {$httpCode}): " . $this->anthropicErrorMessage(response: $response)));
			throw ProviderCallFailedException::fromHttpStatus(
				status: $httpCode,
				message: $error->getMessage(),
				retryAfter: $psrResponse->getHeaderLine('retry-after'),
				kind: self::anthropicRefusedCredential(psrResponse: $psrResponse)
			);
		}

		$data = json_decode($response, true);
		if (is_array($data) === false) {
			throw new Exception('Unexpected Anthropic API response format: ' . $response);
		}

		return $data;
	}//end postToAnthropic()

	/**
	 * Send one Messages request. Through the broker, not straight out: Hermiq has no
	 * Anthropic secret to send — the broker holds it, checks the allow-rules, and
	 * injects the auth header server-side.
	 *
	 * @param AnthropicToolLoop   $loop    The loop: credential, endpoint and headers.
	 * @param array<string,mixed> $payload The request payload.
	 *
	 * @return ResponseInterface
	 *
	 * @throws \Exception If the request cannot be sent.
	 */
	private function sendToAnthropic(AnthropicToolLoop $loop, array $payload): ResponseInterface {
		$client = new BrokerHttpClient(
			credentialId: $loop->run->credentialId,
			logger: $this->logger,
			actingUserId: $this->currentUid(),
			container: $this->container
		);

		try {
			return $client->sendRequest(
				new Request(
					'POST',
					$loop->url,
					$loop->headers,
					(string)json_encode($payload)
				)
			);
		} catch (Throwable $e) {
			throw self::transportFailure(prefix: 'Anthropic API request failed: ', failure: $e);
		}
	}//end sendToAnthropic()

	/**
	 * A request that did not get an answer, typed by what went wrong, with the message it always had.
	 *
	 * @param string    $prefix  The provider's message prefix.
	 * @param Throwable $failure The client's failure.
	 *
	 * @return ProviderCallFailedException
	 *
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-2
	 */
	private static function transportFailure(string $prefix, Throwable $failure): ProviderCallFailedException {
		$typed = ProviderCallFailedException::fromThrowable(failure: $failure);

		return new ProviderCallFailedException(
			message: $prefix . $failure->getMessage(),
			kind: $typed->kind,
			httpStatus: $typed->httpStatus,
			previous: $failure
		);
	}//end transportFailure()

	/**
	 * An Anthropic 429 without retry-after or rate-limit counters is a credential the Messages API refuses:
	 * a configuration error (auth), never a rate limit a fallback should hide.
	 *
	 * @param ResponseInterface $psrResponse The answer.
	 *
	 * @return string|null The auth kind for that case, else null (the status decides).
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003
	 */
	private static function anthropicRefusedCredential(ResponseInterface $psrResponse): ?string {
		if ($psrResponse->getStatusCode() !== 429
			|| trim($psrResponse->getHeaderLine('retry-after')) !== ''
			|| $psrResponse->hasHeader('anthropic-ratelimit-requests-remaining') === true
			|| $psrResponse->hasHeader('anthropic-ratelimit-tokens-remaining') === true
		) {
			return null;
		}

		return ProviderCallFailedException::AUTH;
	}//end anthropicRefusedCredential()

	/**
	 * The failure of a refused credential or an unknown model, or null for any
	 * other status.
	 *
	 * @param int    $httpCode The response status.
	 * @param string $model    Model id (for the message only).
	 *
	 * @return Exception|null
	 */
	private function anthropicStatusError(int $httpCode, string $model): ?Exception {
		if ($httpCode === 401 || $httpCode === 403) {
			return new Exception('Authentication failed. Please check your Anthropic credential.');
		}

		if ($httpCode === 404) {
			return new Exception("Model not found: {$model}. Please check the model name.");
		}

		return null;
	}//end anthropicStatusError()

	/**
	 * The failure of a 429, or null for any other status.
	 *
	 * `retry-after` only reaches us because BrokerHttpClient forwards the provider's
	 * response headers; without it a 429 is opaque. No retry-after and no
	 * `anthropic-ratelimit-*` counters means Anthropic is refusing this credential
	 * for this endpoint rather than reporting a usage window — the signature of a
	 * subscription (Claude Max) OAuth token, which is not entitled to the direct
	 * Messages API. Say so instead of implying that waiting will help.
	 *
	 * @param ResponseInterface $psrResponse The response.
	 *
	 * @return Exception|null
	 */
	private function anthropicRateLimitError(ResponseInterface $psrResponse): ?Exception {
		if ($psrResponse->getStatusCode() !== 429) {
			return null;
		}

		$retryAfter = trim($psrResponse->getHeaderLine('retry-after'));
		if ($retryAfter !== '') {
			return new Exception('Rate limit exceeded. Please try again in ' . $retryAfter . ' seconds.');
		}

		if ($psrResponse->hasHeader('anthropic-ratelimit-requests-remaining') === false
			&& $psrResponse->hasHeader('anthropic-ratelimit-tokens-remaining') === false
		) {
			return new Exception(
				'Anthropic refused this credential for the Messages API (rate_limit_error with no '
				. 'rate-limit counters). A Claude Max/Pro subscription OAuth token is not entitled to '
				. 'the direct API — use an Anthropic API key, or run the subscription through the '
				. 'hermiq-llm-runner ExApp (executionMode: cli), which uses the official Claude CLI.'
			);
		}

		return new Exception('Rate limit exceeded. Please try again later.');
	}//end anthropicRateLimitError()

	/**
	 * The error message an Anthropic error body carries, the raw body when it
	 * carries none, or 'Unknown error' when it is not a string.
	 *
	 * @param string $response The raw response body.
	 *
	 * @return string
	 */
	private function anthropicErrorMessage(string $response): string {
		$errorData = json_decode($response, true);
		if (is_array($errorData) === false) {
			$errorData = [];
		}

		$errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? $response;
		if (is_string($errorMessage) === false) {
			return 'Unknown error';
		}

		return $errorMessage;
	}//end anthropicErrorMessage()

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
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-2
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
	 * @param string|null $aiFeature The AI feature this generation belongs to, when it
	 *                               names one. Its binding, residency and redaction
	 *                               gates run before any request (createChatDriver()).
	 * @param string|null $documentReference The document the generation reads, when it
	 *                               reads one, so the feature's redaction gate is
	 *                               applied to that document.
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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$allowNextcloud` is a genuine
	 * two-mode recursion guard (background-work caller allows the TaskProcessing
	 * driver; a TaskProcessing provider-backed caller forbids it to avoid recursing),
	 * not a responsibility split — both modes share the identical generation path.
	 * Mirrors the accepted `ScheduleService::runNow($bypassApprovalGate)` precedent.
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
	 * @spec openspec/changes/archive/2026-07-14-agent-evals/tasks.md#task-4-providerfactorygeneratetext-optional-organisation-param
	 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
	 */
	public function generateText(
		string $prompt,
		?string $userId = null,
		bool $allowNextcloud = true,
		?string $organisation = null,
		?string $aiFeature = null,
		?string $documentReference = null,
	): string {
		$llmConfig = $this->getLlmConfig();
		$driver = $this->createChatDriver(
			llmConfig: $llmConfig,
			organisation: $organisation,
			aiFeature: $aiFeature,
			documentReference: $documentReference
		);

		if ($driver->provider === 'nextcloud') {
			$this->assertNextcloudMayGenerate(allowNextcloud: $allowNextcloud);
			return $this->generateViaNextcloud(prompt: $prompt, userId: $userId);
		}

		return $this->generateWithDriver(driver: $driver, prompt: $prompt);
	}//end generateText()

	/**
	 * Refuse the `nextcloud` chat provider where it would back a Nextcloud
	 * TaskProcessing provider (it would recurse).
	 *
	 * @param bool $allowNextcloud Whether the caller may use the `nextcloud` provider.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When it may not (400).
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is generateText()'s own, passed through.
	 */
	private function assertNextcloudMayGenerate(bool $allowNextcloud): void {
		if ($allowNextcloud === true) {
			return;
		}

		$message = "The 'nextcloud' chat provider cannot back a Nextcloud TaskProcessing provider ";
		$message .= '(it would recurse). Configure openai, ollama, or fireworks.';
		throw new ProviderUnavailableException($message, 400);
	}//end assertNextcloudMayGenerate()

	/**
	 * Generate one completion of a single user prompt on a non-nextcloud driver:
	 * Fireworks and Anthropic over their direct HTTP calls, OpenAI / Ollama on the
	 * driver's ready LLPhant chat instance.
	 *
	 * @param ChatDriver $driver The resolved driver.
	 * @param string     $prompt The prompt.
	 *
	 * @return string The completion.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) LLPhant's Message role factories are the library's public API.
	 */
	private function generateWithDriver(ChatDriver $driver, string $prompt): string {
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

		// OpenAI / Ollama: driver->chat is a ready LLPhant chat instance.
		return $driver->chat->generateText($prompt);
	}//end generateWithDriver()

	/**
	 * Build the `ollama` driver: native OllamaConfig + OllamaChat.
	 *
	 * @param array             $ollamaConfig The `ollamaConfig` sub-block.
	 * @param ChatDriverRequest $request      The agent's model, temperature and max-tokens
	 *                                        override (maps to `num_predict`).
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When the Ollama URL is not configured.
	 */
	private function createOllamaDriver(array $ollamaConfig, ChatDriverRequest $request): ChatDriver {
		if (empty($ollamaConfig['url']) === true) {
			throw new ProviderUnavailableException('Ollama URL is not configured');
		}

		$config = new OllamaConfig();
		$config->url = rtrim($ollamaConfig['url'], '/') . '/api/';
		$config->model = $this->agentModelOr(configured: ($ollamaConfig['chatModel'] ?? 'llama2'), request: $request);

		// The agent's own ceiling, wired the same way temperature already was.
		// Without this the field was stored, versioned and shown in the UI while
		// having no effect on a single request.
		$config->modelOptions = array_merge($config->modelOptions, $this->agentModelOptions(request: $request, ceilingKey: 'num_predict'));

		$chat = new OllamaChat($config);

		return new ChatDriver(provider: 'ollama', chat: $chat, model: $config->model, maxTokens: $request->agentMaxTokens);
	}//end createOllamaDriver()

	/**
	 * Build the `openai` driver: OpenAIConfig + OpenAIChat.
	 *
	 * The personal/organisation broker credential id (agent-credentials) resolved for
	 * the request overrides `$openaiConfig['credentialId']` when non-empty.
	 *
	 * @param array             $openaiConfig The `openaiConfig` sub-block.
	 * @param ChatDriverRequest $request      The agent's model, temperature and max-tokens
	 *                                        override (maps to `max_tokens`), organisation
	 *                                        and pinned credentials.
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When the OpenAI API key is not configured.
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	private function createOpenAiDriver(array $openaiConfig, ChatDriverRequest $request): ChatDriver {
		$credentialId = $this->overriddenCredentialId(
			config: $openaiConfig,
			override: $this->resolveCredentialOverride(provider: 'openai', organisation: $request->organisation, pinned: $request->agentCredentialIds)
		);
		$this->assertBrokerCredential(credentialId: $credentialId, label: 'OpenAI');

		$config = $this->brokeredOpenAiConfig(credentialId: $credentialId);
		$config->model = $this->agentModelOr(configured: ($openaiConfig['chatModel'] ?? 'gpt-4o-mini'), request: $request);

		// The agent's own ceiling, wired the same way temperature already was.
		$config->modelOptions = array_merge($config->modelOptions, $this->agentModelOptions(request: $request, ceilingKey: 'max_tokens'));

		$chat = new OpenAIChat($config);

		// `credentialId` is carried on the driver for OpenAI too (previously only
		// fireworks/anthropic did, since they need it for their own direct-HTTP call) —
		// it is otherwise baked opaquely into `$config->client`'s BrokerHttpClient with no
		// public accessor, and this is the only externally-observable proof of WHICH
		// credential a personal/organisation override actually resolved to
		// (agent-credentials). Nothing reads `$driver->credentialId` on the openai path
		// today; this is metadata only, not a behaviour change.
		return new ChatDriver(provider: 'openai', chat: $chat, model: $config->model, credentialId: $credentialId, maxTokens: $request->agentMaxTokens);
	}//end createOpenAiDriver()

	/**
	 * An OpenAI client configuration whose requests go through the credential broker.
	 *
	 * The openai-php client REQUIRES an api key and sets it as a Bearer header before
	 * our client ever sees the request. BrokerHttpClient strips that header and the
	 * broker injects the real secret, so what we hand it here is a placeholder, not a
	 * key — Hermiq has none to give. The seam that makes this possible without
	 * rewriting LLPhant: OpenAIChat uses `$config->client` when set, and
	 * OpenAI::factory() accepts any PSR-18 client.
	 *
	 * @param string $credentialId The broker credential UUID.
	 *
	 * @return OpenAIConfig
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OpenAI::factory() is the client library's own builder.
	 */
	private function brokeredOpenAiConfig(string $credentialId): OpenAIConfig {
		$config = new OpenAIConfig();
		$config->apiKey = BrokerHttpClient::BROKER_MANAGED_KEY;
		$config->client = OpenAI::factory()
			->withApiKey(BrokerHttpClient::BROKER_MANAGED_KEY)
			->withHttpClient(
				new BrokerHttpClient(
					credentialId: $credentialId,
					logger: $this->logger,
					actingUserId: $this->currentUid(),
					container: $this->container
				)
			)
			->make();

		return $config;
	}//end brokeredOpenAiConfig()

	/**
	 * The configured model, or the agent's override when it set a non-empty one.
	 *
	 * @param mixed             $configured The provider configuration's model (or its default).
	 * @param ChatDriverRequest $request    The agent's override.
	 *
	 * @return mixed
	 */
	private function agentModelOr(mixed $configured, ChatDriverRequest $request): mixed {
		if (empty($request->agentModel) === false) {
			return $request->agentModel;
		}

		return $configured;
	}//end agentModelOr()

	/**
	 * The agent's temperature and output ceiling as client model options.
	 *
	 * @param ChatDriverRequest $request    The agent's overrides.
	 * @param string            $ceilingKey The option name the provider reads the ceiling from.
	 *
	 * @return array<string, int|float>
	 */
	private function agentModelOptions(ChatDriverRequest $request, string $ceilingKey): array {
		$options = [];
		if ($request->agentTemperature !== null) {
			$options['temperature'] = $request->agentTemperature;
		}

		if ($request->agentMaxTokens !== null) {
			$options[$ceilingKey] = $request->agentMaxTokens;
		}

		return $options;
	}//end agentModelOptions()

	/**
	 * The provider configuration's credential id, or the override when non-empty.
	 *
	 * @param array<string, mixed> $config   The provider's configuration sub-block.
	 * @param string|null          $override The resolved personal/organisation/pinned credential.
	 *
	 * @return string
	 */
	private function overriddenCredentialId(array $config, ?string $override): string {
		if (empty($override) === false) {
			return $override;
		}

		return (string)($config['credentialId'] ?? '');
	}//end overriddenCredentialId()

	/**
	 * Refuse a provider without a broker credential, or when the broker is missing.
	 *
	 * @param string $credentialId The credential id ('' when none).
	 * @param string $label        The provider's display name in the message.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When there is no credential or no broker.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BrokerHttpClient::isAvailable() is that class's
	 *   own static feature-detection seam for the optional OpenRegister broker — checked
	 *   here so the driver fails loud (503) instead of at request time.
	 */
	private function assertBrokerCredential(string $credentialId, string $label): void {
		if ($credentialId === '') {
			throw new ProviderUnavailableException(
				$label . ' has no credential. Select one from the credential broker in the Hermiq LLM settings.',
				503
			);
		}

		if (BrokerHttpClient::isAvailable() === false) {
			throw new ProviderUnavailableException(
				$label . ' cannot be used: the OpenRegister credential broker is not available.',
				503
			);
		}
	}//end assertBrokerCredential()

	/**
	 * Build the `fireworks` driver descriptor. No LLPhant chat instance is
	 * created — generation goes through `callFireworksChat()` (direct HTTP);
	 * see the class docblock for why.
	 *
	 * The personal/organisation broker credential id (agent-credentials) resolved for
	 * the request overrides `$fireworksConfig['credentialId']` when non-empty.
	 *
	 * @param array             $fireworksConfig The `fireworksConfig` sub-block.
	 * @param ChatDriverRequest $request         The agent's model override, organisation
	 *                                           and pinned credentials.
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When the Fireworks API key is not configured.
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	private function createFireworksDriver(array $fireworksConfig, ChatDriverRequest $request): ChatDriver {
		$credentialId = $this->overriddenCredentialId(
			config: $fireworksConfig,
			override: $this->resolveCredentialOverride(provider: 'fireworks', organisation: $request->organisation, pinned: $request->agentCredentialIds)
		);
		$this->assertBrokerCredential(credentialId: $credentialId, label: 'Fireworks AI');

		$model = $this->agentModelOr(configured: ($fireworksConfig['chatModel'] ?? 'accounts/fireworks/models/llama-v3p1-8b-instruct'), request: $request);

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
	 * @param array             $anthropicConfig The `anthropicConfig` sub-block.
	 * @param ChatDriverRequest $request         The agent's model and max-tokens override,
	 *                                           applied when set.
	 *
	 * @return ChatDriver
	 *
	 * @throws ProviderUnavailableException When the credential is missing (503) or the
	 *                                      OpenRegister credential broker is unavailable (503),
	 *                                      mirroring createOpenAiDriver().
	 *
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	private function createAnthropicDriver(array $anthropicConfig, ChatDriverRequest $request): ChatDriver {
		$credentialId = (string)($anthropicConfig['credentialId'] ?? '');
		$this->assertBrokerCredential(credentialId: $credentialId, label: 'Anthropic');

		$model = $this->resolveAnthropicModel(
			configuredModel: ($anthropicConfig['chatModel'] ?? 'claude-opus-4-8'),
			agentModel: $request->agentModel
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
			maxTokens: $request->agentMaxTokens
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
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
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
