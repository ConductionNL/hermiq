<?php

/**
 * Characterisation of IntakeToolGrant's catalogue filter: which descriptors an
 * annotation admits, what is skipped silently, what is skipped with a warning,
 * and what an unreadable catalogue logs.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Intake
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

namespace OCA\Hermiq\Tests\Unit\Service\Intake;

use OCA\Hermiq\Service\Intake\IntakeToolGrant;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class IntakeToolGrantCatalogueTest extends TestCase {

	/**
	 * Log lines: [level, message].
	 *
	 * @var array<int, array{0: mixed, 1: string}>
	 */
	private array $logs = [];

	public function testReviewsNeedOnlyTheMarkSetToTrue(): void {
		$catalog = [
			'not a descriptor',
			['name' => 'a_review', 'annotations' => ['externalReview' => true]],
			['name' => 'b_review', 'annotations' => ['externalReview' => 1]],
			['name' => 'c_review', 'annotations' => 'externalReview'],
			['annotations' => ['externalReview' => true]],
			['mcpId' => 'd.review', 'name' => 'd_review', 'annotations' => ['externalReview' => true, 'citizenIntake' => true]],
			['name' => 'e_intake', 'annotations' => ['citizenIntake' => true]],
		];

		$ids = array_map(static fn (array $d): string => IntakeToolGrant::idOf(descriptor: $d), $this->grant(catalog: $catalog)->reviewTools());

		self::assertSame(['a_review', 'd.review'], $ids);
		self::assertSame([], $this->logs);
	}//end testReviewsNeedOnlyTheMarkSetToTrue()

	public function testEachMarkedButNotCreateOnlyIntakeToolIsWarnedAboutInOrder(): void {
		$catalog = [
			['mcpId' => 'x.update', 'scope' => 'update', 'action' => 'update', 'annotations' => ['citizenIntake' => true]],
			['mcpId' => 'x.file', 'scope' => 'create', 'action' => 'create', 'annotations' => ['citizenIntake' => true]],
			['mcpId' => 'x.reopen', 'scope' => 'create', 'action' => 'reopen', 'annotations' => ['citizenIntake' => true]],
			['mcpId' => 'x.unmarked', 'scope' => 'update', 'action' => 'update'],
			['scope' => 'update', 'annotations' => ['citizenIntake' => true]],
		];

		$tools = $this->grant(catalog: $catalog)->intakeTools();

		self::assertSame(['x.file'], array_map(static fn (array $d): string => IntakeToolGrant::idOf(descriptor: $d), $tools));
		self::assertSame(
			[
				['warning', "Hermiq ignored 'x.update' on the intake surface: it is annotated for intake but it is not a create-only tool."],
				['warning', "Hermiq ignored 'x.reopen' on the intake surface: it is annotated for intake but it is not a create-only tool."],
			],
			$this->logs
		);
	}//end testEachMarkedButNotCreateOnlyIntakeToolIsWarnedAboutInOrder()

	public function testAnUnreadableCatalogueIsLoggedOnce(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects(self::once())->method('listTools')->with([])->willThrowException(new RuntimeException('down'));

		self::assertSame([], (new IntakeToolGrant($facade, $this->logger()))->reviewTools());
		self::assertSame([['warning', 'Hermiq could not read the tool catalogue for the intake surface: down']], $this->logs);
	}//end testAnUnreadableCatalogueIsLoggedOnce()

	/**
	 * The grant over a catalogue.
	 *
	 * @param array<int, mixed> $catalog What listTools returns.
	 *
	 * @return IntakeToolGrant
	 */
	private function grant(array $catalog): IntakeToolGrant {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($catalog);
		return new IntakeToolGrant($facade, $this->logger());
	}//end grant()

	private function logger(): AbstractLogger {
		$logs = &$this->logs;
		return new class($logs) extends AbstractLogger {
			public function __construct(private array &$logs) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message];
			}
		};
	}//end logger()
}//end class
