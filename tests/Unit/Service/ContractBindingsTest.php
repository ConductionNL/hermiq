<?php

/**
 * Unit tests for ContractBindings (Kernel contract -> implementation aliases).
 *
 * Covers: every Kernel contract is aliased exactly once, each alias points at
 * a concrete class that actually implements the contract, and no contract in
 * lib/Kernel/Contracts is left without a binding (Nextcloud autowires classes
 * but not interfaces, so an unbound contract fails at the first injection).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\EngineInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\ContractBindings;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\ScheduleService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for ContractBindings.
 */
#[CoversClass(ContractBindings::class)]
class ContractBindingsTest extends TestCase {

	/**
	 * Run register() against a recording context.
	 *
	 * @return array<int, array{0: string, 1: string}> The (alias, target) pairs in call order.
	 */
	private function recordAliases(): array {
		$calls   = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$calls): void {
				$calls[] = [$alias, $target];
			}
		);
		$context->expects($this->never())->method('registerService');

		(new ContractBindings())->register(context: $context);

		return $calls;
	}//end recordAliases()

	/**
	 * Each contract is aliased exactly once.
	 *
	 * @return void
	 */
	public function testEveryContractIsAliasedExactlyOnce(): void {
		$aliases = array_column($this->recordAliases(), 0);

		$this->assertNotEmpty($aliases);
		$this->assertSame(array_values(array_unique($aliases)), $aliases);
	}//end testEveryContractIsAliasedExactlyOnce()

	/**
	 * Every alias is a Kernel contract interface and its target a concrete class implementing it.
	 *
	 * @return void
	 */
	public function testEveryTargetIsAConcreteImplementationOfItsContract(): void {
		foreach ($this->recordAliases() as [$alias, $target]) {
			$this->assertStringStartsWith('OCA\\Hermiq\\Kernel\\Contracts\\', $alias);
			$this->assertTrue(interface_exists($alias), $alias.' is not an interface');
			$this->assertStringStartsWith('OCA\\Hermiq\\Service\\', $target);
			$this->assertTrue(class_exists($target), $target.' does not exist');

			$reflection = new ReflectionClass($target);
			$this->assertFalse($reflection->isAbstract(), $target.' is abstract');
			$this->assertTrue($reflection->implementsInterface($alias), $target.' does not implement '.$alias);
		}
	}//end testEveryTargetIsAConcreteImplementationOfItsContract()

	/**
	 * No contract in lib/Kernel/Contracts is left unbound, and nothing outside it is bound.
	 *
	 * @return void
	 */
	public function testTheBoundSetIsExactlyTheKernelContractsDirectory(): void {
		$expected = [];
		foreach (glob(dirname(__DIR__, 3).'/lib/Kernel/Contracts/*.php') as $file) {
			$expected[] = 'OCA\\Hermiq\\Kernel\\Contracts\\'.basename($file, '.php');
		}

		$bound = array_column($this->recordAliases(), 0);
		sort($expected);
		sort($bound);

		$this->assertNotEmpty($expected);
		$this->assertSame($expected, $bound);
	}//end testTheBoundSetIsExactlyTheKernelContractsDirectory()

	/**
	 * Spot-check a few pairs so a swapped target is caught by name too.
	 *
	 * @return void
	 */
	public function testKnownContractsResolveToTheirServices(): void {
		$map = [];
		foreach ($this->recordAliases() as [$alias, $target]) {
			$map[$alias] = $target;
		}

		$this->assertSame(ApprovalService::class, $map[ApprovalServiceInterface::class]);
		$this->assertSame(ScheduleService::class, $map[ScheduleServiceInterface::class]);
		$this->assertSame(Engine::class, $map[EngineInterface::class]);
	}//end testKnownContractsResolveToTheirServices()
}//end class
