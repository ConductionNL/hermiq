<?php

/**
 * Golden test of how both provider declaration registries read and write app config.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use InvalidArgumentException;
use OCA\Hermiq\Service\AiFeature\ProviderDataUseRegistry;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Reads, writes and refusals of both registries, with the exact config calls.
 *
 * @covers \OCA\Hermiq\Service\AiFeature\ProviderDataUseRegistry
 * @covers \OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry
 * @uses \OCA\Hermiq\Service\AiFeature\ProviderDeclarationStore
 *
 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
 */
class ProviderDeclarationStorageGoldenTest extends TestCase {

	/**
	 * Every config call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The stored value per config key.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * An app config over $values that records every call.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->calls[] = 'get ' . $app . ' ' . $key . ' [' . $default . ']';
				return ($this->values[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = 'set ' . $app . ' ' . $key . ' ' . preg_replace('/"declaredAt":"[^"]*"/', '"declaredAt":"T"', $value);
				$this->values[$key] = $value;
				return true;
			}
		);
		return $config;

	}//end appConfig()

	/**
	 * Unreadable, empty and odd stored maps read as the recorded results.
	 *
	 * @return void
	 */
	public function testOddStoredMapsRead(): void {
		$dataUse = new ProviderDataUseRegistry(appConfig: $this->appConfig());
		$residency = new ProviderResidencyRegistry(appConfig: $this->appConfig());
		$seen = [];
		foreach (['', '{oops', '"text"', '[]', '{"7":{"dataUse":"no-training","residency":"eu"},"openai":"flat","ollama":[]}'] as $raw) {
			$this->values = ['providerDataUse' => $raw, 'providerResidency' => $raw];
			$seen[] = [$dataUse->all(), $residency->all(), $dataUse->forProvider(provider: 'openai'), $residency->forProvider(provider: 'openai')];
		}

		$this->assertSame(
			expected: [
				[[], [], $this->undeclaredUse(provider: 'openai'), $this->undeclaredPlace(provider: 'openai')],
				[[], [], $this->undeclaredUse(provider: 'openai'), $this->undeclaredPlace(provider: 'openai')],
				[[], [], $this->undeclaredUse(provider: 'openai'), $this->undeclaredPlace(provider: 'openai')],
				[[], [], $this->undeclaredUse(provider: 'openai'), $this->undeclaredPlace(provider: 'openai')],
				[
					[
						7 => ['provider' => '7', 'dataUse' => 'no-training', 'termsReference' => '', 'declaredBy' => '', 'declaredAt' => ''],
						'openai' => $this->undeclaredUse(provider: 'openai'),
						'ollama' => $this->undeclaredUse(provider: 'ollama'),
					],
					[
						7 => ['provider' => '7', 'residency' => 'eu', 'location' => ''],
						'openai' => $this->undeclaredPlace(provider: 'openai'),
						'ollama' => $this->undeclaredPlace(provider: 'ollama'),
					],
					$this->undeclaredUse(provider: 'openai'),
					$this->undeclaredPlace(provider: 'openai'),
				],
			],
			actual: $seen
		);

	}//end testOddStoredMapsRead()

	/**
	 * Declaring writes the whole map back under each registry's own key.
	 *
	 * @return void
	 */
	public function testDeclaringWritesTheWholeMap(): void {
		$this->values = ['providerDataUse' => '{"ollama":{"dataUse":"may-train"}}', 'providerResidency' => '{"ollama":{"residency":"eu"}}'];
		$dataUse = new ProviderDataUseRegistry(appConfig: $this->appConfig());
		$residency = new ProviderResidencyRegistry(appConfig: $this->appConfig());

		$used = $dataUse->declare(provider: 'openai', dataUse: 'zero-retention', termsReference: ' DPA 4 ', declaredBy: 'admin');
		$placed = $residency->declareResidency(provider: 'openai', residency: 'outside-eu', location: ' Virginia ');

		$this->assertSame(expected: 'DPA 4', actual: $used['termsReference']);
		$this->assertSame(expected: ['provider' => 'openai', 'residency' => 'outside-eu', 'location' => 'Virginia'], actual: $placed);
		$this->assertSame(
			expected: [
				'get hermiq providerDataUse []',
				'set hermiq providerDataUse {"ollama":{"dataUse":"may-train"},'
					. '"openai":{"dataUse":"zero-retention","termsReference":"DPA 4","declaredBy":"admin","declaredAt":"T"}}',
				'get hermiq providerDataUse []',
				'get hermiq providerResidency []',
				'set hermiq providerResidency {"ollama":{"residency":"eu"},"openai":{"residency":"outside-eu","location":"Virginia"}}',
				'get hermiq providerResidency []',
			],
			actual: $this->calls
		);

	}//end testDeclaringWritesTheWholeMap()

	/**
	 * Every refusal carries its exact message and writes nothing.
	 *
	 * @return void
	 */
	public function testRefusalsCarryTheirExactMessages(): void {
		$dataUse = new ProviderDataUseRegistry(appConfig: $this->appConfig());
		$residency = new ProviderResidencyRegistry(appConfig: $this->appConfig());
		$messages = [];
		$attempts = [
			static fn () => $dataUse->declare(provider: 'mystery', dataUse: 'no-training', termsReference: '', declaredBy: 'a'),
			static fn () => $dataUse->declare(provider: 'openai', dataUse: 'maybe', termsReference: '', declaredBy: 'a'),
			static fn () => $residency->declareResidency(provider: 'mystery', residency: 'eu'),
			static fn () => $residency->declareResidency(provider: 'openai', residency: 'moon'),
		];
		foreach ($attempts as $attempt) {
			try {
				$attempt();
			} catch (InvalidArgumentException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$providers = 'openai, ollama, fireworks, nextcloud, anthropic';
		$this->assertSame(
			expected: [
				"Unsupported provider 'mystery', must be one of: " . $providers,
				"Unsupported data use 'maybe', must be one of: zero-retention, no-training, may-train",
				"Unsupported provider 'mystery' — must be one of: " . $providers,
				"Unsupported residency 'moon' — must be one of: on-premise, eu, outside-eu",
			],
			actual: $messages
		);
		$this->assertSame(expected: [], actual: $this->calls);

	}//end testRefusalsCarryTheirExactMessages()

	/**
	 * An undeclared data-use answer.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return array<string, string>
	 */
	private function undeclaredUse(string $provider): array {
		return ['provider' => $provider, 'dataUse' => 'undeclared', 'termsReference' => '', 'declaredBy' => '', 'declaredAt' => ''];

	}//end undeclaredUse()

	/**
	 * An undeclared residency answer.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return array<string, string>
	 */
	private function undeclaredPlace(string $provider): array {
		return ['provider' => $provider, 'residency' => 'undeclared', 'location' => ''];

	}//end undeclaredPlace()
}//end class
