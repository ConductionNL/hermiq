<?php

/**
 * Unit tests for RunsUnderSystemIdentity, the trait that runs a repair step's
 * work under OpenRegister's system identity.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair\Support
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
 * @spec openspec/specs/repair-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair\Support;

use OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Pins that the work runs exactly once: inside runAsSystem() when the service
 * offers it, and directly (falling through, never refusing) when it does not.
 *
 * @covers \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity
 *
 * @spec openspec/specs/repair-steps/spec.md
 */
class RunsUnderSystemIdentityTest extends TestCase {
	/**
	 * A repair-step-shaped host exposing the trait's method.
	 *
	 * @return object The host.
	 */
	private function host(): object {
		return new class () {
			use RunsUnderSystemIdentity;

			/**
			 * Expose withSystemIdentity() to the test.
			 *
			 * @param object|null $objectService The ObjectService, or null.
			 * @param callable    $work          The work.
			 *
			 * @return void
			 */
			public function run(?object $objectService, callable $work): void {
				$this->withSystemIdentity($objectService, $work);
			}
		};
	}

	/**
	 * A service offering runAsSystem() runs the work inside it, once.
	 *
	 * @return void
	 */
	public function testTheWorkRunsInsideRunAsSystemWhenOffered(): void {
		$service = new class () {
			/** @var boolean */
			public bool $elevated = false;

			/** @var integer */
			public int $calls = 0;

			/**
			 * @param callable $callback The work to run elevated.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $callback): void {
				$this->calls++;
				$this->elevated = true;
				$callback();
				$this->elevated = false;
			}
		};

		$seen = [];
		$this->host()->run($service, static function () use ($service, &$seen): void {
			$seen[] = $service->elevated;
		});

		$this->assertSame([true], $seen, 'The work must run once, while elevated.');
		$this->assertSame(1, $service->calls);
	}

	/**
	 * A service without runAsSystem() still gets the work run, directly and once.
	 *
	 * @return void
	 */
	public function testAServiceWithoutRunAsSystemFallsThrough(): void {
		$runs = 0;
		$this->host()->run(new \stdClass(), static function () use (&$runs): void {
			$runs++;
		});

		$this->assertSame(1, $runs);
	}

	/**
	 * No service at all still gets the work run, directly and once.
	 *
	 * @return void
	 */
	public function testNoServiceFallsThrough(): void {
		$runs = 0;
		$this->host()->run(null, static function () use (&$runs): void {
			$runs++;
		});

		$this->assertSame(1, $runs);
	}

	/**
	 * An exception from the work is not swallowed, elevated or not.
	 *
	 * @return void
	 */
	public function testAThrowingWorkPropagates(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('write refused');

		$this->host()->run(null, static function (): void {
			throw new \RuntimeException('write refused');
		});
	}
}
