<?php

/**
 * Unit tests for ProviderCooldown (models-several-models-per-turn).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-4-cooldown-and-the-attempts-on-the-run-record
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use OCA\Hermiq\Service\Llm\ProviderCooldown;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProviderCooldownTest extends TestCase {

	/**
	 * The fake cache: key => [value, ttl], with a fake clock for expiry.
	 *
	 * @var array<string, array{0: mixed, 1: int, 2: int}>
	 */
	private array $entries = [];

	/**
	 * The fake clock, in seconds.
	 *
	 * @var int
	 */
	private int $now = 1000;

	/**
	 * A cooldown over a fake cache whose entries expire on the fake clock.
	 *
	 * @return ProviderCooldown
	 */
	private function cooldown(): ProviderCooldown {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->entries[$key] = [$value, $ttl, $this->now];
				return true;
			}
		);
		$cache->method('get')->willReturnCallback(
			function (string $key): mixed {
				$entry = ($this->entries[$key] ?? null);
				if ($entry === null || $this->now >= $entry[2] + $entry[1]) {
					return null;
				}

				return $entry[0];
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->with('hermiq_provider_cooldown')->willReturn($cache);

		return new ProviderCooldown($factory);
	}//end cooldown()

	/**
	 * A 429 with retry-after 30 cools the provider for that organisation for 30 seconds, not for another.
	 *
	 * @return void
	 */
	public function testARateLimitCoolsTheProviderForItsRetryAfter(): void {
		$cooldown = $this->cooldown();

		$seconds = $cooldown->startFor(organisation: 'org-1', provider: 'anthropic', failure: ProviderCallFailedException::fromHttpStatus(status: 429, message: 'x', retryAfter: '30'));
		$this->now += 10;

		$this->assertSame(30, $seconds);
		$this->assertTrue($cooldown->isCooling(organisation: 'org-1', provider: 'anthropic'));
		$this->assertFalse($cooldown->isCooling(organisation: 'org-2', provider: 'anthropic'));
		$this->assertFalse($cooldown->isCooling(organisation: 'org-1', provider: 'ollama'));

		$this->now += 20;
		$this->assertFalse($cooldown->isCooling(organisation: 'org-1', provider: 'anthropic'));
	}//end testARateLimitCoolsTheProviderForItsRetryAfter()

	/**
	 * Without retry-after a server error cools for 60 seconds; a long retry-after is capped at 600.
	 *
	 * @return void
	 */
	public function testTheDefaultAndTheCap(): void {
		$cooldown = $this->cooldown();

		$this->assertSame(60, $cooldown->startFor(organisation: 'o', provider: 'fireworks', failure: ProviderCallFailedException::fromHttpStatus(status: 500, message: 'x')));
		$this->assertSame(600, $cooldown->startFor(organisation: 'o', provider: 'openai', failure: ProviderCallFailedException::fromHttpStatus(status: 429, message: 'x', retryAfter: '3600')));
		$this->assertSame(600, $this->entries['fallback:o:openai'][1]);
	}//end testTheDefaultAndTheCap()

	/**
	 * A timeout or an auth failure starts no cooldown.
	 *
	 * @return void
	 */
	public function testOnlyARateLimitOrAServerErrorCools(): void {
		$cooldown = $this->cooldown();

		$this->assertSame(0, $cooldown->startFor(organisation: 'o', provider: 'openai', failure: ProviderCallFailedException::fromHttpStatus(status: 401, message: 'x')));
		$this->assertSame(0, $cooldown->startFor(organisation: 'o', provider: 'openai', failure: new ProviderCallFailedException(message: 'x', kind: ProviderCallFailedException::TIMEOUT)));
		$this->assertSame([], $this->entries);
	}//end testOnlyARateLimitOrAServerErrorCools()

	/**
	 * Without a distributed cache nothing cools down.
	 *
	 * @return void
	 */
	public function testWithoutACacheNothingCools(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new RuntimeException('no cache'));
		$cooldown = new ProviderCooldown($factory);

		$this->assertSame(0, $cooldown->startFor(organisation: 'o', provider: 'anthropic', failure: ProviderCallFailedException::fromHttpStatus(status: 429, message: 'x')));
		$this->assertFalse($cooldown->isCooling(organisation: 'o', provider: 'anthropic'));
	}//end testWithoutACacheNothingCools()
}//end class
