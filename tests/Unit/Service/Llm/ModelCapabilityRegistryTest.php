<?php

/**
 * Hermiq ModelCapabilityRegistry unit tests.
 *
 * Covers the declared-not-guessed rule (an undeclared model reads nothing natively,
 * whatever its name), the `provider/model` key with a model id that itself holds
 * slashes, and the refusals on the write path (chat-attachments-and-images task 4).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use InvalidArgumentException;
use OCA\Hermiq\Service\Llm\ModelCapabilityRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the administered input capabilities per provider and model.
 */
class ModelCapabilityRegistryTest extends TestCase {

	/**
	 * The raw `hermiq.modelCapabilities` value the fake config holds.
	 *
	 * @var array{value: string, writes: int}
	 */
	private array $store = ['value' => '', 'writes' => 0];

	/**
	 * Build a registry over an in-memory IAppConfig.
	 *
	 * @param string $initial The stored JSON value before the test.
	 *
	 * @return ModelCapabilityRegistry The registry under test.
	 */
	private function registry(string $initial = ''): ModelCapabilityRegistry {
		$this->store = ['value' => $initial, 'writes' => 0];
		$config = $this->createMock(IAppConfig::class);

		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame('hermiq', $app);
				$this->assertSame('modelCapabilities', $key);
				if ($this->store['value'] === '') {
					return $default;
				}

				return $this->store['value'];
			}
		);

		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('hermiq', $app);
				$this->assertSame('modelCapabilities', $key);
				$this->store['value'] = $value;
				$this->store['writes']++;
				return true;
			}
		);

		return new ModelCapabilityRegistry(appConfig: $config);
	}//end registry()

	/**
	 * An undeclared model reports no capability, even with a vision-sounding name.
	 *
	 * @return void
	 */
	public function testAnUndeclaredModelReportsNoCapability(): void {
		$registry = $this->registry();

		$this->assertSame([], $registry->forModel(provider: 'openai', model: 'gpt-4o'));
		$this->assertFalse($registry->supports(provider: 'openai', model: 'gpt-4o', capability: 'image'));
		$this->assertFalse($registry->supports(provider: 'ollama', model: 'llava:13b', capability: 'image'));
		$this->assertSame([], $registry->all());
	}//end testAnUndeclaredModelReportsNoCapability()

	/**
	 * Ticking "Reads images" stores the model under `provider/model`.
	 *
	 * @return void
	 */
	public function testDeclaringImageStoresItUnderProviderSlashModel(): void {
		$registry = $this->registry();

		$result = $registry->declare(provider: 'openai', model: 'gpt-4o', capabilities: ['image']);

		$this->assertSame(['image'], $result);
		$this->assertSame(['openai/gpt-4o' => ['image']], json_decode($this->store['value'], true));
		$this->assertTrue($registry->supports(provider: 'openai', model: 'gpt-4o', capability: 'image'));
		$this->assertFalse($registry->supports(provider: 'openai', model: 'gpt-4o', capability: 'pdf'));
		$this->assertFalse($registry->supports(provider: 'openai', model: 'gpt-4o-mini', capability: 'image'));
	}//end testDeclaringImageStoresItUnderProviderSlashModel()

	/**
	 * A model id with slashes (Fireworks) round-trips; only the first slash splits.
	 *
	 * @return void
	 */
	public function testAModelIdWithSlashesRoundTrips(): void {
		$registry = $this->registry();
		$model = 'accounts/fireworks/models/llama-v3p2-11b-vision-instruct';

		$registry->declare(provider: 'fireworks', model: $model, capabilities: ['image']);

		$this->assertSame(['image'], $registry->forModel(provider: 'fireworks', model: $model));
		$this->assertSame(['fireworks/' . $model => ['image']], $registry->all());
	}//end testAModelIdWithSlashesRoundTrips()

	/**
	 * Capabilities come back deduplicated and in one order, whatever was sent.
	 *
	 * @return void
	 */
	public function testCapabilitiesAreDeduplicatedInCanonicalOrder(): void {
		$registry = $this->registry();

		$result = $registry->declare(provider: 'anthropic', model: 'claude-sonnet-5', capabilities: ['pdf', 'image', 'pdf']);

		$this->assertSame(['image', 'pdf'], $result);
	}//end testCapabilitiesAreDeduplicatedInCanonicalOrder()

	/**
	 * Declaring neither is kept as an explicit empty list, and clears an earlier tick.
	 *
	 * @return void
	 */
	public function testDeclaringNeitherClearsAnEarlierDeclaration(): void {
		$registry = $this->registry('{"ollama/qwen3:8b":["image"]}');

		$registry->declare(provider: 'ollama', model: 'qwen3:8b', capabilities: []);

		$this->assertSame(['ollama/qwen3:8b' => []], $registry->all());
		$this->assertFalse($registry->supports(provider: 'ollama', model: 'qwen3:8b', capability: 'image'));
	}//end testDeclaringNeitherClearsAnEarlierDeclaration()

	/**
	 * A capability outside image and pdf is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnknownCapabilityIsRefused(): void {
		$registry = $this->registry();

		try {
			$registry->declare(provider: 'openai', model: 'gpt-4o', capabilities: ['audio']);
			$this->fail('An unknown capability was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('audio', $e->getMessage());
		}

		$this->assertSame(0, $this->store['writes']);
	}//end testAnUnknownCapabilityIsRefused()

	/**
	 * An unsupported provider and an empty model are refused.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderOrEmptyModelIsRefused(): void {
		$registry = $this->registry();

		foreach ([['bogus', 'x'], ['openai', '   ']] as [$provider, $model]) {
			try {
				$registry->declare(provider: $provider, model: $model, capabilities: ['image']);
				$this->fail("'{$provider}/{$model}' was accepted.");
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

		$this->assertSame(0, $this->store['writes']);
	}//end testAnUnknownProviderOrEmptyModelIsRefused()

	/**
	 * A map with one bad entry writes nothing: the declarations land together or not at all.
	 *
	 * @return void
	 */
	public function testDeclareManyWritesNothingWhenOneEntryIsBad(): void {
		$registry = $this->registry();

		try {
			$registry->declareMany(declarations: ['openai/gpt-4o' => ['image'], 'ollama/llava' => ['video']]);
			$this->fail('A bad entry was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('video', $e->getMessage());
		}

		$this->assertSame(0, $this->store['writes']);
		$this->assertSame([], $registry->all());
	}//end testDeclareManyWritesNothingWhenOneEntryIsBad()

	/**
	 * DeclareMany merges into what is stored and leaves other models alone.
	 *
	 * @return void
	 */
	public function testDeclareManyMergesAndKeepsOtherModels(): void {
		$registry = $this->registry('{"anthropic/claude-sonnet-5":["image","pdf"]}');

		$result = $registry->declareMany(declarations: ['ollama/llava:13b' => ['image']]);

		$this->assertSame(
			['anthropic/claude-sonnet-5' => ['image', 'pdf'], 'ollama/llava:13b' => ['image']],
			$result
		);
		$this->assertSame(1, $this->store['writes']);
	}//end testDeclareManyMergesAndKeepsOtherModels()

	/**
	 * A key without a slash cannot name a provider and a model, so it is refused.
	 *
	 * @return void
	 */
	public function testAKeyWithoutASlashIsRefused(): void {
		$registry = $this->registry();

		$this->expectException(InvalidArgumentException::class);
		$registry->normalizeDeclarations(declarations: ['gpt-4o' => ['image']]);
	}//end testAKeyWithoutASlashIsRefused()

	/**
	 * A broken stored value reads as nothing declared, and stray values are dropped.
	 *
	 * @return void
	 */
	public function testAStoredValueIsReadDefensively(): void {
		$this->assertSame([], $this->registry('not json')->all());

		$registry = $this->registry('{"openai/gpt-4o":["image","audio"],"ollama/x":"image"}');

		$this->assertSame(['openai/gpt-4o' => ['image'], 'ollama/x' => []], $registry->all());
		$this->assertSame([], $registry->forModel(provider: 'ollama', model: 'x'));
	}//end testAStoredValueIsReadDefensively()
}//end class
