<?php

/**
 * Unit tests for how InitializeActions reads its seed: malformed JSON, a seed
 * without an `actions` object, and the singular seeded message.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\InitializeActions;
use OCA\Hermiq\Service\ActionMatrix;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for InitializeActions' seed parsing.
 *
 * @covers \OCA\Hermiq\Repair\InitializeActions
 */
class InitializeActionsSeedParsingTest extends TestCase {

	/**
	 * Parse $raw through the step's private seed parser.
	 *
	 * @param string          $raw    The seed file contents.
	 * @param IOutput         $output The repair output.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return array<string, mixed>|null
	 */
	private function parse(string $raw, IOutput $output, LoggerInterface $logger): ?array {
		$step = new InitializeActions($this->createMock(ActionMatrix::class), $logger);
		return (new ReflectionMethod(InitializeActions::class, 'actionsIn'))->invoke($step, $raw, $output);
	}//end parse()

	/**
	 * The step names the matrix it initialises.
	 *
	 * @return void
	 */
	public function testNameNamesTheMatrix(): void {
		$step = new InitializeActions($this->createMock(ActionMatrix::class), $this->createMock(LoggerInterface::class));
		$this->assertSame('Initialize action-authorization matrix (ADR-023)', $step->getName());
	}//end testNameNamesTheMatrix()

	/**
	 * A well-formed seed yields its actions unchanged.
	 *
	 * @return void
	 */
	public function testAWellFormedSeedYieldsItsActions(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		$this->assertSame(
			['agent.create' => ['allow' => ['admin']]],
			$this->parse('{"actions":{"agent.create":{"allow":["admin"]}}}', $output, $this->createMock(LoggerInterface::class))
		);
	}//end testAWellFormedSeedYieldsItsActions()

	/**
	 * Malformed JSON is warned about and logged as an error, and yields null.
	 *
	 * @return void
	 */
	public function testMalformedJsonIsReportedAndYieldsNull(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('actions.seed.json invalid JSON: Syntax error');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[hermiq] ADR-023 seed malformed: Syntax error');

		$this->assertNull($this->parse('{"actions":', $output, $logger));
	}//end testMalformedJsonIsReportedAndYieldsNull()

	/**
	 * A seed without an `actions` object (absent or not an object) yields null.
	 *
	 * @return void
	 */
	public function testASeedWithoutAnActionsObjectYieldsNull(): void {
		foreach (['{"other":{}}', '{"actions":"all"}'] as $raw) {
			$output = $this->createMock(IOutput::class);
			$output->expects($this->once())->method('warning')
				->with('actions.seed.json missing `actions` object — matrix left empty.');

			$this->assertNull($this->parse($raw, $output, $this->createMock(LoggerInterface::class)), $raw);
		}
	}//end testASeedWithoutAnActionsObjectYieldsNull()

	/**
	 * One seeded action reads in the singular.
	 *
	 * @return void
	 */
	public function testOneSeededActionReadsInTheSingular(): void {
		$step = new InitializeActions($this->createMock(ActionMatrix::class), $this->createMock(LoggerInterface::class));
		$message = new ReflectionMethod(InitializeActions::class, 'seededMessage');

		$this->assertSame('Seeded action matrix with 1 action (default: admin-only).', $message->invoke($step, 1));
		$this->assertSame('Seeded action matrix with 0 actions (default: admin-only).', $message->invoke($step, 0));
	}//end testOneSeededActionReadsInTheSingular()
}//end class
