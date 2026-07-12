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
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OpenAI;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the configured chat provider into a ready-to-use driver.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A provider factory is, by role,
 * the one seam that touches every LLPhant config/chat class plus the TaskProcessing
 * surface; splitting it would smear provider selection across the Engine.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
class ProviderFactory
{
    /**
     * Constructor.
     *
     * @param LlmSettingsHandler $settingsHandler Reads/writes `hermiq.llm`.
     * @param IManager           $taskManager     Nextcloud Assistant task manager
     *                                            (the `nextcloud` driver's
     *                                            backend).
     * @param IUserSession       $userSession     Current session — the broker's
     *                                            ownership guard needs an identity
     *                                            to check the credential against.
     * @param LoggerInterface    $logger          Logger.
     * @param string             $appName         App id used when scheduling
     *                                            TaskProcessing tasks.
     * @param TenantModelPolicyService|null $modelPolicyService Resolves the calling
     *                                            organisation's effective ModelPolicy
     *                                            (tenant-model-policy). Nullable —
     *                                            defaulted so every existing call site
     *                                            (all constructed before this change)
     *                                            keeps working unchanged; NC's DI
     *                                            container autowires a real instance in
     *                                            production regardless of the default.
     *                                            When `$organisation` is not passed to
     *                                            `createChatDriver()`, no enforcement
     *                                            call is made at all (opt-in threading,
     *                                            see design.md).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
     */
    public function __construct(
        private readonly LlmSettingsHandler $settingsHandler,
        private readonly IManager $taskManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly string $appName='hermiq',
        private readonly ?TenantModelPolicyService $modelPolicyService=null
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
                'Chat provider is not configured. Please configure OpenAI, Fireworks AI, Ollama, or Nextcloud Assistant in settings.',
                503
            );
        }

        if ($chatProvider === 'ollama') {
            $driver = $this->createOllamaDriver(
                ollamaConfig: $llmConfig['ollamaConfig'] ?? [],
                agentModel: $agentModel,
                agentTemperature: $agentTemperature
            );
        } elseif ($chatProvider === 'openai') {
            $driver = $this->createOpenAiDriver(
                openaiConfig: $llmConfig['openaiConfig'] ?? [],
                agentModel: $agentModel,
                agentTemperature: $agentTemperature
            );
        } elseif ($chatProvider === 'fireworks') {
            $driver = $this->createFireworksDriver(
                fireworksConfig: $llmConfig['fireworksConfig'] ?? [],
                agentModel: $agentModel
            );
        } elseif ($chatProvider === 'nextcloud') {
            $driver = $this->createNextcloudDriver();
        } else {
            throw new ProviderUnavailableException("Unsupported chat provider: {$chatProvider}");
        }

        // tenant-model-policy: the single enforcement chokepoint. Runs AFTER the
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
     *
     * @return string The generated text.
     *
     * @throws ProviderUnavailableException When no provider is configured/reachable, or
     *                                      `nextcloud` is selected while `$allowNextcloud`
     *                                      is false.
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
     */
    public function generateText(string $prompt, ?string $userId=null, bool $allowNextcloud=true): string
    {
        $llmConfig = $this->getLlmConfig();
        $driver    = $this->createChatDriver(llmConfig: $llmConfig);

        if ($driver->provider === 'fireworks') {
            return $this->callFireworksChat(
                credentialId: (string) $driver->credentialId,
                model: $driver->model,
                baseUrl: (string) $driver->baseUrl,
                messageHistory: [LLPhantMessage::user($prompt)]
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
     * @param array       $openaiConfig     The `openaiConfig` sub-block.
     * @param string|null $agentModel       Agent model override.
     * @param float|null  $agentTemperature Agent temperature override.
     *
     * @return ChatDriver
     *
     * @throws ProviderUnavailableException When the OpenAI API key is not configured.
     */
    private function createOpenAiDriver(array $openaiConfig, ?string $agentModel, ?float $agentTemperature): ChatDriver
    {
        $credentialId = (string) ($openaiConfig['credentialId'] ?? '');
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

        return new ChatDriver(provider: 'openai', chat: $chat, model: $config->model);

    }//end createOpenAiDriver()

    /**
     * Build the `fireworks` driver descriptor. No LLPhant chat instance is
     * created — generation goes through `callFireworksChat()` (direct HTTP);
     * see the class docblock for why.
     *
     * @param array       $fireworksConfig The `fireworksConfig` sub-block.
     * @param string|null $agentModel      Agent model override.
     *
     * @return ChatDriver
     *
     * @throws ProviderUnavailableException When the Fireworks API key is not configured.
     */
    private function createFireworksDriver(array $fireworksConfig, ?string $agentModel): ChatDriver
    {
        $credentialId = (string) ($fireworksConfig['credentialId'] ?? '');
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
