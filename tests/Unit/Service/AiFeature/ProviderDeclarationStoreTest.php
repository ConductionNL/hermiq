<?php

/**
 * Tests for ProviderDeclarationStore.
 *
 * Pins the stored map read (unset, invalid JSON, a JSON scalar, a real map),
 * the per-provider read through the caller's reader, and that a write keeps
 * every other provider and stores under the registry's own key.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use OCA\Hermiq\Service\AiFeature\ProviderDeclarationStore;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One registry's stored declarations.
 */
#[CoversClass(ProviderDeclarationStore::class)]
final class ProviderDeclarationStoreTest extends TestCase {

	/**
	 * The app config value being read and written.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * Every write, as [app, key, value].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $writes = [];

	/**
	 * Every read, as [app, key, default].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $reads = [];

	/**
	 * A store over an in-memory app config.
	 *
	 * @param string $initial The initial stored value.
	 *
	 * @return ProviderDeclarationStore
	 */
	private function store(string $initial): ProviderDeclarationStore {
		$this->stored = $initial;
		$this->writes = [];
		$this->reads = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->reads[] = [$app, $key, $default];
				return $this->stored;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->writes[] = [$app, $key, $value];
				$this->stored = $value;
				return true;
			}
		);

		return new ProviderDeclarationStore($config, 'someRegistryKey');
	}//end store()

	/**
	 * Unset, unparsable and non-map values all read as an empty map.
	 *
	 * @return void
	 */
	public function testUnusableStoredValuesReadAsEmpty(): void {
		foreach (['', '{not json', '"a string"', '42', 'true', 'null'] as $raw) {
			$this->assertSame([], $this->store($raw)->stored(), 'raw value: ' . $raw);
		}

		$this->assertSame([['hermiq', 'someRegistryKey', '']], $this->reads);
	}//end testUnusableStoredValuesReadAsEmpty()

	/**
	 * A stored map is returned as decoded.
	 *
	 * @return void
	 */
	public function testStoredMapIsDecoded(): void {
		$store = $this->store('{"openai":{"dataUse":"no-training"},"local":{"region":"eu"}}');

		$this->assertSame(
			['openai' => ['dataUse' => 'no-training'], 'local' => ['region' => 'eu']],
			$store->stored()
		);
	}//end testStoredMapIsDecoded()

	/**
	 * Each stored provider is read through the given reader, keyed by provider id.
	 *
	 * @return void
	 */
	public function testEachReadsEveryProviderThroughTheReader(): void {
		$store = $this->store('{"openai":{"x":1},"0":{"y":2}}');
		$asked = [];

		$result = $store->each(
			static function (string $provider) use (&$asked): array {
				$asked[] = $provider;
				return ['provider' => $provider];
			}
		);

		$this->assertSame(['openai', '0'], $asked);
		$this->assertSame(['openai' => ['provider' => 'openai'], 0 => ['provider' => '0']], $result);
		$this->assertSame([], $this->store('')->each(static fn (string $p): array => ['never' => $p]));
	}//end testEachReadsEveryProviderThroughTheReader()

	/**
	 * A write replaces one provider and keeps the others, under the registry key.
	 *
	 * @return void
	 */
	public function testPutKeepsOtherProviders(): void {
		$store = $this->store('{"openai":{"dataUse":"training"},"local":{"region":"eu"}}');

		$store->put('openai', ['dataUse' => 'no-training']);
		$store->put('mistral', ['region' => 'fr']);

		$this->assertSame(
			[
				['hermiq', 'someRegistryKey', '{"openai":{"dataUse":"no-training"},"local":{"region":"eu"}}'],
				['hermiq', 'someRegistryKey', '{"openai":{"dataUse":"no-training"},"local":{"region":"eu"},"mistral":{"region":"fr"}}'],
			],
			$this->writes
		);
	}//end testPutKeepsOtherProviders()

	/**
	 * A write over an unusable stored value starts a fresh map.
	 *
	 * @return void
	 */
	public function testPutOverUnusableValueStartsFresh(): void {
		$store = $this->store('"garbage"');

		$store->put('local', ['region' => 'eu']);

		$this->assertSame('{"local":{"region":"eu"}}', $this->stored);
	}//end testPutOverUnusableValueStartsFresh()
}//end class
