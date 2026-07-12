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

use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
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
}//end class
