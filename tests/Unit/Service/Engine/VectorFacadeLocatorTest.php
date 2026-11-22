<?php

/**
 * Tests for VectorFacadeLocator: OpenRegister's vector facade found once, behind
 * a class probe, and its embedding backend read without ever throwing (vector-rag).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\VectorFacadeLocator;
use OCA\OpenRegister\Service\Search\VectorSearchFacadeStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolution once, the three ways it yields nothing, and the backend check.
 */
#[CoversClass(VectorFacadeLocator::class)]
final class VectorFacadeLocatorTest extends TestCase {

	/**
	 * The facade is taken from the container on first use and reused after.
	 *
	 * @return void
	 */
	public function testTheFacadeIsResolvedOnceAndReused(): void {
		$facade    = new VectorSearchFacadeStub();
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(VectorSearchFacadeStub::class)->willReturn($facade);

		$locator = $this->locator(container: $container, facadeClass: VectorSearchFacadeStub::class);

		$this->assertSame(expected: $facade, actual: $locator->facade());
		$this->assertSame(expected: $facade, actual: $locator->facade());
	}//end testTheFacadeIsResolvedOnceAndReused()

	/**
	 * An OpenRegister without the facade class answers null and the container is never asked.
	 *
	 * @return void
	 */
	public function testAMissingFacadeClassAnswersNullWithoutAskingTheContainer(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$locator = $this->locator(container: $container, facadeClass: 'OCA\\OpenRegister\\Service\\Search\\NoSuchFacade');

		$this->assertNull(actual: $locator->facade());
	}//end testAMissingFacadeClassAnswersNullWithoutAskingTheContainer()

	/**
	 * A container that throws answers null once, logs a line naming the caller, and is not asked again.
	 *
	 * @return void
	 */
	public function testAFailedResolutionIsLoggedUnderTheCallersNameAndRemembered(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())->method('get')->willThrowException(new RuntimeException('not registered'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'[SomeCaller] the OpenRegister vector facade could not be resolved',
			['error' => 'not registered']
		);

		$locator = new VectorFacadeLocator(
			container: $container,
			logger: $logger,
			facadeClass: VectorSearchFacadeStub::class,
			caller: 'SomeCaller'
		);

		$this->assertNull(actual: $locator->facade());
		$this->assertNull(actual: $locator->facade());
	}//end testAFailedResolutionIsLoggedUnderTheCallersNameAndRemembered()

	/**
	 * A container entry that is not an object is no facade.
	 *
	 * @return void
	 */
	public function testANonObjectEntryIsNoFacade(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn('not a facade');

		$this->assertNull(actual: $this->locator(container: $container, facadeClass: VectorSearchFacadeStub::class)->facade());
	}//end testANonObjectEntryIsNoFacade()

	/**
	 * The backend check reads `isAvailable()`, and a facade without it, or one that throws, has none.
	 *
	 * @return void
	 */
	public function testTheBackendCheckNeverThrows(): void {
		$available = new VectorSearchFacadeStub();
		$available->available = true;
		$unavailable = new VectorSearchFacadeStub();
		$unavailable->available = false;
		$throwing = new class {

			/**
			 * A backend check that fails.
			 *
			 * @return bool
			 */
			public function isAvailable(): bool {
				throw new RuntimeException('backend down');
			}//end isAvailable()
		};

		$this->assertTrue(condition: VectorFacadeLocator::isAvailable(facade: $available));
		$this->assertFalse(condition: VectorFacadeLocator::isAvailable(facade: $unavailable));
		$this->assertFalse(condition: VectorFacadeLocator::isAvailable(facade: $throwing));
		$this->assertFalse(condition: VectorFacadeLocator::isAvailable(facade: new \stdClass()));
	}//end testTheBackendCheckNeverThrows()

	/**
	 * A locator over the given container, with a logger that is not asserted on.
	 *
	 * @param ContainerInterface $container   The container.
	 * @param string             $facadeClass The facade class.
	 *
	 * @return VectorFacadeLocator
	 */
	private function locator(ContainerInterface $container, string $facadeClass): VectorFacadeLocator {
		return new VectorFacadeLocator(
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			facadeClass: $facadeClass,
			caller: 'Test'
		);
	}//end locator()
}//end class
