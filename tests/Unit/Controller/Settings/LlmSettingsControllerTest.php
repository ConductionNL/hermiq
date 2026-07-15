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
            // `credentialId` is a broker credential UUID — a reference, not a secret. It
            // replaces the `apiKey` that used to sit here in CLEARTEXT.
            'openaiConfig'    => ['credentialId' => 'cred-uuid-openai', 'chatModel' => 'gpt-4o-mini'],
            'ollamaConfig'    => ['url' => 'http://localhost:11434', 'chatModel' => null],
            'fireworksConfig' => ['credentialId' => '', 'chatModel' => null, 'baseUrl' => 'https://api.fireworks.ai/inference/v1'],
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
     * get() returns the credential REFERENCE (which is not a secret) and derives the
     * `*Set` flags from it.
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

        // The UUID IS returned — the settings UI needs it to show which credential is
        // selected, and it is safe to return because the secret behind it never leaves
        // the vault.
        $this->assertSame('cred-uuid-openai', $data['openaiConfig']['credentialId']);
    }//end testGetMasksCredentials()

    /**
     * A config blob written BEFORE this release still carries a cleartext `apiKey` until
     * the repair step runs. It must never be echoed to the browser.
     *
     * This is the defensive half of the migration: the keys used to sit unencrypted in the
     * `hermiq.llm` JSON blob, so on any instance that upgrades before repairing, `get()`
     * is the one place that could hand them straight back out.
     *
     * @return void
     */
    public function testGetStripsALegacyCleartextApiKey(): void
    {
        $legacy = [
            'enabled'         => true,
            'chatProvider'    => 'openai',
            'openaiConfig'    => ['apiKey' => 'sk-LEGACY-CLEARTEXT', 'chatModel' => 'gpt-4o-mini'],
            'fireworksConfig' => ['apiKey' => 'fw-LEGACY-CLEARTEXT'],
        ];

        $handler = $this->createMock(LlmSettingsHandler::class);
        $handler->method('getLLMSettingsOnly')->willReturn($legacy);

        $response = $this->controller($this->createMock(IRequest::class), $handler)->get();
        $data     = $response->getData();

        $this->assertArrayNotHasKey('apiKey', $data['openaiConfig']);
        $this->assertArrayNotHasKey('apiKey', $data['fireworksConfig']);

        $serialised = json_encode($data);
        $this->assertStringNotContainsString('sk-LEGACY-CLEARTEXT', (string) $serialised);
        $this->assertStringNotContainsString('fw-LEGACY-CLEARTEXT', (string) $serialised);
    }//end testGetStripsALegacyCleartextApiKey()

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
     * update() rejects a Claude Max (OAuth) credential at organisation scope with 422 —
     * a Max subscription is personal-only per the Anthropic ToS and must never be
     * persisted as an org credential.
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-credential-scope-organisation-vs-personal-claude-max-personal-only
     */
    public function testUpdateRejectsOauthAtOrganisationScope(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('llm')->willReturn(
            [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => [
                    'credentialId' => 'cred-uuid-anthropic',
                    'authMode'     => 'oauth',
                    'scope'        => 'organisation',
                ],
            ]
        );

        $handler = $this->createMock(LlmSettingsHandler::class);
        $handler->expects($this->never())->method('updateLLMSettingsOnly');

        $response = $this->controller($request, $handler)->update();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertStringContainsString('personal', strtolower((string) $response->getData()['error']));
    }//end testUpdateRejectsOauthAtOrganisationScope()

    /**
     * update() accepts a Claude Max (OAuth) credential at personal scope (the only
     * ToS-compliant placement for a Max token).
     *
     * @return void
     *
     * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-credential-scope-organisation-vs-personal-claude-max-personal-only
     */
    public function testUpdateAcceptsOauthAtPersonalScope(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('llm')->willReturn(
            [
                'chatProvider'    => 'anthropic',
                'anthropicConfig' => [
                    'credentialId' => 'cred-uuid-anthropic',
                    'authMode'     => 'oauth',
                    'scope'        => 'personal',
                ],
            ]
        );

        $merged                 = $this->storedConfig();
        $merged['chatProvider'] = 'anthropic';

        $handler = $this->createMock(LlmSettingsHandler::class);
        $handler->expects($this->once())->method('updateLLMSettingsOnly')->willReturn($merged);

        $response = $this->controller($request, $handler)->update();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testUpdateAcceptsOauthAtPersonalScope()

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
