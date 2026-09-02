<?php

/**
 * Unit tests for ChatHealthController (agent-engine-port).
 *
 * The probe reports 200 {status:ok} when a chat provider is configured in
 * `hermiq.llm` and 200 {status:unconfigured, capabilities:[]} when none is: an
 * unconfigured app is healthy, and a 5xx would trip every co-installed app's
 * no-5xx e2e guard. Only a failing config read, the app itself being broken,
 * is 503 {status:config_error}.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatHealthController;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the agent-engine-port ChatHealthController.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class ChatHealthControllerTest extends TestCase {

	/**
	 * Build the controller with the given LLM settings handler.
	 *
	 * @param LlmSettingsHandler $llmSettings The settings handler mock.
	 *
	 * @return ChatHealthController
	 */
	private function controller(LlmSettingsHandler $llmSettings): ChatHealthController {
		return new ChatHealthController(
			$this->createMock(IRequest::class),
			$llmSettings,
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * A configured chat provider yields 200 {status:ok, capabilities:[chat,stream]}.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testHealthOkWhenProviderConfigured(): void {
		$llmSettings = $this->createMock(LlmSettingsHandler::class);
		$llmSettings->method('getLLMSettingsOnly')->willReturn(['chatProvider' => 'ollama']);

		$response = $this->controller($llmSettings)->health();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('ok', $response->getData()['status']);
		$this->assertSame(['chat', 'stream'], $response->getData()['capabilities']);

	}//end testHealthOkWhenProviderConfigured()

	/**
	 * No configured chat provider yields 200 {status:unconfigured}: healthy,
	 * not broken. The empty capability list is what tells a consumer there is
	 * no chat to offer; the HTTP code no longer carries that decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testHealthUnconfiguredIsStillHealthy(): void {
		$llmSettings = $this->createMock(LlmSettingsHandler::class);
		$llmSettings->method('getLLMSettingsOnly')->willReturn(['chatProvider' => null]);

		$response = $this->controller($llmSettings)->health();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('unconfigured', $response->getData()['status']);
		$this->assertFalse($response->getData()['configured']);
		$this->assertSame([], $response->getData()['capabilities']);

	}//end testHealthUnconfiguredIsStillHealthy()

	/**
	 * A failing config read yields 503 {status:config_error}, not no_provider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testHealthConfigErrorWhenSettingsReadFails(): void {
		$llmSettings = $this->createMock(LlmSettingsHandler::class);
		$llmSettings->method('getLLMSettingsOnly')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller($llmSettings)->health();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('config_error', $response->getData()['status']);

	}//end testHealthConfigErrorWhenSettingsReadFails()
}//end class
