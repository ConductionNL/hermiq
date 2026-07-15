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
use OCP\IUserSession;
use OCP\Server;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OpenAI;
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
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
class ProviderFactory
{

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
     * @param LlmSettingsHandler            $settingsHandler    Reads/writes `hermiq.llm`.
     * @param IManager                      $taskManager        Nextcloud Assistant task manager
     *                                                          (the `nextcloud` driver's
     *                                                          backend).
     * @param IUserSession                  $userSession        Current session —
     *                                                          the broker's
     *                                                          ownership guard needs
     *                                                          an identity to check
     *                                                          the credential
     *                                                          against.
     * @param LoggerInterface               $logger             Logger.
     * @param string                        $appName            App id used when scheduling
     *                                                          TaskProcessing tasks.
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
     * @param IAppManager|null              $appManager         Nextcloud app manager — used ONLY by the
     *                                                          `cli` execution-mode path
     *                                                          (llm-cli-runner-exapp) to detect whether
     *                                                          AppAPI and the `hermiq-llm-runner` ExApp
     *                                                          are installed/enabled before a turn is
     *                                                          dispatched. Nullable/defaulted so every
     *                                                          existing call site (all constructed before
     *                                                          this change) keeps working unchanged; NC's
     *                                                          DI container autowires a real instance in
     *                                                          production. A null manager makes the runner
     *                                                          report unavailable (503), never crash.
     * @param CredentialScopeResolver|null  $credentialResolver Resolves a personal →
     *                                                          organisation override for the
     *                                                          broker credential id the
     *                                                          `openai`/`fireworks` branches use
     *                                                          (agent-credentials). Nullable —
     *                                                          defaulted so every existing call
     *                                                          site (all constructed before this
     *                                                          change) keeps working unchanged;
     *                                                          NC's DI container autowires a real
     *                                                          instance in production regardless
     *                                                          of the default. Consulted ONLY
     *                                                          when `$organisation` is passed to
     *                                                          `createChatDriver()` — the exact
     *                                                          same opt-in guard
     *                                                          `enforceModelPolicy()` uses.
     *
     * @return void
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
        private readonly string $appName='hermiq',
        private readonly ?TenantModelPolicyService $modelPolicyService=null,
        private readonly ?IAppManager $appManager=null,
        private readonly ?CredentialScopeResolver $credentialResolver=null
    ) {
    }//end __construct()

    /**
     * Read the current `hermiq.llm` configuration.
     *
     * @return array LLM configuration (see LlmSettingsHandler::getLLMSettingsOnly()).
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function getLlmConfig(): array
    {
        return $this->settingsHandler->getLLMSettingsOnly();

    }//end getLlmConfig()

    /**
     * Resolve the configured `chatProvider` into a ready-to-use ChatDriver.
     *
     * @param array       $llmConfig        The `hermiq.llm` configuration
     *                                      (LlmSettingsHandler::getLLMSettingsOnly()).
     * @param string|null $agentModel       Agent-level model override, when set and non-empty.
     * @param float|null  $agentTemperature Agent-level temperature override.
     * @param string|null $organisation     The calling agent's organisation
     *                                      (tenant-model-policy). When non-null (including
     *                                      `''` for an organisation-less agent/instance
     *                                      scope), the resolved (provider, model) pair is
     *                                      checked against the effective ModelPolicy for
     *                                      that organisation BEFORE the driver is returned
     *                                      — this is the single enforcement chokepoint every
     *                                      trigger path (schedule, Run now, conversation,
     *                                      flow listener) shares. When null, no check is made
     *                                      (opt-in; existing callers that do not pass an
     *                                      organisation see zero behavior change).
     *
     * @return ChatDriver The resolved driver.
     *
     * @throws ProviderUnavailableException When no provider is configured, the selected
     *                                      provider is missing required credentials, or
     *                                      the provider identifier is not recognised.
     * @throws ModelPolicyViolationException When `$organisation` is given and the resolved
     *                                      (provider, model) pair falls outside its
     *                                      effective ModelPolicy.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
     */
    public function createChatDriver(
        array $llmConfig,
        ?string $agentModel=null,
        ?float $agentTemperature=null,
        ?string $organisation=null
    ): ChatDriver {
        $chatProvider = $llmConfig['chatProvider'] ?? null;

        if (empty($chatProvider) === true) {
            throw new ProviderUnavailableException(
                'Chat provider is not configured. Please configure OpenAI, Anthropic, Fireworks AI, Ollama, or Nextcloud Assistant in settings.',
                503
            );
        }

        if ($chatProvider === 'ollama') {
            $driver = $this->createOllamaDriver(
                ollamaConfig: $llmConfig['ollamaConfig'] ?? [],
                agentModel: $agentModel,
                agentTemperature: $agentTemperature
            );
        } else if ($chatProvider === 'openai') {
            $driver = $this->createOpenAiDriver(
                openaiConfig: $llmConfig['openaiConfig'] ?? [],
                agentModel: $agentModel,
                agentTemperature: $agentTemperature,
                credentialOverride: $this->resolveCredentialOverride(provider: 'openai', organisation: $organisation)
            );
        } else if ($chatProvider === 'fireworks') {
            $driver = $this->createFireworksDriver(
                fireworksConfig: $llmConfig['fireworksConfig'] ?? [],
                agentModel: $agentModel,
                credentialOverride: $this->resolveCredentialOverride(provider: 'fireworks', organisation: $organisation)
            );
        } else if ($chatProvider === 'anthropic') {
            $driver = $this->createAnthropicDriver(
                anthropicConfig: $llmConfig['anthropicConfig'] ?? [],
                agentModel: $agentModel
            );
        } else if ($chatProvider === 'nextcloud') {
            $driver = $this->createNextcloudDriver();
        } else {
            throw new ProviderUnavailableException("Unsupported chat provider: {$chatProvider}");
        }//end if

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
     * @param string      $provider     The resolved provider.
     * @param string      $model        The resolved model id.
     *
     * @return void
     *
     * @throws ModelPolicyViolationException When the pair is outside the effective policy.
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
     */
    private function enforceModelPolicy(?string $organisation, string $provider, string $model): void
    {
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
     * @param string      $provider     The provider identifier (e.g. "openai", "fireworks").
     * @param string|null $organisation The calling organisation, or null to skip resolution.
     *
     * @return string|null The overriding credential uuid, or null to keep the configured
     *                     `hermiq.llm.<provider>Config.credentialId` unchanged.
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    private function resolveCredentialOverride(string $provider, ?string $organisation): ?string
    {
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
     * @param string $credentialId   Broker credential UUID — NOT a key. Hermiq has no
     *                               Fireworks key; the broker holds it and injects it.
     * @param string $model          Model identifier.
     * @param string $baseUrl        Base API URL.
     * @param array  $messageHistory Array of LLPhant Message objects.
     * @param array  $functions      Function definitions (ignored; logged when present).
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
        array $functions=[]
    ): string {
        $url = rtrim($baseUrl, '/').'/chat/completions';

        if (empty($functions) === false) {
            $this->logger->warning(
                message: '[ProviderFactory] Function calling not yet supported for Fireworks AI. Tools will be ignored.',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'functionCount' => count($functions),
                ]
            );
        }

        $messages = [];
        foreach ($messageHistory as $msg) {
            $messages[] = [
                'role'    => $msg->role->value,
                'content' => $msg->content,
            ];
        }

        $payload = [
            'model'    => $model,
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
                    (string) json_encode($payload)
                )
            );
        } catch (Throwable $e) {
            throw new Exception('Fireworks API request failed: '.$e->getMessage());
        }

        $httpCode = $psrResponse->getStatusCode();
        $response = (string) $psrResponse->getBody();

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

            throw new Exception('Unexpected Fireworks API response format: '.$responseStr);
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
    public function buildAnthropicHeaders(string $authMode): array
    {
        $headers = [
            'Content-Type'      => 'application/json',
            'anthropic-version' => '2023-06-01',
        ];

        if ($authMode === 'oauth') {
            // Claude Max / subscription token — the Bearer + beta-flag flow.
            $headers['Authorization']  = 'Bearer '.BrokerHttpClient::BROKER_MANAGED_KEY;
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
     * @param string        $credentialId   Broker credential UUID — NOT a key. Hermiq has
     *                                      no Anthropic secret; the broker holds it and
     *                                      injects it.
     * @param string        $model          Model identifier (e.g. `claude-opus-4-8`).
     * @param string        $baseUrl        Base API URL (e.g. `https://api.anthropic.com/v1`).
     * @param array         $messageHistory Array of LLPhant Message objects.
     * @param string        $authMode       Auth mode: `api_key` (default) or `oauth`.
     * @param int           $maxTokens      Max output tokens (Messages API requires it).
     * @param array         $functions      OpenAI-style function/tool definitions.
     * @param callable|null $toolExecutor   `fn(string $name, array $input): string` — Hermiq's
     *                                      governed tool executor. When null, tools are NOT
     *                                      offered to the model (a pure text turn), so the
     *                                      model can never request a tool Hermiq cannot run.
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
     */
    public function callAnthropicChat(
        string $credentialId,
        string $model,
        string $baseUrl,
        array $messageHistory,
        string $authMode='api_key',
        int $maxTokens=4096,
        array $functions=[],
        ?callable $toolExecutor=null
    ): string {
        $url = rtrim($baseUrl, '/').'/messages';

        // Anthropic keeps the system prompt OUT of `messages`; hoist system turns into
        // the top-level `system` field and map user/assistant turns through.
        $mapped   = $this->mapHistoryToAnthropicMessages(messageHistory: $messageHistory);
        $system   = $mapped['system'];
        $messages = $mapped['messages'];

        // Tools are offered ONLY when both a schema AND an executor are present — the
        // model must never be told about a tool Hermiq cannot then run for it.
        $tools = [];
        if (empty($functions) === false && $toolExecutor !== null) {
            $tools = $this->buildAnthropicTools(functions: $functions);
        } else if (empty($functions) === false) {
            $this->logger->warning(
                message: '[ProviderFactory] Anthropic turn has tools but no executor; running text-only.',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'functionCount' => count($functions),
                ]
            );
        }

        $headers = $this->buildAnthropicHeaders(authMode: $authMode);
        $text    = '';

        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++) {
            $payload = [
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'messages'   => $messages,
            ];

            if ($system !== '') {
                $payload['system'] = $system;
            }

            if (empty($tools) === false) {
                $payload['tools'] = $tools;
            }

            $data   = $this->postToAnthropic(credentialId: $credentialId, url: $url, headers: $headers, payload: $payload, model: $model);
            $parsed = $this->parseAnthropicResponse(data: $data);
            $text   = $parsed['text'];

            // No tool call requested (or no executor to run one) — the turn is complete.
            if ($tools === [] || $toolExecutor === null || $parsed['stopReason'] !== 'tool_use' || $parsed['toolCalls'] === []) {
                break;
            }

            // Echo the assistant's tool_use turn back verbatim, run each requested tool
            // through Hermiq's governed engine (the executor), and feed the results back.
            $messages[]  = [
                'role'    => 'assistant',
                'content' => $parsed['content'],
            ];
            $toolResults = [];
            foreach ($parsed['toolCalls'] as $toolCall) {
                $result        = (string) $toolExecutor($toolCall['name'], $toolCall['input']);
                $toolResults[] = [
                    'tool_use_id' => $toolCall['id'],
                    'content'     => $result,
                ];
            }

            $messages[] = [
                'role'    => 'user',
                'content' => $this->buildAnthropicToolResultBlocks(toolResults: $toolResults),
            ];
        }//end for

        return $text;

    }//end callAnthropicChat()

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
    public function mapHistoryToAnthropicMessages(array $messageHistory): array
    {
        $systemParts = [];
        $messages    = [];
        foreach ($messageHistory as $msg) {
            $role = $msg->role->value;
            if ($role === 'system') {
                $systemParts[] = (string) $msg->content;
                continue;
            }

            // @todo llm-cli-runner-exapp / anthropic-agent-provider — Hermiq's engine never
            // stores prior tool_use/tool_result turns as LLPhant messages today (tool turns
            // live only inside this call's Anthropic loop). If a future history carries them
            // (e.g. LLPhant Message::toolCalls), map them to tool_use/tool_result content
            // blocks here. Until then, pass text turns through — the common path.
            $messages[] = [
                'role'    => $role,
                'content' => (string) $msg->content,
            ];
        }

        return [
            'system'   => implode("\n\n", $systemParts),
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
    public function buildAnthropicTools(array $functions): array
    {
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
            if (isset($schema['properties']) === false
                || ($schema['properties'] === [] || $schema['properties'] === null)
            ) {
                $schema['properties'] = new \stdClass();
            }

            $tools[] = [
                'name'         => (string) $function['name'],
                'description'  => (string) ($function['description'] ?? ''),
                'input_schema' => $schema,
            ];
        }

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
    public function parseAnthropicResponse(array $data): array
    {
        $content = [];
        if (is_array($data['content'] ?? null) === true) {
            $content = $data['content'];
        }

        $text      = '';
        $toolCalls = [];
        foreach ($content as $block) {
            if (is_array($block) === false) {
                continue;
            }

            $type = ($block['type'] ?? '');
            if ($type === 'text') {
                $text .= (string) ($block['text'] ?? '');
                continue;
            }

            if ($type === 'tool_use') {
                $input = ($block['input'] ?? []);
                if (is_array($input) === false) {
                    $input = [];
                }

                $toolCalls[] = [
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => $input,
                ];
            }
        }//end foreach

        $usage    = [];
        $rawUsage = ($data['usage'] ?? []);
        if (is_array($rawUsage) === true) {
            $usage = [
                'promptTokens'     => (int) ($rawUsage['input_tokens'] ?? 0),
                'completionTokens' => (int) ($rawUsage['output_tokens'] ?? 0),
            ];
        }

        return [
            'text'       => $text,
            'toolCalls'  => $toolCalls,
            'stopReason' => (string) ($data['stop_reason'] ?? ''),
            'content'    => $content,
            'usage'      => $usage,
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
    public function buildAnthropicToolResultBlocks(array $toolResults): array
    {
        $blocks = [];
        foreach ($toolResults as $toolResult) {
            if (is_array($toolResult) === false) {
                continue;
            }

            $block = [
                'type'        => 'tool_result',
                'tool_use_id' => (string) ($toolResult['tool_use_id'] ?? ''),
                'content'     => (string) ($toolResult['content'] ?? ''),
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
     * @param string               $credentialId Broker credential UUID.
     * @param string               $url          The `/messages` endpoint URL.
     * @param array<string,string> $headers      Auth/version headers from `buildAnthropicHeaders()`.
     * @param array<string,mixed>  $payload      The request payload.
     * @param string               $model        Model id (for error messages only).
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
    private function postToAnthropic(string $credentialId, string $url, array $headers, array $payload, string $model): array
    {
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
                    (string) json_encode($payload)
                )
            );
        } catch (Throwable $e) {
            throw new Exception('Anthropic API request failed: '.$e->getMessage());
        }

        $httpCode = $psrResponse->getStatusCode();
        $response = (string) $psrResponse->getBody();

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
                throw new Exception('Rate limit exceeded. Please try again later.');
            }

            throw new Exception("Anthropic API error (HTTP {$httpCode}): {$errorMessage}");
        }//end if

        $data = json_decode($response, true);
        if (is_array($data) === false) {
            throw new Exception('Unexpected Anthropic API response format: '.$response);
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
     * @param string      $prompt   The prompt text.
     * @param string|null $userId   The user id scheduling the task (optional).
     * @param string|null $customId Optional custom task id for correlation.
     *
     * @return string The generated text.
     *
     * @throws ProviderUnavailableException When no TaskProcessing provider is installed,
     *                                      or the task fails/returns no output.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
     */
    public function generateViaNextcloud(string $prompt, ?string $userId=null, ?string $customId=null): string
    {
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
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            throw new ProviderUnavailableException('Nextcloud Assistant task failed: '.$e->getMessage(), 0, $e);
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
     * @param string      $prompt         The prompt text.
     * @param string|null $userId         The user id (forwarded to the nextcloud driver).
     * @param bool        $allowNextcloud When false, selecting the `nextcloud` driver is
     *                                    rejected. TaskProcessing providers pass false: a
     *                                    Hermiq TaskProcessing provider backed by the
     *                                    `nextcloud` (TaskProcessing) driver would recurse
     *                                    into TaskProcessing endlessly.
     * @param string|null $organisation   Agent-evals: when non-null (including `''` for an
     *                                    organisation-less scope), the resolved (provider,
     *                                    model) pair is checked against that organisation's
     *                                    effective ModelPolicy before generating — the SAME
     *                                    `createChatDriver()` enforcement chokepoint an
     *                                    agent-under-test call goes through, so an
     *                                    LLM-as-judge call is model-policy-governed exactly
     *                                    like any other Hermiq LLM call. Default `null`
     *                                    preserves every pre-existing caller's behaviour
     *                                    unchanged (opt-in, no enforcement).
     *
     * @return string The generated text.
     *
     * @throws ProviderUnavailableException When no provider is configured/reachable, or
     *                                      `nextcloud` is selected while `$allowNextcloud`
     *                                      is false.
     * @throws ModelPolicyViolationException When `$organisation` is given and the resolved
     *                                      (provider, model) pair falls outside its
     *                                      effective ModelPolicy.
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
    public function generateText(string $prompt, ?string $userId=null, bool $allowNextcloud=true, ?string $organisation=null): string
    {
        $llmConfig = $this->getLlmConfig();
        $driver    = $this->createChatDriver(llmConfig: $llmConfig, organisation: $organisation);

        if ($driver->provider === 'fireworks') {
            return $this->callFireworksChat(
                credentialId: (string) $driver->credentialId,
                model: $driver->model,
                baseUrl: (string) $driver->baseUrl,
                messageHistory: [LLPhantMessage::user($prompt)]
            );
        }

        if ($driver->provider === 'anthropic') {
            return $this->callAnthropicChat(
                credentialId: (string) $driver->credentialId,
                model: $driver->model,
                baseUrl: (string) $driver->baseUrl,
                messageHistory: [LLPhantMessage::user($prompt)],
                authMode: (string) $driver->authMode
            );
        }

        if ($driver->provider === 'nextcloud') {
            if ($allowNextcloud === false) {
                $message  = "The 'nextcloud' chat provider cannot back a Nextcloud TaskProcessing provider ";
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
     * @param array       $ollamaConfig     The `ollamaConfig` sub-block.
     * @param string|null $agentModel       Agent model override.
     * @param float|null  $agentTemperature Agent temperature override.
     *
     * @return ChatDriver
     *
     * @throws ProviderUnavailableException When the Ollama URL is not configured.
     */
    private function createOllamaDriver(array $ollamaConfig, ?string $agentModel, ?float $agentTemperature): ChatDriver
    {
        if (empty($ollamaConfig['url']) === true) {
            throw new ProviderUnavailableException('Ollama URL is not configured');
        }

        $config      = new OllamaConfig();
        $config->url = rtrim($ollamaConfig['url'], '/').'/api/';

        $config->model = ($ollamaConfig['chatModel'] ?? 'llama2');
        if (empty($agentModel) === false) {
            $config->model = $agentModel;
        }

        if ($agentTemperature !== null) {
            $config->modelOptions['temperature'] = $agentTemperature;
        }

        $chat = new OllamaChat($config);

        return new ChatDriver(provider: 'ollama', chat: $chat, model: $config->model);

    }//end createOllamaDriver()

    /**
     * Build the `openai` driver: OpenAIConfig + OpenAIChat.
     *
     * @param array       $openaiConfig       The `openaiConfig` sub-block.
     * @param string|null $agentModel         Agent model override.
     * @param float|null  $agentTemperature   Agent temperature override.
     * @param string|null $credentialOverride Personal/organisation broker credential id
     *                                        (agent-credentials) that overrides
     *                                        `$openaiConfig['credentialId']` when non-empty.
     *
     * @return ChatDriver
     *
     * @throws ProviderUnavailableException When the OpenAI API key is not configured.
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    private function createOpenAiDriver(
        array $openaiConfig,
        ?string $agentModel,
        ?float $agentTemperature,
        ?string $credentialOverride=null
    ): ChatDriver {
        $credentialId = (string) ($openaiConfig['credentialId'] ?? '');
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

        $chat = new OpenAIChat($config);

        // `credentialId` is carried on the driver for OpenAI too (previously only
        // fireworks/anthropic did, since they need it for their own direct-HTTP call) —
        // it is otherwise baked opaquely into `$config->client`'s BrokerHttpClient with no
        // public accessor, and this is the only externally-observable proof of WHICH
        // credential a personal/organisation override actually resolved to
        // (agent-credentials). Nothing reads `$driver->credentialId` on the openai path
        // today; this is metadata only, not a behaviour change.
        return new ChatDriver(provider: 'openai', chat: $chat, model: $config->model, credentialId: $credentialId);

    }//end createOpenAiDriver()

    /**
     * Build the `fireworks` driver descriptor. No LLPhant chat instance is
     * created — generation goes through `callFireworksChat()` (direct HTTP);
     * see the class docblock for why.
     *
     * @param array       $fireworksConfig    The `fireworksConfig` sub-block.
     * @param string|null $agentModel         Agent model override.
     * @param string|null $credentialOverride Personal/organisation broker credential id
     *                                        (agent-credentials) that overrides
     *                                        `$fireworksConfig['credentialId']` when non-empty.
     *
     * @return ChatDriver
     *
     * @throws ProviderUnavailableException When the Fireworks API key is not configured.
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    private function createFireworksDriver(array $fireworksConfig, ?string $agentModel, ?string $credentialOverride=null): ChatDriver
    {
        $credentialId = (string) ($fireworksConfig['credentialId'] ?? '');
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
     * @param array       $anthropicConfig The `anthropicConfig` sub-block.
     * @param string|null $agentModel      Agent model override.
     *
     * @return ChatDriver
     *
     * @throws ProviderUnavailableException When the credential is missing (503) or the
     *                                      OpenRegister credential broker is unavailable (503),
     *                                      mirroring createOpenAiDriver().
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
     */
    private function createAnthropicDriver(array $anthropicConfig, ?string $agentModel): ChatDriver
    {
        $credentialId = (string) ($anthropicConfig['credentialId'] ?? '');
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

        $model = ($anthropicConfig['chatModel'] ?? 'claude-opus-4-8');
        if (empty($agentModel) === false) {
            $model = $agentModel;
        }

        $authMode = ($anthropicConfig['authMode'] ?? 'api_key');
        if ($authMode !== 'oauth') {
            $authMode = 'api_key';
        }

        // `executionMode: cli` (llm-cli-runner-exapp) routes the turn through the
        // hermiq-llm-runner ExApp instead of direct HTTP. The AppAPI dispatch to the
        // ExApp `/run` route is not yet wired (tracked follow-up), so fail LOUDLY here
        // rather than silently serving the `http` path — an operator who selected `cli`
        // must get a clear signal, not a different transport.
        $executionMode = ($anthropicConfig['executionMode'] ?? 'http');
        if ($executionMode === 'cli') {
            $message  = 'Anthropic executionMode "cli" (hermiq-llm-runner ExApp) is not yet available: ';
            $message .= 'the AppAPI dispatch to the runner is a tracked follow-up. Use executionMode "http" for now.';
            throw new ProviderUnavailableException($message, 503);
        }

        $baseUrl = rtrim($anthropicConfig['baseUrl'] ?? 'https://api.anthropic.com/v1', '/');

        // `credentialId` is a broker reference, not a secret — the key or OAuth token lives
        // in the vault and is injected server-side by BrokerHttpClient at egress.
        return new ChatDriver(
            provider: 'anthropic',
            chat: null,
            model: $model,
            credentialId: $credentialId,
            baseUrl: $baseUrl,
            authMode: $authMode
        );

    }//end createAnthropicDriver()

    /**
     * The calling user's UID, when there is a session.
     *
     * The broker's ownership guard needs an identity to check the credential against. On
     * the scheduled-agent path there is no session; the credential owner has to be carried
     * on the run instead.
     *
     * @return string|null The UID, or null when there is no session.
     */
    private function currentUid(): ?string
    {
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
    private function createNextcloudDriver(): ChatDriver
    {
        if ($this->taskManager->hasProviders() === false) {
            throw new ProviderUnavailableException('No Nextcloud Assistant (TaskProcessing) provider is installed.', 503);
        }

        return new ChatDriver(provider: 'nextcloud', chat: null, model: TextToText::ID);

    }//end createNextcloudDriver()
}//end class
