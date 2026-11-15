<?php

/**
 * Golden tests for the RemoveLegacyLlmKeys repair step.
 *
 * Pins the exact config written and every message for each branch: no config,
 * invalid JSON, no keys, only empty keys (nothing written), real keys among empty
 * ones and non-array blocks, and a write that fails.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-4-delete-the-cleartext-keys
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\RemoveLegacyLlmKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the legacy LLM key removal.
 *
 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-4-delete-the-cleartext-keys
 */
class RemoveLegacyLlmKeysGoldenTest extends TestCase {

	/**
	 * Run the step over a stored config and answer every line and write, in order.
	 *
	 * @param string $stored The stored `llm` value.
	 * @param bool $writeFails Whether the rewrite throws.
	 *
	 * @return array<int, string>
	 */
	private function runStep(string $stored, bool $writeFails = false): array {
		$log = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('hermiq', 'llm', '')->willReturn($stored);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$log, $writeFails): bool {
				$log[] = 'set ' . $app . ' ' . $key . ' ' . $value;
				if ($writeFails === true) {
					throw new RuntimeException('read-only');
				}

				return true;
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $m) use (&$log): void {
				$log[] = 'info: ' . $m;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $m) use (&$log): void {
				$log[] = 'warning: ' . $m;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $m, array $c = []) use (&$log): void {
				$log[] = 'log.error: ' . $m . ' ' . json_encode($c);
			}
		);

		(new RemoveLegacyLlmKeys($appConfig, $logger))->run($output);
		return $log;
	}//end runStep()

	/**
	 * Every branch, line for line.
	 *
	 * @return void
	 */
	public function testEveryBranch(): void {
		$this->assertSame(['info: No Hermiq LLM config stored; nothing to remove.'], $this->runStep(''));
		$this->assertSame(['warning: Hermiq LLM config is not valid JSON; leaving it untouched.'], $this->runStep('{nope'));
		$this->assertSame(['warning: Hermiq LLM config is not valid JSON; leaving it untouched.'], $this->runStep('"text"'));
		$this->assertSame(
			['info: No cleartext LLM API keys stored; nothing to remove.'],
			$this->runStep((string)json_encode(['openaiConfig' => ['model' => 'm'], 'fireworksConfig' => 'x']))
		);
		$this->assertSame(
			['info: No cleartext LLM API keys stored; nothing to remove.'],
			$this->runStep((string)json_encode(['openaiConfig' => ['apiKey' => ''], 'fireworksConfig' => ['apiKey' => null]]))
		);

		$this->assertSame(
			[
				'set hermiq llm {"provider":"openai","openaiConfig":{"model":"m"},"fireworksConfig":{"url":"u"},"ollamaConfig":{"apiKey":"keep"}}',
				'info: Removed 1 cleartext LLM API key(s). Select a credential from the broker in the Hermiq LLM settings to re-enable each provider.',
			],
			$this->runStep(
				(string)json_encode(
					[
						'provider' => 'openai',
						'openaiConfig' => ['apiKey' => 'sk-1', 'model' => 'm'],
						'fireworksConfig' => ['apiKey' => '', 'url' => 'u'],
						'ollamaConfig' => ['apiKey' => 'keep'],
					]
				)
			)
		);

		$this->assertSame(
			[
				'set hermiq llm {"openaiConfig":[],"fireworksConfig":[]}',
				'warning: Could not rewrite the Hermiq LLM config: read-only',
				'log.error: [Hermiq] Could not remove the cleartext LLM API keys; they are still stored {"exception":"read-only"}',
			],
			$this->runStep((string)json_encode(['openaiConfig' => ['apiKey' => 'a'], 'fireworksConfig' => ['apiKey' => 'b']]), true)
		);
	}//end testEveryBranch()
}//end class
