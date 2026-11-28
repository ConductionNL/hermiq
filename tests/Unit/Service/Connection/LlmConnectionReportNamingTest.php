<?php

/**
 * How the LLM connection report names the provider and decides whether the runner is in use.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Connection;

use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\Connection\LlmConnectionReport;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Connection\LlmConnectionReport
 * @uses \OCA\Hermiq\Service\Llm\ChatDriver
 */
class LlmConnectionReportNamingTest extends TestCase {

	/**
	 * The reports sent, by key, in order.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private array $reports = [];

	/**
	 * The provider factory double.
	 *
	 * @var ProviderFactory&MockObject
	 */
	private ProviderFactory&MockObject $factory;

	/**
	 * Report a saved configuration.
	 *
	 * @param array<string, mixed> $config The saved configuration.
	 *
	 * @return void
	 */
	private function reportSaved(array $config): void {
		$reporter = $this->getMockBuilder(ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['report'])
			->getMock();
		$reporter->expects($this->exactly(2))->method('report')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->reports[$key] = [$status, $message];
				return true;
			}
		);

		(new LlmConnectionReport(reporter: $reporter, providerFactory: $this->factory))->reportSaved(config: $config);
	}//end reportSaved()

	/**
	 * Build the factory double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reports = [];
		$this->factory = $this->getMockBuilder(ProviderFactory::class)
			->disableOriginalConstructor()
			->onlyMethods(['createChatDriver', 'assertCliRunnerAvailable'])
			->getMock();
	}//end setUp()

	/**
	 * Each known provider is named by its display name.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function providers(): array {
		return [
			'openai' => ['openai', 'OpenAI'],
			'ollama' => ['ollama', 'Ollama'],
			'fireworks' => ['fireworks', 'Fireworks AI'],
			'anthropic' => ['anthropic', 'Anthropic'],
			'an unlabelled openai-compatible endpoint' => ['openai-compatible', 'OpenAI-compatible endpoint'],
			'an unknown id' => ['mistral', 'mistral'],
		];
	}//end providers()

	/**
	 * The configured message names the provider by its display name, or its id when unknown.
	 *
	 * @dataProvider providers
	 *
	 * @param string $provider The chat provider id.
	 * @param string $name     The name the report uses.
	 *
	 * @return void
	 */
	public function testTheProviderIsNamed(string $provider, string $name): void {
		$this->factory->expects($this->once())
			->method('createChatDriver')
			->with(['chatProvider' => $provider])
			->willReturn(new ChatDriver(provider: $provider, chat: null, model: 'm'));
		$this->reportSaved(config: ['chatProvider' => $provider]);

		$this->assertSame(['configured', 'Chat uses ' . $name . '. Saved, not tested.'], $this->reports['llm']);
	}//end testTheProviderIsNamed()

	/**
	 * An OpenAI-compatible endpoint is named by its admin's label.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-an-admin-can-connect-an-openai-compatible-endpoint-by-name-req-pconn-001
	 */
	public function testAnOpenAiCompatibleEndpointIsNamedByItsLabel(): void {
		$config = ['chatProvider' => 'openai-compatible', 'openaiCompatibleConfig' => ['label' => ' vLLM Rekencentrum ']];
		$this->factory->method('createChatDriver')->willReturn(new ChatDriver(provider: 'openai-compatible', chat: null, model: 'm'));
		$this->reportSaved(config: $config);

		$this->assertSame(['configured', 'Chat uses vLLM Rekencentrum (OpenAI-compatible). Saved, not tested.'], $this->reports['llm']);
	}//end testAnOpenAiCompatibleEndpointIsNamedByItsLabel()

	/**
	 * A refusal and a failure are prefixed with the provider's display name.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresNameTheProvider(): void {
		$this->factory->method('createChatDriver')->willThrowException(new ProviderUnavailableException(message: 'no key', code: 503));
		$this->reportSaved(config: ['chatProvider' => 'fireworks']);
		$this->assertSame(['unconfigured', 'Fireworks AI is chosen and cannot answer yet: no key'], $this->reports['llm']);

		$this->setUp();
		$this->factory->method('createChatDriver')->willThrowException(new \TypeError('bad config'));
		$this->reportSaved(config: ['chatProvider' => 'openai']);
		$this->assertSame(['error', 'OpenAI is chosen and failed to load: bad config'], $this->reports['llm']);
	}//end testRefusalsAndFailuresNameTheProvider()

	/**
	 * The runner is in use only for Anthropic in cli mode; http is the default mode.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function runnerNotInUse(): array {
		return [
			'anthropic without a config' => [['chatProvider' => 'anthropic']],
			'anthropic with no mode' => [['chatProvider' => 'anthropic', 'anthropicConfig' => []]],
			'another provider in cli mode' => [['chatProvider' => 'openai', 'anthropicConfig' => ['executionMode' => 'cli']]],
			'a differently cased mode' => [['chatProvider' => 'anthropic', 'anthropicConfig' => ['executionMode' => 'CLI']]],
		];
	}//end runnerNotInUse()

	/**
	 * Outside Anthropic in cli mode the runner is reported unused and never checked.
	 *
	 * @dataProvider runnerNotInUse
	 *
	 * @param array<string, mixed> $config The saved configuration.
	 *
	 * @return void
	 */
	public function testTheRunnerIsUnusedOutsideAnthropicCli(array $config): void {
		$this->factory->expects($this->never())->method('assertCliRunnerAvailable');
		$this->factory->method('createChatDriver')->willReturn(new ChatDriver(provider: 'anthropic', chat: null, model: 'm'));

		$this->reportSaved(config: $config);

		$this->assertSame(
			['unconfigured', 'Not in use. Turns reach the runner only when Anthropic is the provider with executionMode cli.'],
			$this->reports['llm-runner']
		);
	}//end testTheRunnerIsUnusedOutsideAnthropicCli()

	/**
	 * The runner is checked even when the chat driver itself is refused.
	 *
	 * @return void
	 */
	public function testTheRunnerIsCheckedEvenWhenTheDriverIsRefused(): void {
		$this->factory->method('createChatDriver')->willThrowException(new ProviderUnavailableException(message: 'no key', code: 503));
		$this->factory->expects($this->once())->method('assertCliRunnerAvailable');

		$this->reportSaved(config: ['chatProvider' => 'anthropic', 'anthropicConfig' => ['executionMode' => 'cli']]);

		$this->assertSame('unconfigured', $this->reports['llm'][0]);
		$this->assertSame(
			['configured', 'AppAPI reports the hermiq-llm-runner ExApp enabled. Anthropic turns run through it. Not tested.'],
			$this->reports['llm-runner']
		);
		$this->assertSame(['llm', 'llm-runner'], array_keys($this->reports));
	}//end testTheRunnerIsCheckedEvenWhenTheDriverIsRefused()
}//end class
