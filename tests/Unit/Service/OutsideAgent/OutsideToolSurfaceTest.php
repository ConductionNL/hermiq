<?php

/**
 * Hermiq OutsideToolSurface unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\OutsideAgent
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

namespace OCA\Hermiq\Tests\Unit\Service\OutsideAgent;

use OCA\Hermiq\Service\OutsideAgent\OutsideToolSurface;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The surface offers only what an owning app declared reachable from outside.
 *
 * @covers \OCA\Hermiq\Service\OutsideAgent\OutsideToolSurface
 */
class OutsideToolSurfaceTest extends TestCase {

	/**
	 * A surface over a facade that returns the given catalogue.
	 *
	 * @param array<int, mixed> $catalog The catalogue.
	 *
	 * @return OutsideToolSurface The surface.
	 */
	private function surface(array $catalog): OutsideToolSurface {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects(self::atLeastOnce())
			->method('listTools')
			->with([])
			->willReturn($catalog);

		return new OutsideToolSurface(toolRegistryFacade: $facade, logger: new NullLogger());
	}//end surface()

	/**
	 * A mixed catalogue.
	 *
	 * @return array<int, mixed> The catalogue.
	 */
	private function catalog(): array {
		return [
			['name' => 'dossiq.case.get', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
			['id' => 'dossiq.case.update', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
			['name' => 'dossiq.case.delete', 'annotations' => []],
			['name' => 'dossiq.case.list', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => 'true']],
			['name' => 'dossiq.case.find', 'annotations' => 'outsideAgent'],
			['name' => 'dossiq.case.count'],
			['name' => 'hermiq.webFetch', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
			['name' => '', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
			'not-a-descriptor',
		];
	}//end catalog()

	/**
	 * Only descriptors declared reachable with a literal true are offered, by name
	 * or by id, never hermiq's own and never a nameless or non-array entry.
	 *
	 * @return void
	 */
	public function testOnlyDeclaredToolsAreOffered(): void {
		$offered = $this->surface($this->catalog())->declaredTools();

		self::assertSame(
			[
				['name' => 'dossiq.case.get', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
				['id' => 'dossiq.case.update', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
			],
			$offered
		);
	}//end testOnlyDeclaredToolsAreOffered()

	/**
	 * An unreadable catalogue offers nothing and is logged as a warning.
	 *
	 * @return void
	 */
	public function testAnUnreadableCatalogueOffersNothing(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willThrowException(new RuntimeException('catalogue down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(
				'Hermiq could not read the tool catalogue for the outside surface: catalogue down',
				self::callback(static fn (array $ctx): bool => ($ctx['exception'] instanceof RuntimeException))
			);

		$surface = new OutsideToolSurface(toolRegistryFacade: $facade, logger: $logger);

		self::assertSame([], $surface->declaredTools());
	}//end testAnUnreadableCatalogueOffersNothing()

	/**
	 * Describe finds an offered tool by name or id.
	 *
	 * @return void
	 */
	public function testDescribeFindsAnOfferedTool(): void {
		$surface = $this->surface($this->catalog());

		self::assertSame(
			['name' => 'dossiq.case.get', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
			$surface->describe('dossiq.case.get')
		);
		self::assertSame(
			['id' => 'dossiq.case.update', 'annotations' => [OutsideToolSurface::OUTSIDE_ANNOTATION => true]],
			$surface->describe('dossiq.case.update')
		);
	}//end testDescribeFindsAnOfferedTool()

	/**
	 * Describe returns null for a tool present in the catalogue but not offered.
	 *
	 * @return void
	 */
	public function testDescribeReturnsNullForAnUnofferedTool(): void {
		$surface = $this->surface($this->catalog());

		self::assertNull($surface->describe('dossiq.case.delete'));
		self::assertNull($surface->describe('hermiq.webFetch'));
		self::assertNull($surface->describe('nothing.at.all'));
	}//end testDescribeReturnsNullForAnUnofferedTool()

	/**
	 * Writes defers to OpenRegister's classification: hints first, then the verb.
	 *
	 * @return void
	 */
	public function testWritesFollowsTheSharedClassification(): void {
		$surface = new OutsideToolSurface(
			toolRegistryFacade: $this->createMock(ToolRegistryFacade::class),
			logger: new NullLogger()
		);

		self::assertTrue($surface->writes('dossiq.case.update'));
		self::assertTrue($surface->writes('dossiq.case.delete'));
		self::assertFalse($surface->writes('dossiq.case.get'));
		self::assertFalse($surface->writes('dossiq.case.update', ['scope' => 'read']));
		self::assertTrue($surface->writes('dossiq.case.get', ['scope' => 'create']));
		self::assertTrue($surface->writes('unclassifiable'));
	}//end testWritesFollowsTheSharedClassification()
}//end class
