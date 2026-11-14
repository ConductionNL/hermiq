<?php

/**
 * The declared context window per model: stored apart from the llm blob,
 * validated, and removed by a null.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
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

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use InvalidArgumentException;
use OCA\Hermiq\Service\Llm\ModelContextWindows;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ModelContextWindows.
 */
class ModelContextWindowsTest extends TestCase {

	/**
	 * What the app config holds.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The windows over an in-memory app config.
	 *
	 * @return ModelContextWindows
	 */
	private function windows(): ModelContextWindows {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$app . '.' . $key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . '.' . $key] = $value;
				return true;
			}
		);
		return new ModelContextWindows($config);
	}//end windows()

	/**
	 * A declared window is read back per model; another model has none.
	 *
	 * @return void
	 */
	public function testAWindowIsDeclaredAndReadBack(): void {
		$windows = $this->windows();
		$all = $windows->declareMany(declarations: ['ollama/qwen3:8b ' => 128000, 'anthropic/claude-sonnet-4-5' => '200000']);

		$this->assertSame(['ollama/qwen3:8b' => 128000, 'anthropic/claude-sonnet-4-5' => 200000], $all);
		$this->assertSame(128000, $windows->forModel(provider: 'ollama', model: 'qwen3:8b'));
		$this->assertNull($windows->forModel(provider: 'fireworks', model: 'llama'));
		$this->assertSame('{"ollama\/qwen3:8b":128000,"anthropic\/claude-sonnet-4-5":200000}', $this->stored['hermiq.modelContextWindows']);
	}//end testAWindowIsDeclaredAndReadBack()

	/**
	 * A null or empty window removes that model's declaration and leaves the others.
	 *
	 * @return void
	 */
	public function testANullWindowRemovesTheDeclaration(): void {
		$windows = $this->windows();
		$windows->declareMany(declarations: ['ollama/a' => 1000, 'ollama/b' => 2000]);

		$this->assertSame(['ollama/b' => 2000], $windows->declareMany(declarations: ['ollama/a' => null]));
		$this->assertSame([], $windows->declareMany(declarations: ['ollama/b' => '']));
	}//end testANullWindowRemovesTheDeclaration()

	/**
	 * Stored garbage reads as nothing declared.
	 *
	 * @return void
	 */
	public function testStoredGarbageReadsAsNothing(): void {
		$this->stored['hermiq.modelContextWindows'] = '{"ollama/a":"big","ollama/b":-5,"ollama/c":4096}';
		$this->assertSame(['ollama/c' => 4096], $this->windows()->all());
		$this->stored['hermiq.modelContextWindows'] = 'not json';
		$this->assertSame([], $this->windows()->all());
	}//end testStoredGarbageReadsAsNothing()

	/**
	 * Malformed declarations are refused with what is wrong.
	 *
	 * @return void
	 */
	public function testMalformedDeclarationsAreRefused(): void {
		$refusals = [
			[['qwen' => 1000], "must be 'provider/model'"],
			[['acme/qwen' => 1000], "Unsupported provider 'acme'"],
			[['ollama/ ' => 1000], 'names no model'],
			[['ollama/a' => 12.5], 'whole number of tokens'],
			[['ollama/a' => '1e6'], 'whole number of tokens'],
			[['ollama/a' => 0], 'between 1 and'],
			[['ollama/a' => (ModelContextWindows::MAX_WINDOW + 1)], 'between 1 and'],
		];
		foreach ($refusals as [$declaration, $message]) {
			try {
				$this->windows()->normalizeDeclarations(declarations: $declaration);
				$this->fail('Expected a refusal for ' . json_encode($declaration));
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}

		$this->assertSame([], $this->stored);
	}//end testMalformedDeclarationsAreRefused()
}//end class
