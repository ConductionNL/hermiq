<?php

/**
 * Unit tests for LlmSettingsController (taskprocessing-consume-ui).
 *
 * Covers the admin LLM provider read/patch endpoints: credential masking on read,
 * provider validation (422 on an unknown provider), each allowed provider accepted,
 * and the blank-credential-drop that prevents an unedited masked field from wiping a
 * stored key.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-2
 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\LlmSettingsController;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LlmSettingsController.
 *
 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-1
 */
class LlmSettingsControllerTest extends TestCase
{
    /**
     * A representative stored config with a set OpenAI key.
     *
     * @return array The config shape LlmSettingsHandler returns.
     */
    private function storedConfig(): array
    {
        return [
            'enabled'         => true,
            'chatProvider'    => 'openai',
            'openaiConfig'    => ['apiKey' => 'sk-secret', 'chatModel' => 'gpt-4o-mini'],
            'ollamaConfig'    => ['url' => 'http://localhost:11434', 'chatModel' => null],
            'fireworksConfig' => ['apiKey' => '', 'chatModel' => null, 'baseUrl' => 'https://api.fireworks.ai/inference/v1'],
        ];
    }//end storedConfig()

    /**
     * Build a controller over mocked collaborators.
     *
     * @param IRequest           $request The (already-configured) request mock.
     * @param LlmSettingsHandler $handler The (already-configured) settings handler mock.
     *
     * @return LlmSettingsController
     */
    private function controller(IRequest $request, LlmSettingsHandler $handler): LlmSettingsController
    {
        return new LlmSettingsController($request, $handler, new NullLogger());
    }//end controller()

    /**
     * get() masks the stored OpenAI key to a boolean flag and never echoes it.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-2
     */
    public function testGetMasksCredentials(): void
    {
        $handler = $this->createMock(LlmSettingsHandler::class);
        $handler->method('getLLMSettingsOnly')->willReturn($this->storedConfig());

        $response = $this->controller($this->createMock(IRequest::class), $handler)->get();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($data['openaiApiKeySet']);
        $this->assertFalse($data['fireworksApiKeySet']);
        $this->assertArrayNotHasKey('apiKey', $data['openaiConfig']);
        $this->assertSame('openai', $data['chatProvider']);
        // The raw secret must not appear anywhere in the serialised response.
        $this->assertStringNotContainsString('sk-secret', json_encode($data));
    }//end testGetMasksCredentials()

    /**
     * update() rejects a provider outside the allowed set with 422.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-3
     */
    public function testUpdateRejectsUnknownProvider(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('llm')->willReturn(['chatProvider' => 'bogus']);

        $handler = $this->createMock(LlmSettingsHandler::class);
        $handler->expects($this->never())->method('updateLLMSettingsOnly');

        $response = $this->controller($request, $handler)->update();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testUpdateRejectsUnknownProvider()

    /**
     * update() accepts the nextcloud provider and persists it (masked echo).
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-3
     */
    public function testUpdateAcceptsNextcloudProvider(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('llm')->willReturn(['chatProvider' => 'nextcloud']);

        $merged                 = $this->storedConfig();
        $merged['chatProvider'] = 'nextcloud';

        $handler = $this->createMock(LlmSettingsHandler::class);
        $handler->expects($this->once())
            ->method('updateLLMSettingsOnly')
            ->with($this->callback(static fn ($patch): bool => ($patch['chatProvider'] ?? null) === 'nextcloud'))
            ->willReturn($merged);

        $response = $this->controller($request, $handler)->update();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertSame('nextcloud', $data['config']['chatProvider']);
    }//end testUpdateAcceptsNextcloudProvider()

    /**
     * update() drops a blank credential so it never overwrites a stored key.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-3
     */
    public function testUpdateDropsBlankCredential(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('llm')->willReturn(
            [
                'chatProvider' => 'openai',
                'openaiConfig' => ['apiKey' => '', 'chatModel' => 'gpt-4o'],
            ]
        );

        $handler = $this->createMock(LlmSettingsHandler::class);
        $handler->expects($this->once())
            ->method('updateLLMSettingsOnly')
            ->with($this->callback(static function ($patch): bool {
                // The blank apiKey must have been stripped before persisting.
                return array_key_exists('apiKey', $patch['openaiConfig']) === false
                    && ($patch['openaiConfig']['chatModel'] ?? null) === 'gpt-4o';
            }))
            ->willReturn($this->storedConfig());

        $response = $this->controller($request, $handler)->update();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testUpdateDropsBlankCredential()

    /**
     * Every allowed provider passes validation (guards the allow-list wiring).
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-3
     */
    public function testAllAllowedProvidersAccepted(): void
    {
        foreach (LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS as $provider) {
            $request = $this->createMock(IRequest::class);
            $request->method('getParam')->with('llm')->willReturn(['chatProvider' => $provider]);

            $merged                 = $this->storedConfig();
            $merged['chatProvider'] = $provider;

            $handler = $this->createMock(LlmSettingsHandler::class);
            $handler->method('updateLLMSettingsOnly')->willReturn($merged);

            $response = $this->controller($request, $handler)->update();
            $this->assertSame(Http::STATUS_OK, $response->getStatus(), "provider {$provider} should be accepted");
        }
    }//end testAllAllowedProvidersAccepted()
}//end class
