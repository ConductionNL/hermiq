<?php

/**
 * The AI provider save and the reports it sends to integriq.
 *
 * An AI provider save is the one moment hermiq learns what the chosen provider
 * means for the `llm` and `llm-runner` rows. If this stops reporting, the
 * Integrations page keeps an old status and looks like an answer. If it reports
 * the wrong thing, an unset provider reads as working, or as a mock that does
 * not exist. Every test asserts the report actually sent.
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
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-the-runner-and-the-speech-sidecar-read-honestly-req-hermiq-conn-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\LlmSettingsController;
use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\Connection\LlmConnectionReport;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for the connection reports sent from an AI provider save.
 *
 * @covers \OCA\Hermiq\Service\Connection\LlmConnectionReport
 * @covers \OCA\Hermiq\Controller\Settings\LlmSettingsController
 *
 * @uses \OCA\Hermiq\Service\Llm\ChatDriver
 */
class LlmSettingsConnectionReportTest extends TestCase {

	/**
	 * Every report sent, keyed by connection key, as [status, message].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private array $reports = [];

	/**
	 * The recording reporter.
	 *
	 * @var ConnectionReporter&MockObject
	 */
	private ConnectionReporter $reporter;

	/**
	 * The factory double; only the two methods the report asks are replaced.
	 *
	 * @var ProviderFactory&MockObject
	 */
	private ProviderFactory $factory;

	/**
	 * Set up a reporter that records instead of dispatching.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reports = [];
		$this->reporter = $this->getMockBuilder(className: ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['report'])
			->getMock();
		$this->reporter->method('report')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->reports[$key] = [$status, $message];
				return true;
			}
		);

		$this->factory = $this->getMockBuilder(className: ProviderFactory::class)
			->disableOriginalConstructor()
			->onlyMethods(['createChatDriver', 'assertCliRunnerAvailable'])
			->getMock();
	}//end setUp()

	/**
	 * Run the report for one saved configuration.
	 *
	 * @param array<string, mixed> $config The saved `llm` configuration.
	 *
	 * @return void
	 */
	private function reportSaved(array $config): void {
		(new LlmConnectionReport(reporter: $this->reporter, providerFactory: $this->factory))->reportSaved(config: $config);
	}//end reportSaved()

	/**
	 * A save without a provider reports unconfigured, and never simulated.
	 *
	 * @return void
	 */
	public function testASaveWithoutAProviderReportsUnconfigured(): void {
		$this->factory->expects($this->never())->method('createChatDriver');

		$this->reportSaved(config: ['chatProvider' => null]);

		$this->assertSame(expected: 'unconfigured', actual: $this->reports['llm'][0]);
		$this->assertStringContainsString(needle: '503', haystack: $this->reports['llm'][1]);
		$this->assertSame(expected: 'unconfigured', actual: $this->reports['llm-runner'][0]);
		$this->assertNotContains(needle: 'simulated', haystack: array_column($this->reports, 0));
	}//end testASaveWithoutAProviderReportsUnconfigured()

	/**
	 * A provider the factory builds reports configured, naming it and saying it is untested.
	 *
	 * @return void
	 */
	public function testABuildableProviderReportsConfigured(): void {
		$config = ['chatProvider' => 'openai', 'openaiConfig' => ['credentialId' => 'cred-1']];
		$this->factory->expects($this->once())->method('createChatDriver')
			->with($config)
			->willReturn(new ChatDriver(provider: 'openai', chat: null, model: 'gpt-4o-mini'));

		$this->reportSaved(config: $config);

		$this->assertSame(expected: 'configured', actual: $this->reports['llm'][0]);
		$this->assertStringContainsString(needle: 'OpenAI', haystack: $this->reports['llm'][1]);
		$this->assertStringContainsString(needle: 'not tested', haystack: $this->reports['llm'][1]);
	}//end testABuildableProviderReportsConfigured()

	/**
	 * A provider the factory refuses reports unconfigured with the factory's own reason.
	 *
	 * @return void
	 */
	public function testARefusedProviderReportsTheFactorysReason(): void {
		$this->factory->method('createChatDriver')->willThrowException(
			new ProviderUnavailableException(
				message: 'Anthropic has no credential. Select one from the credential broker in the Hermiq LLM settings.',
				code: 503
			)
		);

		$this->reportSaved(config: ['chatProvider' => 'anthropic']);

		$this->assertSame(expected: 'unconfigured', actual: $this->reports['llm'][0]);
		$this->assertStringContainsString(needle: 'Anthropic has no credential', haystack: $this->reports['llm'][1]);
	}//end testARefusedProviderReportsTheFactorysReason()

