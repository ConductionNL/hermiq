<?php

/**
 * Hermiq LlmSettingsController model capability tests.
 *
 * The LLM provider modal saves "Reads images" and "Reads PDFs" with the provider.
 * These tests drive the controller with the real ModelCapabilityRegistry over an
 * in-memory app config, so what is asserted is what `hermiq.modelCapabilities`
 * actually holds (chat-attachments-and-images task 4).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\LlmSettingsController;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ModelCapabilityRegistry;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the model capability half of the LLM settings endpoint.
 */
class LlmSettingsModelCapabilitiesTest extends TestCase {

	/**
	 * Every app config value the fake holds, keyed by `app.key`.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Build the real registry over an in-memory IAppConfig.
	 *
	 * @return ModelCapabilityRegistry The registry.
	 */
	private function registry(): ModelCapabilityRegistry {
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

		return new ModelCapabilityRegistry(appConfig: $appConfig);
	}//end registry()

	/**
	 * Build the controller.
	 *
	 * @param IRequest             $request The request.
	 * @param LlmSettingsHandler   $handler The settings handler.
	 *
	 * @return LlmSettingsController The controller.
	 */
	private function controller(IRequest $request, LlmSettingsHandler $handler): LlmSettingsController {
		return new LlmSettingsController(
			request: $request,
			settingsHandler: $handler,
			logger: new NullLogger(),
			modelCapabilities: $this->registry()
		);
	}//end controller()

	/**
	 * A request whose `llm` param is the given patch.
	 *
	 * @param array<string, mixed> $patch The patch.
	 *
	 * @return IRequest The request.
	 */
	private function request(array $patch): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('llm')->willReturn($patch);
		return $request;
	}//end request()

	/**
	 * Ticking "Reads images" for a model lands in `hermiq.modelCapabilities`, and the
	 * llm blob never sees the declarations.
	 *
	 * @return void
	 */
	public function testTickingReadsImagesStoresTheModelCapability(): void {
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->expects($this->once())
			->method('updateLLMSettingsOnly')
			->with($this->callback(static fn (array $patch): bool => array_key_exists('modelCapabilities', $patch) === false))
			->willReturn(['chatProvider' => 'ollama', 'ollamaConfig' => ['chatModel' => 'llava:13b']]);

		$response = $this->controller(
			$this->request(
				[
					'chatProvider' => 'ollama',
					'ollamaConfig' => ['chatModel' => 'llava:13b'],
					'modelCapabilities' => ['ollama/llava:13b' => ['image']],
				]
			),
			$handler
		)->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ollama/llava:13b' => ['image']], json_decode($this->config['hermiq.modelCapabilities'], true));
		$this->assertSame(['ollama/llava:13b' => ['image']], $response->getData()['modelCapabilities']);
	}//end testTickingReadsImagesStoresTheModelCapability()

	/**
	 * A bad capability is refused with 422 before anything is saved.
	 *
	 * @return void
	 */
	public function testABadCapabilityIsRefusedBeforeAnythingIsSaved(): void {
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->expects($this->never())->method('updateLLMSettingsOnly');

		$response = $this->controller(
			$this->request(['chatProvider' => 'openai', 'modelCapabilities' => ['openai/gpt-4o' => ['audio']]]),
			$handler
		)->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
		$this->assertArrayNotHasKey('hermiq.modelCapabilities', $this->config);
	}//end testABadCapabilityIsRefusedBeforeAnythingIsSaved()

	/**
	 * When the llm save fails, the declarations are not written either.
	 *
	 * @return void
	 */
	public function testAFailedSaveWritesNoDeclaration(): void {
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->method('updateLLMSettingsOnly')->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller(
			$this->request(['chatProvider' => 'openai', 'modelCapabilities' => ['openai/gpt-4o' => ['image']]]),
			$handler
		)->update();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey('hermiq.modelCapabilities', $this->config);
	}//end testAFailedSaveWritesNoDeclaration()

	/**
	 * GET returns the declared map, so the modal can show the ticks; undeclared is absent.
	 *
	 * @return void
	 */
	public function testGetReturnsTheDeclaredCapabilities(): void {
		$this->config['hermiq.modelCapabilities'] = '{"anthropic/claude-sonnet-5":["image","pdf"]}';

		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->method('getLLMSettingsOnly')->willReturn(['chatProvider' => 'anthropic']);

		$data = $this->controller($this->createMock(IRequest::class), $handler)->get()->getData();

		$this->assertSame(['anthropic/claude-sonnet-5' => ['image', 'pdf']], $data['modelCapabilities']);
	}//end testGetReturnsTheDeclaredCapabilities()
}//end class
