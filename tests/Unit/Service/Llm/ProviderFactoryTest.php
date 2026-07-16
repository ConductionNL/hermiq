<?php

/**
 * Unit tests for ProviderFactory (agent-engine-port).
 *
 * Covers provider selection per `chatProvider` value including every
 * unavailable/misconfigured error path, agent model/temperature overrides, the
 * Fireworks baseUrl normalisation, and the `nextcloud` driver's `hasProviders()`
 * guard (both true and false) plus its runTask round trip.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
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

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the LLM provider factory.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
class ProviderFactoryTest extends TestCase
{

    /**
     * Build a factory over a mocked TaskProcessing manager.
     *
     * @param bool $hasProviders Whether the manager reports installed providers.
     *
     * @return array{0: ProviderFactory, 1: IManager} The factory and the manager mock.
     */
    private function factory(bool $hasProviders=false): array
    {
        $manager = $this->createMock(IManager::class);
        $manager->method('hasProviders')->willReturn($hasProviders);

        $settings = $this->createMock(LlmSettingsHandler::class);

        // The broker's ownership guard needs an identity to check the credential against.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $factory = new ProviderFactory($settings, $manager, $userSession, new NullLogger());

        return [$factory, $manager];

    }//end factory()

    /**
     * No configured provider raises the recoverable unavailable signal (503).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testMissingProviderThrowsUnavailable(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);
        $factory->createChatDriver(llmConfig: ['chatProvider' => null]);

    }//end testMissingProviderThrowsUnavailable()

    /**
     * An unrecognised provider identifier is rejected clearly.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testUnknownProviderThrowsUnavailable(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('Unsupported chat provider: azure');
        $factory->createChatDriver(llmConfig: ['chatProvider' => 'azure']);

    }//end testUnknownProviderThrowsUnavailable()

    /**
     * Ollama without a URL is a misconfiguration error, not a fatal.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testOllamaWithoutUrlThrowsUnavailable(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('Ollama URL is not configured');
        $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'ollama',
                'ollamaConfig' => ['url' => ''],
            ]
        );

    }//end testOllamaWithoutUrlThrowsUnavailable()

    /**
     * An out-of-policy (provider, model) pair is refused with the 422 violation at
     * the single createChatDriver() chokepoint (tenant-model-policy).
     *
     * @return void
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
     */
    public function testOutOfPolicyPairIsRefusedAtTheChokepoint(): void
    {
        $manager     = $this->createMock(IManager::class);
        $settings    = $this->createMock(LlmSettingsHandler::class);
        $userSession = $this->createMock(IUserSession::class);

        $policy = $this->createMock(TenantModelPolicyService::class);
        $policy->method('isAllowed')->willReturn(false);

        $factory = new ProviderFactory($settings, $manager, $userSession, new NullLogger(), 'hermiq', $policy);

        $this->expectException(ModelPolicyViolationException::class);
        $this->expectExceptionCode(422);
        $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'ollama',
                'ollamaConfig' => [
                    'url'       => 'http://localhost:11434',
                    'chatModel' => 'llama2',
                ],
            ],
            organisation: 'org-a'
        );

    }//end testOutOfPolicyPairIsRefusedAtTheChokepoint()

    /**
     * An in-policy pair passes the enforcement gate and resolves the driver
     * (the gate is consulted exactly once).
     *
     * @return void
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
     */
    public function testInPolicyPairResolvesTheDriver(): void
    {
        $manager     = $this->createMock(IManager::class);
        $settings    = $this->createMock(LlmSettingsHandler::class);
        $userSession = $this->createMock(IUserSession::class);

        $policy = $this->createMock(TenantModelPolicyService::class);
        $policy->expects($this->once())->method('isAllowed')->willReturn(true);

        $factory = new ProviderFactory($settings, $manager, $userSession, new NullLogger(), 'hermiq', $policy);

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'ollama',
                'ollamaConfig' => [
                    'url'       => 'http://localhost:11434',
                    'chatModel' => 'llama2',
                ],
            ],
            organisation: 'org-a'
        );

        $this->assertSame('ollama', $driver->provider);

    }//end testInPolicyPairResolvesTheDriver()

    /**
     * Ollama resolves to an OllamaChat instance; the agent model override wins
     * over the configured chatModel.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testOllamaDriverWithAgentModelOverride(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'ollama',
                'ollamaConfig' => [
                    'url'       => 'http://localhost:11434',
                    'chatModel' => 'llama2',
                ],
            ],
            agentModel: 'qwen3.5',
            agentTemperature: 0.3
        );

        $this->assertSame('ollama', $driver->provider);
        $this->assertInstanceOf(OllamaChat::class, $driver->chat);
        $this->assertSame('qwen3.5', $driver->model);

    }//end testOllamaDriverWithAgentModelOverride()

    /**
     * OpenAI without an API key is a 503-style misconfiguration error.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testOpenAiWithoutKeyThrowsUnavailable(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);
        $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'openai',
                'openaiConfig' => ['credentialId' => ''],
            ]
        );

    }//end testOpenAiWithoutKeyThrowsUnavailable()

    /**
     * OpenAI resolves to an OpenAIChat instance with the configured chat model
     * when no agent override is given.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testOpenAiDriverUsesConfiguredModel(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'openai',
                'openaiConfig' => [
                    // A broker credential UUID, not a key. Hermiq has no OpenAI key.
                    'credentialId' => 'cred-uuid-openai',
                    'chatModel'    => 'gpt-4o-mini',
                ],
            ]
        );

        $this->assertSame('openai', $driver->provider);
        $this->assertInstanceOf(OpenAIChat::class, $driver->chat);
        $this->assertSame('gpt-4o-mini', $driver->model);

    }//end testOpenAiDriverUsesConfiguredModel()

    /**
     * Fireworks without an API key is a 503-style misconfiguration error.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testFireworksWithoutKeyThrowsUnavailable(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);
        $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'fireworks',
                'fireworksConfig' => ['credentialId' => ''],
            ]
        );

    }//end testFireworksWithoutKeyThrowsUnavailable()

    /**
     * Fireworks carries credentials on the driver (direct-HTTP path, no LLPhant
     * chat instance) and normalises the baseUrl to end in /v1.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testFireworksDriverNormalisesBaseUrl(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'fireworks',
                'fireworksConfig' => [
                    'credentialId' => 'cred-uuid-fireworks',
                    'baseUrl'      => 'https://api.fireworks.ai/inference/',
                ],
            ]
        );

        $this->assertSame('fireworks', $driver->provider);
        $this->assertNull($driver->chat);
        // The driver carries a REFERENCE, not a secret. This field used to hold the raw
        // Fireworks key, which meant every handler touching a ChatDriver held a live secret.
        $this->assertSame('cred-uuid-fireworks', $driver->credentialId);
        $this->assertSame('https://api.fireworks.ai/inference/v1', $driver->baseUrl);
        $this->assertSame('accounts/fireworks/models/llama-v3p1-8b-instruct', $driver->model);

    }//end testFireworksDriverNormalisesBaseUrl()

    /**
     * Anthropic without a credential is a 503-style misconfiguration error, naming the
     * missing credential — no request is sent (anthropic-agent-provider).
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
     */
    public function testAnthropicWithoutCredentialThrowsUnavailable(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);
        $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => ['credentialId' => ''],
            ]
        );

    }//end testAnthropicWithoutCredentialThrowsUnavailable()

    /**
     * Anthropic resolves to a direct-HTTP descriptor (no LLPhant chat instance) carrying
     * the credential reference, resolved model, base URL and authMode; the agent model
     * override wins over the configured chatModel.
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
     */
    public function testAnthropicDriverDescriptor(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => [
                    'credentialId' => 'cred-uuid-anthropic',
                    'chatModel'    => 'claude-sonnet-5',
                    'authMode'     => 'oauth',
                    'baseUrl'      => 'https://api.anthropic.com/v1/',
                ],
            ],
            agentModel: 'claude-opus-4-8'
        );

        $this->assertSame('anthropic', $driver->provider);
        $this->assertNull($driver->chat);
        $this->assertSame('cred-uuid-anthropic', $driver->credentialId);
        $this->assertSame('claude-opus-4-8', $driver->model);
        $this->assertSame('https://api.anthropic.com/v1', $driver->baseUrl);
        $this->assertSame('oauth', $driver->authMode);

    }//end testAnthropicDriverDescriptor()

    /**
     * An unrecognised authMode falls back to the safe `api_key` default.
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
     */
    public function testAnthropicDriverDefaultsAuthModeToApiKey(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => [
                    'credentialId' => 'cred-uuid-anthropic',
                    'authMode'     => 'nonsense',
                ],
            ]
        );

        $this->assertSame('api_key', $driver->authMode);
        // The configured default model is used when no override is given.
        $this->assertSame('claude-opus-4-8', $driver->model);

    }//end testAnthropicDriverDefaultsAuthModeToApiKey()

    /**
     * API-key mode sends `x-api-key` set to the broker placeholder + the version header,
     * and NO `Authorization: Bearer` / oauth-beta header. The value handed to transport is
     * never a real secret.
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
     */
    public function testAnthropicApiKeyHeaders(): void
    {
        [$factory] = $this->factory();

        $headers = $factory->buildAnthropicHeaders('api_key');

        $this->assertSame(\OCA\Hermiq\Service\Llm\BrokerHttpClient::BROKER_MANAGED_KEY, $headers['x-api-key']);
        $this->assertSame('2023-06-01', $headers['anthropic-version']);
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertArrayNotHasKey('anthropic-beta', $headers);

        // The transport never receives a real key — only the recognisable placeholder.
        $this->assertStringStartsNotWith('sk-', $headers['x-api-key']);

    }//end testAnthropicApiKeyHeaders()

    /**
     * OAuth (Claude Max) mode sends `Authorization: Bearer` set to the broker placeholder,
     * the version header, and the `anthropic-beta: oauth-2025-04-20` flag — and NO
     * `x-api-key`.
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-both-api-key-and-claude-max-oauth-auth-modes-are-supported
     */
    public function testAnthropicOauthHeaders(): void
    {
        [$factory] = $this->factory();

        $headers = $factory->buildAnthropicHeaders('oauth');

        $this->assertSame('Bearer '.\OCA\Hermiq\Service\Llm\BrokerHttpClient::BROKER_MANAGED_KEY, $headers['Authorization']);
        $this->assertSame('2023-06-01', $headers['anthropic-version']);
        $this->assertSame('oauth-2025-04-20', $headers['anthropic-beta']);
        $this->assertArrayNotHasKey('x-api-key', $headers);

        // The transport never receives a real token — only the recognisable placeholder.
        $this->assertStringContainsString('__managed_by_credential_broker__', $headers['Authorization']);

    }//end testAnthropicOauthHeaders()

    /**
     * An out-of-policy Claude model is refused at the createChatDriver() chokepoint with
     * the 422 violation — before any network call — identically to the other providers.
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-models-pass-through-model-policy-enforcement
     */
    public function testAnthropicOutOfPolicyModelIsBlocked(): void
    {
        $manager     = $this->createMock(IManager::class);
        $settings    = $this->createMock(LlmSettingsHandler::class);
        $userSession = $this->createMock(IUserSession::class);

        $policy = $this->createMock(TenantModelPolicyService::class);
        $policy->method('isAllowed')->willReturn(false);

        $factory = new ProviderFactory($settings, $manager, $userSession, new NullLogger(), 'hermiq', $policy);

        $this->expectException(ModelPolicyViolationException::class);
        $this->expectExceptionCode(422);
        $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => [
                    'credentialId' => 'cred-uuid-anthropic',
                    'chatModel'    => 'claude-opus-4-8',
                ],
            ],
            organisation: 'org-a'
        );

    }//end testAnthropicOutOfPolicyModelIsBlocked()

    /**
     * The nextcloud driver is refused when hasProviders() is false (guard).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
     */
    public function testNextcloudDriverGuardedWhenNoProviders(): void
    {
        [$factory] = $this->factory(hasProviders: false);

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);
        $factory->createChatDriver(llmConfig: ['chatProvider' => 'nextcloud']);

    }//end testNextcloudDriverGuardedWhenNoProviders()

    /**
     * The nextcloud driver resolves when a TaskProcessing provider is installed;
     * it deliberately carries no LLPhant chat instance (non-streaming).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
     */
    public function testNextcloudDriverResolvesWhenProvidersInstalled(): void
    {
        [$factory] = $this->factory(hasProviders: true);

        $driver = $factory->createChatDriver(llmConfig: ['chatProvider' => 'nextcloud']);

        $this->assertSame('nextcloud', $driver->provider);
        $this->assertNull($driver->chat);
        $this->assertSame(TextToText::ID, $driver->model);

    }//end testNextcloudDriverResolvesWhenProvidersInstalled()

    /**
     * generateViaNextcloud() refuses to run without an installed provider.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
     */
    public function testGenerateViaNextcloudGuardedWhenNoProviders(): void
    {
        [$factory] = $this->factory(hasProviders: false);

        $this->expectException(ProviderUnavailableException::class);
        $factory->generateViaNextcloud(prompt: 'Summarise this.');

    }//end testGenerateViaNextcloudGuardedWhenNoProviders()

    /**
     * generateViaNextcloud() runs a core:text2text task and returns its output.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
     */
    public function testGenerateViaNextcloudReturnsTaskOutput(): void
    {
        [$factory, $manager] = $this->factory(hasProviders: true);

        $manager->method('runTask')->willReturnCallback(
            function (Task $task): Task {
                $this->assertSame(TextToText::ID, $task->getTaskTypeId());
                $this->assertSame('hermiq', $task->getAppId());
                $this->assertSame(['input' => 'Summarise this.'], $task->getInput());
                $task->setOutput(['output' => 'A concise summary.']);
                return $task;
            }
        );

        $result = $factory->generateViaNextcloud(prompt: 'Summarise this.');
        $this->assertSame('A concise summary.', $result);

    }//end testGenerateViaNextcloudReturnsTaskOutput()

    /**
     * generateViaNextcloud() surfaces an empty/failed task as unavailable, not a fatal.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
     */
    public function testGenerateViaNextcloudEmptyOutputThrowsUnavailable(): void
    {
        [$factory, $manager] = $this->factory(hasProviders: true);

        $manager->method('runTask')->willReturnCallback(
            static function (Task $task): Task {
                $task->setOutput([]);
                return $task;
            }
        );

        $this->expectException(ProviderUnavailableException::class);
        $factory->generateViaNextcloud(prompt: 'Summarise this.');

    }//end testGenerateViaNextcloudEmptyOutputThrowsUnavailable()

    /**
     * A factory wired with a `CredentialScopeResolver`, for the agent-credentials cases.
     *
     * @param CredentialScopeResolver|null $resolver The resolver stub (or null — not injected).
     *
     * @return ProviderFactory
     */
    private function factoryWithCredentialResolver(?CredentialScopeResolver $resolver): ProviderFactory
    {
        $manager     = $this->createMock(IManager::class);
        $settings    = $this->createMock(LlmSettingsHandler::class);
        $userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession->method('getUser')->willReturn($user);

        return new ProviderFactory($settings, $manager, $userSession, new NullLogger(), 'hermiq', null, null, $resolver);

    }//end factoryWithCredentialResolver()

    /**
     * The resolver's non-null result overrides `openaiConfig.credentialId` when an
     * organisation is passed (agent-credentials).
     *
     * @return void
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    public function testOpenAiCredentialOverrideAppliesWhenOrganisationGiven(): void
    {
        $resolver = $this->createMock(CredentialScopeResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('openai', 'alice', 'org-a')
            ->willReturn('cred-personal-openai');

        $factory = $this->factoryWithCredentialResolver($resolver);

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'openai',
                'openaiConfig' => [
                    'credentialId' => 'cred-instance-openai',
                    'chatModel'    => 'gpt-4o-mini',
                ],
            ],
            organisation: 'org-a'
        );

        $this->assertSame('cred-personal-openai', $driver->credentialId);

    }//end testOpenAiCredentialOverrideAppliesWhenOrganisationGiven()

    /**
     * The resolver's non-null result overrides `fireworksConfig.credentialId` when an
     * organisation is passed (agent-credentials).
     *
     * @return void
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    public function testFireworksCredentialOverrideAppliesWhenOrganisationGiven(): void
    {
        $resolver = $this->createMock(CredentialScopeResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('fireworks', 'alice', 'org-a')
            ->willReturn('cred-org-fireworks');

        $factory = $this->factoryWithCredentialResolver($resolver);

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'fireworks',
                'fireworksConfig' => [
                    'credentialId' => 'cred-instance-fireworks',
                ],
            ],
            organisation: 'org-a'
        );

        $this->assertSame('cred-org-fireworks', $driver->credentialId);

    }//end testFireworksCredentialOverrideAppliesWhenOrganisationGiven()

    /**
     * With `$organisation === null`, the resolver is never even consulted — behaviour is
     * byte-for-byte identical to before this change (agent-credentials).
     *
     * @return void
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    public function testCredentialResolverNotConsultedWithoutAnOrganisation(): void
    {
        $resolver = $this->createMock(CredentialScopeResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $factory = $this->factoryWithCredentialResolver($resolver);

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'openai',
                'openaiConfig' => [
                    'credentialId' => 'cred-instance-openai',
                    'chatModel'    => 'gpt-4o-mini',
                ],
            ]
        );

        $this->assertSame('cred-instance-openai', $driver->credentialId);

    }//end testCredentialResolverNotConsultedWithoutAnOrganisation()

    /**
     * When no resolver is injected at all, the configured instance credential is used
     * unchanged — the nullable-defaulted constructor param never breaks a caller that
     * doesn't provide one (agent-credentials).
     *
     * @return void
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    public function testNoResolverInjectedKeepsTheConfiguredCredential(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'openai',
                'openaiConfig' => [
                    'credentialId' => 'cred-instance-openai',
                    'chatModel'    => 'gpt-4o-mini',
                ],
            ],
            organisation: 'org-a'
        );

        $this->assertSame('cred-instance-openai', $driver->credentialId);

    }//end testNoResolverInjectedKeepsTheConfiguredCredential()

    /**
     * When the resolver returns null (no personal/organisation match), the configured
     * instance credential is used unchanged (agent-credentials, regression case).
     *
     * @return void
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    public function testResolverReturningNullKeepsTheConfiguredCredential(): void
    {
        $resolver = $this->createMock(CredentialScopeResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $factory = $this->factoryWithCredentialResolver($resolver);

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider' => 'fireworks',
                'fireworksConfig' => [
                    'credentialId' => 'cred-instance-fireworks',
                ],
            ],
            organisation: 'org-a'
        );

        $this->assertSame('cred-instance-fireworks', $driver->credentialId);

    }//end testResolverReturningNullKeepsTheConfiguredCredential()


    /**
     * An argument-less tool must serialise `input_schema.properties` as a JSON
     * object (`{}`), not an array (`[]`). PHP's json_encode emits an empty PHP
     * array as `[]`, which the Anthropic Messages API rejects with HTTP 400
     * ("Input should be an object"), so an empty/absent properties map is forced
     * to a stdClass.
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
     */
    public function testBuildAnthropicToolsEmptyPropertiesSerialisesAsObject(): void
    {
        [$factory] = $this->factory();

        $tools = $factory->buildAnthropicTools(
            [
                ['name' => 'no_args', 'description' => 'A tool with no parameters'],
                ['name' => 'empty_props', 'parameters' => ['type' => 'object', 'properties' => []]],
            ]
        );

        $this->assertInstanceOf(\stdClass::class, $tools[0]['input_schema']['properties']);
        $this->assertSame('object', $tools[0]['input_schema']['type']);
        $this->assertInstanceOf(\stdClass::class, $tools[1]['input_schema']['properties']);

        $json = json_encode($tools);
        $this->assertStringNotContainsString('"properties":[]', $json);
        $this->assertStringContainsString('"properties":{}', $json);

    }//end testBuildAnthropicToolsEmptyPropertiesSerialisesAsObject()


    /**
     * A tool that declares real parameters keeps its properties object intact
     * and is not clobbered by the empty-properties guard.
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
     */
    public function testBuildAnthropicToolsPreservesRealProperties(): void
    {
        [$factory] = $this->factory();

        $tools = $factory->buildAnthropicTools(
            [
                [
                    'name'        => 'search',
                    'description' => 'Search',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => ['query' => ['type' => 'string']],
                        'required'   => ['query'],
                    ],
                ],
            ]
        );

        $this->assertSame(['query' => ['type' => 'string']], $tools[0]['input_schema']['properties']);
        $this->assertSame(['query'], $tools[0]['input_schema']['required']);

    }//end testBuildAnthropicToolsPreservesRealProperties()

    /*
     * cli-runner-text-turn-dispatch — `executionMode: cli` (the hermiq-llm-runner ExApp).
     */

    /**
     * Call a private/protected method on the factory.
     *
     * The `cli` dispatch's internals are private by design (design.md: a private method on
     * the class that already owns every other provider branch, not a new Service). Reflection
     * is used rather than widening the API purely for tests.
     *
     * @param ProviderFactory $factory The factory under test.
     * @param string          $method  The method name.
     * @param array           $args    Positional arguments.
     *
     * @return mixed The method's return value.
     */
    private function callPrivate(ProviderFactory $factory, string $method, array $args=[])
    {
        $reflected = new \ReflectionMethod(ProviderFactory::class, $method);
        $reflected->setAccessible(true);

        return $reflected->invokeArgs($factory, $args);

    }//end callPrivate()

    /**
     * Read a private constant off the factory.
     *
     * @param string $name The constant name.
     *
     * @return mixed The constant's value.
     */
    private function constant(string $name)
    {
        return (new \ReflectionClass(ProviderFactory::class))->getConstant($name);

    }//end constant()

    /**
     * With no `executionMode` configured the driver carries `http` — the default transport is
     * unchanged and depends on neither AppAPI nor the ExApp.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-execution-mode-selects-the-anthropic-transport-and-defaults-to-http
     */
    public function testAnthropicDriverDefaultsExecutionModeToHttp(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => ['credentialId' => 'cred-uuid-anthropic'],
            ]
        );

        $this->assertSame('http', $driver->executionMode);

    }//end testAnthropicDriverDefaultsExecutionModeToHttp()

    /**
     * `executionMode: cli` reaches the driver instead of the removed 503 stub — the factory no
     * longer throws, and the driver actually CARRIES the mode. A driver carrying a mode nobody
     * reads was the original bug: the mode was accepted in settings, then silently ignored.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-execution-mode-selects-the-anthropic-transport-and-defaults-to-http
     */
    public function testAnthropicDriverCarriesCliExecutionMode(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => [
                    'credentialId'  => 'cred-uuid-anthropic',
                    'executionMode' => 'cli',
                ],
            ]
        );

        $this->assertSame('cli', $driver->executionMode);

    }//end testAnthropicDriverCarriesCliExecutionMode()

    /**
     * An unrecognised `executionMode` normalises to `http` — an unknown value can never select
     * a transport that does not exist.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-execution-mode-selects-the-anthropic-transport-and-defaults-to-http
     */
    public function testAnthropicDriverNormalisesUnknownExecutionModeToHttp(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => [
                    'credentialId'  => 'cred-uuid-anthropic',
                    'executionMode' => 'sidecar',
                ],
            ]
        );

        $this->assertSame('http', $driver->executionMode);

    }//end testAnthropicDriverNormalisesUnknownExecutionModeToHttp()

    /**
     * THE BOUNDARY TEST. A tool-carrying `cli` turn is REFUSED with a 503 naming tools — it is
     * never degraded to a text-only turn the way the `http` branch degrades (tools + no
     * executor → warn → proceed). A tool-less agent looks completely healthy and simply never
     * calls a tool, so nothing alarms on it; that is the defect this chain exists to correct.
     *
     * If a future "make it more robust" refactor reintroduces degradation, or falls back to
     * `http`, this test breaks rather than the boundary.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-a-cli-turn-that-carries-tools-is-refused-never-run-tool-less
     */
    public function testCliTurnWithToolsIsRefused(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessageMatches('/tools/i');

        $factory->callAnthropicChat(
            credentialId: '00000000-0000-0000-0000-000000000000',
            model: 'claude-opus-4-8',
            baseUrl: 'https://api.anthropic.com/v1',
            messageHistory: [LLPhantMessage::user('Book me a room.')],
            functions: [['name' => 'book_room', 'description' => 'Book a room', 'parameters' => []]],
            executionMode: 'cli'
        );

    }//end testCliTurnWithToolsIsRefused()

    /**
     * The tool refusal fires BEFORE the credential is resolved and BEFORE the ExApp is called,
     * so a doomed turn spends no subscription quota and pulls no secret from the vault.
     *
     * Pinned structurally: this factory has NO app manager, so if the refusal were ordered
     * after the availability guard the message would name the app manager instead of tools.
     * Asserting the message names tools therefore pins the ORDER, not just the outcome.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-a-cli-turn-that-carries-tools-is-refused-never-run-tool-less
     */
    public function testCliToolRefusalPrecedesCredentialAndDispatch(): void
    {
        [$factory] = $this->factory();

        try {
            $factory->callAnthropicChat(
                credentialId: '00000000-0000-0000-0000-000000000000',
                model: 'claude-opus-4-8',
                baseUrl: 'https://api.anthropic.com/v1',
                messageHistory: [LLPhantMessage::user('Book me a room.')],
                functions: [['name' => 'book_room', 'description' => 'Book a room', 'parameters' => []]],
                executionMode: 'cli'
            );
            $this->fail('A tool-carrying cli turn must be refused.');
        } catch (ProviderUnavailableException $e) {
            $this->assertStringContainsStringIgnoringCase('tool', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('app manager', $e->getMessage());
        }

    }//end testCliToolRefusalPrecedesCredentialAndDispatch()

    /**
     * A text-only `cli` turn with no app manager fails with a 503 that NAMES the missing
     * component — never a generic "cli unavailable" an operator cannot act on.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
     */
    public function testCliWithoutAppManagerNamesTheMissingComponent(): void
    {
        [$factory] = $this->factory();

        try {
            $factory->callAnthropicChat(
                credentialId: '00000000-0000-0000-0000-000000000000',
                model: 'claude-opus-4-8',
                baseUrl: 'https://api.anthropic.com/v1',
                messageHistory: [LLPhantMessage::user('Hello.')],
                executionMode: 'cli'
            );
            $this->fail('A cli turn without an app manager must be refused.');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertStringContainsStringIgnoringCase('app manager', $e->getMessage());
        }

    }//end testCliWithoutAppManagerNamesTheMissingComponent()

    /**
     * THE 3-SECOND TRAP. AppAPI defaults `timeout` to 3s (guarded by
     * `if (!isset($options['timeout']))`) while the runner allows the CLI 120s. Omitting it
     * makes the feature 0% functional: every turn fails at 3s while the container runs to
     * completion and bills the user's real subscription.
     *
     * This pins that the option is PRESENT and EXCEEDS the runner's own timeout, so it cannot
     * regress to the default and the runner's kill-and-report wins the race.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
     */
    public function testCliDispatchPassesAnExplicitTimeoutExceedingTheRunners(): void
    {
        [$factory] = $this->factory();

        $options = $this->callPrivate($factory, 'cliDispatchOptions');

        $this->assertArrayHasKey('timeout', $options, 'Omitting timeout falls back to AppAPI\'s 3s default.');
        $this->assertIsInt($options['timeout']);
        $this->assertGreaterThan(
            $this->constant('RUNNER_CLI_TIMEOUT_SECONDS'),
            $options['timeout'],
            'Hermiq must outwait the runner so the runner reports the real reason.'
        );
        $this->assertGreaterThan(3, $options['timeout'], 'This is AppAPI\'s default — the trap.');

    }//end testCliDispatchPassesAnExplicitTimeoutExceedingTheRunners()

    /**
     * The system prompt is carried IN-BAND in `messages` for the runner.
     *
     * The Messages API mapper hoists system turns into a separate top-level `system` field,
     * but the runner has NO such field — reusing it would silently drop the agent's entire
     * persona on every `cli` turn. The runner renders `ROLE: content`, so the system turn must
     * survive as a message.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-cli-completion-is-mapped-back-into-the-driver-response-and-the-sse-envelope
     */
    public function testCliMessageMappingKeepsTheSystemPromptInBand(): void
    {
        [$factory] = $this->factory();

        $messages = $this->callPrivate(
            $factory,
            'mapHistoryToCliMessages',
            [
                [
                    LLPhantMessage::system('You are a terse assistant.'),
                    LLPhantMessage::user('Hello.'),
                ],
            ]
        );

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('You are a terse assistant.', $messages[0]['content']);
        $this->assertSame('user', $messages[1]['role']);

    }//end testCliMessageMappingKeepsTheSystemPromptInBand()

    /**
     * The runner's 200 body maps to the turn's answer.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-cli-completion-is-mapped-back-into-the-driver-response-and-the-sse-envelope
     */
    public function testCliCompletionMapsTextToTheAnswer(): void
    {
        [$factory] = $this->factory();

        $text = $this->callPrivate(
            $factory,
            'mapCliCompletion',
            [(string) json_encode(['text' => 'Amsterdam.', 'toolCalls' => [], 'usage' => ['input_tokens' => 12]])]
        );

        $this->assertSame('Amsterdam.', $text);

    }//end testCliCompletionMapsTextToTheAnswer()

    /**
     * A runner body carrying no usable `text` is a FAILURE, never an empty answer — an empty
     * completion delivered as the model's turn is indistinguishable from a real one.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
     */
    public function testCliCompletionWithoutTextIsRefused(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);

        $this->callPrivate(
            $factory,
            'mapCliCompletion',
            [(string) json_encode(['toolCalls' => [], 'usage' => []])]
        );

    }//end testCliCompletionWithoutTextIsRefused()

    /**
     * An undecodable runner body is a failure, not an answer.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
     */
    public function testCliCompletionWithUndecodableBodyIsRefused(): void
    {
        [$factory] = $this->factory();

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);

        $this->callPrivate($factory, 'mapCliCompletion', ['ExApp `hermiq-llm-runner` not found']);

    }//end testCliCompletionWithUndecodableBodyIsRefused()

    /**
     * An organisation-scope credential is REFUSED for `cli`: a Claude Max/Pro subscription is
     * PERSONAL-SCOPE ONLY under the Anthropic Terms of Service and serves only its owner.
     *
     * The broker does NOT enforce this — its Guard 1 deliberately admits any organisation
     * MEMBER for an organisation-scope credential — so this is the only enforcement point.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
     */
    public function testCliRefusesAnOrganisationScopeCredential(): void
    {
        $resolver = $this->createMock(CredentialScopeResolver::class);
        $resolver->method('scopeOfCredential')->willReturn('organisation');
        $factory = $this->factoryWithCredentialResolver($resolver);

        try {
            $this->callPrivate(
                $factory,
                'assertPersonalScopeCredential',
                ['00000000-0000-0000-0000-000000000000']
            );
            $this->fail('An organisation-scope subscription credential must be refused.');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertStringContainsStringIgnoringCase('personal', $e->getMessage());
        }

    }//end testCliRefusesAnOrganisationScopeCredential()

    /**
     * A personal-scope credential passes the ToS guard.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
     */
    public function testCliAcceptsAPersonalScopeCredential(): void
    {
        $resolver = $this->createMock(CredentialScopeResolver::class);
        $resolver->method('scopeOfCredential')->willReturn('personal');
        $factory = $this->factoryWithCredentialResolver($resolver);

        $this->callPrivate($factory, 'assertPersonalScopeCredential', ['00000000-0000-0000-0000-000000000000']);

        $this->addToAssertionCount(1);

    }//end testCliAcceptsAPersonalScopeCredential()

    /**
     * An unknown credential FAILS CLOSED: a scope that cannot be established is never assumed
     * personal.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
     */
    public function testCliFailsClosedWhenTheScopeCannotBeEstablished(): void
    {
        $resolver = $this->createMock(CredentialScopeResolver::class);
        $resolver->method('scopeOfCredential')->willReturn(null);
        $factory = $this->factoryWithCredentialResolver($resolver);

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);

        $this->callPrivate($factory, 'assertPersonalScopeCredential', ['00000000-0000-0000-0000-000000000000']);

    }//end testCliFailsClosedWhenTheScopeCannotBeEstablished()

    /**
     * With no `CredentialScopeResolver` the scope cannot be verified, so the turn fails closed
     * rather than proceeding with an unverified subscription credential.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
     */
    public function testCliFailsClosedWithoutACredentialResolver(): void
    {
        $factory = $this->factoryWithCredentialResolver(null);

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);

        $this->callPrivate($factory, 'assertPersonalScopeCredential', ['00000000-0000-0000-0000-000000000000']);

    }//end testCliFailsClosedWithoutACredentialResolver()

    /**
     * No `cli` failure message may leak the subscription token. The token is a local variable
     * passed straight into the dispatch payload: never stored on the driver, never logged,
     * never in an exception message.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
     */
    public function testCliDriverNeverCarriesTheToken(): void
    {
        [$factory] = $this->factory();

        $driver = $factory->createChatDriver(
            llmConfig: [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => [
                    'credentialId'  => '00000000-0000-0000-0000-000000000000',
                    'executionMode' => 'cli',
                ],
            ]
        );

        // The driver carries a broker REFERENCE, never a secret — handlers hold this object.
        $this->assertSame('00000000-0000-0000-0000-000000000000', $driver->credentialId);
        $this->assertObjectNotHasProperty('token', $driver);
        $this->assertObjectNotHasProperty('credentialEnv', $driver);

    }//end testCliDriverNeverCarriesTheToken()
}//end class