	/**
	 * Anything else the factory throws reports an error, and the report never throws.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureReportsAnError(): void {
		$this->factory->method('createChatDriver')->willThrowException(new RuntimeException('policy table missing'));

		$this->reportSaved(config: ['chatProvider' => 'ollama']);

		$this->assertSame(expected: 'error', actual: $this->reports['llm'][0]);
		$this->assertStringContainsString(needle: 'policy table missing', haystack: $this->reports['llm'][1]);
	}//end testAnUnexpectedFailureReportsAnError()

	/**
	 * Nextcloud Assistant reads Limited, because chat refuses it.
	 *
	 * @return void
	 */
	public function testANextcloudProviderReportsLimited(): void {
		$this->factory->method('createChatDriver')->willReturn(new ChatDriver(provider: 'nextcloud', chat: null, model: 'core:text2text'));

		$this->reportSaved(config: ['chatProvider' => 'nextcloud']);

		$this->assertSame(expected: 'limited', actual: $this->reports['llm'][0]);
		$this->assertStringContainsString(needle: 'Chat cannot run on it', haystack: $this->reports['llm'][1]);
	}//end testANextcloudProviderReportsLimited()

	/**
	 * Anthropic over HTTP does not touch the runner, so the runner reads Not configured.
	 *
	 * @return void
	 */
	public function testHttpAnthropicLeavesTheRunnerUnused(): void {
		$this->factory->method('createChatDriver')->willReturn(new ChatDriver(provider: 'anthropic', chat: null, model: 'claude'));
		$this->factory->expects($this->never())->method('assertCliRunnerAvailable');

		$this->reportSaved(config: ['chatProvider' => 'anthropic', 'anthropicConfig' => ['executionMode' => 'http']]);

		$this->assertSame(expected: 'unconfigured', actual: $this->reports['llm-runner'][0]);
		$this->assertStringContainsString(needle: 'Not in use', haystack: $this->reports['llm-runner'][1]);
	}//end testHttpAnthropicLeavesTheRunnerUnused()

	/**
	 * Cli mode with the runner missing reports an error with AppAPI's reason.
	 *
	 * @return void
	 */
	public function testCliWithoutTheRunnerReportsAnError(): void {
		$this->factory->method('createChatDriver')->willReturn(new ChatDriver(provider: 'anthropic', chat: null, model: 'claude'));
		$this->factory->expects($this->once())->method('assertCliRunnerAvailable')->willThrowException(
			new ProviderUnavailableException(
				message: 'Anthropic executionMode "cli" is unavailable: the "hermiq-llm-runner" ExApp is not installed.',
				code: 503
			)
		);

		$this->reportSaved(config: ['chatProvider' => 'anthropic', 'anthropicConfig' => ['executionMode' => 'cli']]);

		$this->assertSame(expected: 'error', actual: $this->reports['llm-runner'][0]);
		$this->assertStringContainsString(needle: 'not installed', haystack: $this->reports['llm-runner'][1]);
		$this->assertSame(expected: 'configured', actual: $this->reports['llm'][0]);
	}//end testCliWithoutTheRunnerReportsAnError()

	/**
	 * Cli mode with the runner enabled reports configured, and says it is untested.
	 *
	 * @return void
	 */
	public function testCliWithTheRunnerReportsConfigured(): void {
		$this->factory->method('createChatDriver')->willReturn(new ChatDriver(provider: 'anthropic', chat: null, model: 'claude'));
		$this->factory->expects($this->once())->method('assertCliRunnerAvailable');

		$this->reportSaved(config: ['chatProvider' => 'anthropic', 'anthropicConfig' => ['executionMode' => 'cli']]);

		$this->assertSame(expected: 'configured', actual: $this->reports['llm-runner'][0]);
		$this->assertStringContainsString(needle: 'Not tested', haystack: $this->reports['llm-runner'][1]);
	}//end testCliWithTheRunnerReportsConfigured()

	/**
	 * The controller hands the merged save to the report, and a failed save reports nothing.
	 *
	 * @return void
	 */
	public function testTheControllerReportsOnlyASaveThatSucceeded(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->with('llm')->willReturn(['chatProvider' => 'ollama']);

		$merged = ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => 'http://ollama:11434']];
		$handler = $this->createMock(originalClassName: LlmSettingsHandler::class);
		$handler->method('updateLLMSettingsOnly')->willReturnOnConsecutiveCalls(
			$merged,
			$this->throwException(exception: new RuntimeException('disk full'))
		);

		$report = $this->getMockBuilder(className: LlmConnectionReport::class)
			->disableOriginalConstructor()
			->onlyMethods(['reportSaved'])
			->getMock();
		$report->expects($this->once())->method('reportSaved')->with($merged);

		$controller = new LlmSettingsController(request: $request, settingsHandler: $handler, logger: new NullLogger(), connectionReport: $report);

		$this->assertSame(expected: 200, actual: $controller->update()->getStatus());
		$this->assertSame(expected: 500, actual: $controller->update()->getStatus());
	}//end testTheControllerReportsOnlyASaveThatSucceeded()
}//end class
