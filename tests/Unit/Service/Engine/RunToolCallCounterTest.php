<?php

/**
 * Tests RunToolCallCounter: the tool call cap on the CLI runner's MCP path.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\RunToolCallCounter;
use OCP\IMemcache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * On the CLI runner every tool call is its own MCP request, so the count of a
 * turn lives in the distributed cache under the run id.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
 */
class RunToolCallCounterTest extends TestCase {

	/**
	 * A counter over an in-memory cache double.
	 *
	 * @param bool $available Whether a distributed cache is configured.
	 *
	 * @return RunToolCallCounter
	 */
	private function counter(bool $available = true): RunToolCallCounter {
		$store = [];
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturnCallback(
			static function (string $key, mixed $value) use (&$store): bool {
				if (isset($store[$key]) === true) {
					return false;
				}

				$store[$key] = $value;
				return true;
			}
		);
		$cache->method('inc')->willReturnCallback(
			static function (string $key) use (&$store): int {
				$store[$key] = ((int)($store[$key] ?? 0) + 1);
				return $store[$key];
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($available);
		$factory->method('createDistributed')->willReturn($cache);

		return new RunToolCallCounter(cacheFactory: $factory);

	}//end counter()

	/**
	 * A cap of three admits three calls of one run and refuses the fourth; another run counts on its own.
	 *
	 * @return void
	 */
	public function testTheFourthCallOfARunPastACapOfThreeIsRefused(): void {
		$counter = $this->counter();

		$this->assertTrue($counter->admit(runId: 'run-a', cap: 3));
		$this->assertTrue($counter->admit(runId: 'run-a', cap: 3));
		$this->assertTrue($counter->admit(runId: 'run-a', cap: 3));
		$this->assertFalse($counter->admit(runId: 'run-a', cap: 3));
		$this->assertTrue($counter->admit(runId: 'run-b', cap: 3));

	}//end testTheFourthCallOfARunPastACapOfThreeIsRefused()

	/**
	 * Without a run id or a distributed cache nothing can be counted, so nothing is refused.
	 *
	 * @return void
	 */
	public function testNothingToCountAdmits(): void {
		$this->assertTrue($this->counter()->admit(runId: '', cap: 1));
		$none = $this->counter(available: false);
		$this->assertTrue($none->admit(runId: 'run-a', cap: 1));
		$this->assertTrue($none->admit(runId: 'run-a', cap: 1));

	}//end testNothingToCountAdmits()
}//end class
