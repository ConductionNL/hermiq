<?php

/**
 * The administrator declares a model's context window on the LLM settings: it is
 * validated before anything is saved, stored in its own key after the llm save,
 * and returned with the settings (chat-how-an-answer-came-about D4).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\LlmSettingsController;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ModelCapabilityRegistry;
use OCA\Hermiq\Service\Llm\ModelContextWindows;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the context windows on LlmSettingsController.
 */
class LlmSettingsContextWindowsTest extends TestCase {

	/**
	 * The app config, in memory.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * An app config over $config.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$app . '.' . $key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$app . '.' . $key] = $value;
				return true;
			}
		);
		return $appConfig;
	}//end appConfig()

	/**
	 * The controller with the windows wired.
	 *
	 * @param array<string, mixed>|null $patch   The llm patch, or null for a get.
	 * @param LlmSettingsHandler        $handler The settings handler.
	 *
	 * @return LlmSettingsController
	 */
	private function controller(?array $patch, LlmSettingsHandler $handler): LlmSettingsController {
		$request = $this->createMock(IRequest::class);
		if ($patch !== null) {
			$request->method('getParam')->with('llm')->willReturn($patch);
		}

		return new LlmSettingsController(
			request: $request,
			settingsHandler: $handler,
			logger: new NullLogger(),
			modelCapabilities: new ModelCapabilityRegistry(appConfig: $this->appConfig()),
			contextWindows: new ModelContextWindows($this->appConfig())
		);
	}//end controller()

	/**
	 * A declared window is saved after the llm save and returned, never in the llm blob.
	 *
	 * @return void
	 */
	public function testAWindowIsSavedAndReturned(): void {
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->expects($this->once())->method('updateLLMSettingsOnly')
			->with($this->callback(static fn (array $patch): bool => array_key_exists('modelContextWindows', $patch) === false))
			->willReturn(['chatProvider' => 'ollama']);

		$response = $this->controller(['chatProvider' => 'ollama', 'modelContextWindows' => ['ollama/qwen3:8b' => 128000]], $handler)->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ollama/qwen3:8b' => 128000], json_decode($this->config['hermiq.modelContextWindows'], true));
		$this->assertSame(['ollama/qwen3:8b' => 128000], $response->getData()['modelContextWindows']);
		$this->assertTrue($response->getData()['success']);
	}//end testAWindowIsSavedAndReturned()

	/**
	 * A bad window is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testABadWindowIsRefusedBeforeAnythingIsSaved(): void {
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->expects($this->never())->method('updateLLMSettingsOnly');

		$response = $this->controller(['chatProvider' => 'ollama', 'modelContextWindows' => ['ollama/qwen3:8b' => 'lots']], $handler)->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('whole number of tokens', $response->getData()['error']);
		$this->assertArrayNotHasKey('hermiq.modelContextWindows', $this->config);
	}//end testABadWindowIsRefusedBeforeAnythingIsSaved()

	/**
	 * A failed llm save writes no window.
	 *
	 * @return void
	 */
	public function testAFailedSaveWritesNoWindow(): void {
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->method('updateLLMSettingsOnly')->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller(['chatProvider' => 'ollama', 'modelContextWindows' => ['ollama/qwen3:8b' => 4096]], $handler)->update();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey('hermiq.modelContextWindows', $this->config);
	}//end testAFailedSaveWritesNoWindow()

	/**
	 * The settings read returns the declared windows.
	 *
	 * @return void
	 */
	public function testGetReturnsTheDeclaredWindows(): void {
		$this->config['hermiq.modelContextWindows'] = '{"anthropic/claude-sonnet-4-5":200000}';
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->method('getLLMSettingsOnly')->willReturn(['chatProvider' => 'anthropic']);

		$data = $this->controller(null, $handler)->get()->getData();

		$this->assertSame(['anthropic/claude-sonnet-4-5' => 200000], $data['modelContextWindows']);
	}//end testGetReturnsTheDeclaredWindows()
}//end class
