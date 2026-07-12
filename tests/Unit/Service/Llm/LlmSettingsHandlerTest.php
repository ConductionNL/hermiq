<?php

/**
 * Unit tests for LlmSettingsHandler (agent-engine-port).
 *
 * Covers the `hermiq.llm` IAppConfig round trip: full defaults when the key is
 * unset, backward-compatible backfill of `enabled`/`vectorConfig` on decode,
 * PATCH-merge semantics on update, and the additive `nextcloud` chatProvider value.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the hermiq.llm settings handler.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
class LlmSettingsHandlerTest extends TestCase
{

    /**
     * An IAppConfig mock whose `hermiq.llm` value is the given string and whose
     * writes are captured into $written.
     *
     * @param string      $stored  The stored JSON (empty = unset).
     * @param string|null $written Out-param: the last written value.
     *
     * @return IAppConfig
     */
    private function appConfig(string $stored, ?string &$written=null): IAppConfig
    {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') use ($stored): string {
                $this->assertSame('hermiq', $app);
                $this->assertSame('llm', $key);
                if ($stored === '') {
                    return $default;
                }

                return $stored;
            }
        );
        $config->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) use (&$written): bool {
                $this->assertSame('hermiq', $app);
                $this->assertSame('llm', $key);
                $written = $value;
                return true;
            }
        );
        return $config;

    }//end appConfig()

    /**
     * An unset key returns the full default configuration shape.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testDefaultsWhenUnset(): void
    {
        $handler  = new LlmSettingsHandler($this->appConfig(''));
        $settings = $handler->getLLMSettingsOnly();

        $this->assertFalse($settings['enabled']);
        $this->assertNull($settings['chatProvider']);
        $this->assertSame('http://localhost:11434', $settings['ollamaConfig']['url']);
        $this->assertSame('https://api.fireworks.ai/inference/v1', $settings['fireworksConfig']['baseUrl']);
        $this->assertSame('php', $settings['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $settings['vectorConfig']['solrField']);
        $this->assertArrayHasKey('openaiConfig', $settings);

    }//end testDefaultsWhenUnset()

    /**
     * A stored config missing `enabled`/`vectorConfig` gets them backfilled.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testBackfillsMissingFieldsOnDecode(): void
    {
        $stored = json_encode(
            [
                'chatProvider' => 'ollama',
                'ollamaConfig' => ['url' => 'http://ollama:11434', 'chatModel' => 'qwen3'],
            ]
        );

        $handler  = new LlmSettingsHandler($this->appConfig($stored));
        $settings = $handler->getLLMSettingsOnly();

        $this->assertFalse($settings['enabled']);
        $this->assertSame('ollama', $settings['chatProvider']);
        $this->assertSame('php', $settings['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $settings['vectorConfig']['solrField']);

    }//end testBackfillsMissingFieldsOnDecode()

    /**
     * Updating merges with the stored config (PATCH semantics) and persists
     * the full shape, keeping untouched sub-blocks intact.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function testUpdateMergesPatchStyle(): void
    {
        $stored = json_encode(
            [
                'enabled'      => true,
                'chatProvider' => 'openai',
                'openaiConfig' => [
                    'credentialId'   => 'cred-uuid-openai',
                    'model'          => null,
                    'chatModel'      => 'gpt-4o-mini',
                    'organizationId' => '',
                ],
            ]
        );

        $written = null;
        $handler = new LlmSettingsHandler($this->appConfig($stored, $written));
        $result  = $handler->updateLLMSettingsOnly(['chatProvider' => 'nextcloud']);

        // The new provider is applied; the untouched credential reference survives.
        $this->assertSame('nextcloud', $result['chatProvider']);
        $this->assertSame('cred-uuid-openai', $result['openaiConfig']['credentialId']);
        $this->assertTrue($result['enabled']);

        // And the same merged shape was persisted.
        $this->assertNotNull($written);
        $persisted = json_decode($written, true);
        $this->assertSame('nextcloud', $persisted['chatProvider']);
        $this->assertSame('cred-uuid-openai', $persisted['openaiConfig']['credentialId']);

    }//end testUpdateMergesPatchStyle()

    /**
     * A submitted `apiKey` is never carried into the persisted blob.
     *
     * The keys used to live here in CLEARTEXT — not merely app-held, but unencrypted in
     * `oc_appconfig`, printed verbatim by `occ config:app:get hermiq llm`. Hermiq holds no
     * LLM key now, so a client that still sends one must not have it written.
     *
     * @return void
     */
    public function testASubmittedApiKeyIsNeverPersisted(): void
    {
        $stored = json_encode(['enabled' => true, 'chatProvider' => 'openai', 'openaiConfig' => []]);

        $written = null;
        $handler = new LlmSettingsHandler($this->appConfig($stored, $written));

        $handler->updateLLMSettingsOnly(
            [
                'openaiConfig' => [
                    'apiKey'       => 'sk-MUST-NOT-BE-STORED',
                    'credentialId' => 'cred-uuid-openai',
                ],
            ]
        );

        $this->assertNotNull($written);
        $this->assertStringNotContainsString('sk-MUST-NOT-BE-STORED', (string) $written);

        $persisted = json_decode((string) $written, true);
        $this->assertArrayNotHasKey('apiKey', $persisted['openaiConfig']);
        $this->assertSame('cred-uuid-openai', $persisted['openaiConfig']['credentialId']);

    }//end testASubmittedApiKeyIsNeverPersisted()

    /**
     * The allowed chatProvider list carries the additive 4th `nextcloud` value.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
     */
    public function testNextcloudIsAnAllowedChatProvider(): void
    {
        $this->assertSame(
            ['openai', 'ollama', 'fireworks', 'nextcloud'],
            LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS
        );

    }//end testNextcloudIsAnAllowedChatProvider()
}//end class
