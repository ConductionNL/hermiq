<?php

/**
 * Unit tests for ServiceRegistrar (the Service component's own seam bindings).
 *
 * Covers: the registrar delegates every Kernel contract binding to
 * ContractBindings, binds the governed workspace and run-token store seams to
 * their server-side implementations, and aliases the register-slug resolver
 * by string so no OpenRegister class is autoloaded at register() time.
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

use OCA\Hermiq\Service\ContractBindings;
use OCA\Hermiq\Service\Llm\DatabaseRunTokenStore;
use OCA\Hermiq\Service\Llm\RunTokenStore;
use OCA\Hermiq\Service\ServiceRegistrar;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceProvider;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ServiceRegistrar.
 */
#[CoversClass(ServiceRegistrar::class)]
#[UsesClass(ContractBindings::class)]
class ServiceRegistrarTest extends TestCase {

	/**
	 * Run a registrar against a recording context.
	 *
	 * @param object $registrar ServiceRegistrar or ContractBindings.
	 *
	 * @return array<int, array{0: string, 1: string}> The (alias, target) pairs in call order.
	 */
	private function record(object $registrar): array {
		$calls   = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$calls): void {
				$calls[] = [$alias, $target];
			}
		);
		$context->expects($this->never())->method('registerService');

		$registrar->register(context: $context);

		return $calls;
	}//end record()

	/**
	 * The contract bindings come first, followed by exactly the three seam aliases.
	 *
	 * @return void
	 */
	public function testRegistersContractBindingsThenTheThreeSeams(): void {
		$contracts = $this->record(new ContractBindings());
		$all       = $this->record(new ServiceRegistrar());

		$this->assertCount(count($contracts) + 3, $all);
		$this->assertSame($contracts, array_slice($all, 0, count($contracts)));
		$this->assertSame(
			[
				[WorkspaceProvider::class, ServerSideWorkspaceProvider::class],
				[RunTokenStore::class, DatabaseRunTokenStore::class],
				[RegisterSlugResolverInterface::class, 'OCA\\OpenRegister\\Service\\RegisterSlugResolver'],
			],
			array_slice($all, count($contracts))
		);
	}//end testRegistersContractBindingsThenTheThreeSeams()

	/**
	 * The seam targets are real implementations of their seams.
	 *
	 * @return void
	 */
	public function testSeamTargetsImplementTheirSeams(): void {
		$this->assertTrue(is_a(ServerSideWorkspaceProvider::class, WorkspaceProvider::class, true));
		$this->assertTrue(is_a(DatabaseRunTokenStore::class, RunTokenStore::class, true));
	}//end testSeamTargetsImplementTheirSeams()

	/**
	 * No alias is registered twice across both registrars.
	 *
	 * @return void
	 */
	public function testNoAliasIsRegisteredTwice(): void {
		$aliases = array_column($this->record(new ServiceRegistrar()), 0);

		$this->assertSame(array_values(array_unique($aliases)), $aliases);
	}//end testNoAliasIsRegisteredTwice()
}//end class
