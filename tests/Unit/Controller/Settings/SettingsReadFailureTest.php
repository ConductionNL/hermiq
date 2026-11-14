<?php

/**
 * Unit tests for the read-failure paths of the LLM, web-research and report-similarity settings controllers.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\LlmSettingsController;
use OCA\Hermiq\Controller\Settings\ReportSimilaritySettingsController;
use OCA\Hermiq\Controller\Settings\WebResearchSettingsController;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ModelCapabilityRegistry;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A settings read that fails answers 500 with a fixed message and logs the cause;
 * a blank LLM credential reference is dropped from the patch.
 *
 * @covers \OCA\Hermiq\Controller\Settings\LlmSettingsController
 * @covers \OCA\Hermiq\Controller\Settings\WebResearchSettingsController
 * @covers \OCA\Hermiq\Controller\Settings\ReportSimilaritySettingsController
 * @uses   \OCA\Hermiq\Service\Llm\ModelCapabilityRegistry
 */
class SettingsReadFailureTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Expect one error log with this message and cause.
	 *
	 * @param string $message The logged message.
	 *
	 * @return void
	 */
	private function expectErrorLog(string $message): void {
		$this->logger->expects($this->once())->method('error')->with(
			$message,
			$this->callback(static fn (array $context): bool => $context['error'] === 'store down')
		);
	}//end expectErrorLog()

	/**
	 * The LLM config read failing answers 500 without the cause.
	 *
	 * @return void
	 */
	public function testLlmReadFailure(): void {
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->method('getLLMSettingsOnly')->willThrowException(new RuntimeException('store down'));
		$this->expectErrorLog(message: '[LlmSettingsController] Failed to read hermiq.llm config');

		$response = (new LlmSettingsController(
			request: $this->createMock(IRequest::class),
			settingsHandler: $handler,
			logger: $this->logger,
			modelCapabilities: new ModelCapabilityRegistry(appConfig: $this->createMock(IAppConfig::class))
		))->get();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to read LLM configuration'], $response->getData());
	}//end testLlmReadFailure()

	/**
	 * The web-research config read failing answers 500 without the cause.
	 *
	 * @return void
	 */
	public function testWebResearchReadFailure(): void {
		$handler = $this->createMock(WebResearchSettingsHandler::class);
		$handler->method('getWebResearchSettingsOnly')->willThrowException(new RuntimeException('store down'));
		$this->expectErrorLog(message: '[WebResearchSettingsController] Failed to read hermiq.webResearch config');

		$response = (new WebResearchSettingsController(
			request: $this->createMock(IRequest::class),
			settingsHandler: $handler,
			logger: $this->logger
		))->get();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to read web-research configuration'], $response->getData());
	}//end testWebResearchReadFailure()

	/**
	 * The report-similarity settings read failing answers 500 without the cause.
	 *
	 * @return void
	 */
	public function testReportSimilarityReadFailure(): void {
		$settings = $this->createMock(ReportSimilaritySettings::class);
		$settings->method('all')->willThrowException(new RuntimeException('store down'));
		$this->expectErrorLog(message: '[ReportSimilaritySettingsController] Failed to read the grouping settings');

		$response = (new ReportSimilaritySettingsController(
			request: $this->createMock(IRequest::class),
			settings: $settings,
			logger: $this->logger
		))->get();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to read the grouping settings'], $response->getData());
	}//end testReportSimilarityReadFailure()

	/**
	 * A blank credential reference is dropped so it cannot wipe the stored one; others are kept.
	 *
	 * @return void
	 */
	public function testBlankLlmCredentialIsDropped(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('llm')->willReturn(
			[
				'chatProvider' => 'openai',
				'openaiConfig' => ['credentialId' => '', 'model' => 'gpt-4o'],
				'anthropicConfig' => ['credentialId' => 'cred-7'],
			]
		);
		$handler = $this->createMock(LlmSettingsHandler::class);
		$handler->expects($this->once())->method('updateLLMSettingsOnly')
			->with(
				[
					'chatProvider' => 'openai',
					'openaiConfig' => ['model' => 'gpt-4o'],
					'anthropicConfig' => ['credentialId' => 'cred-7'],
				]
			)
			->willReturn(['chatProvider' => 'openai']);

		$response = (new LlmSettingsController(
			request: $request,
			settingsHandler: $handler,
			logger: $this->logger,
			modelCapabilities: new ModelCapabilityRegistry(appConfig: $this->createMock(IAppConfig::class))
		))->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testBlankLlmCredentialIsDropped()
}//end class
