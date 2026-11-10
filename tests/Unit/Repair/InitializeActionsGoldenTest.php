<?php

/**
 * Golden tests for the InitializeActions repair step.
 *
 * Pins the allowed and refused outcomes of seeding the action-authorization matrix:
 * an existing matrix (one entry, several) is preserved and never overwritten, an
 * empty one is seeded with exactly the actions in lib/actions.seed.json, and a write
 * that fails leaves the matrix as it was with a warning.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use JsonException;
use OCA\Hermiq\Repair\InitializeActions;
use OCA\Hermiq\Service\ActionAuthService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the action-matrix seed.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class InitializeActionsGoldenTest extends TestCase {

	/**
	 * Every output line, as "channel: message".
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * An IOutput that records info and warning lines.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->lines[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->lines[] = 'warning: ' . $message;
			}
		);
		return $output;
	}//end recordingOutput()

	/**
	 * The actions the shipped seed file declares.
	 *
	 * @return array<string, mixed>
	 */
	private function seedActions(): array {
		$parsed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/actions.seed.json'), true);
		return $parsed['actions'];
	}//end seedActions()

	/**
	 * An existing matrix is preserved, never overwritten, for one entry and several.
	 *
	 * @return void
	 */
	public function testAnExistingMatrixIsPreserved(): void {
		foreach ([1 => 'entry', 3 => 'entries'] as $size => $word) {
			$auth = $this->createMock(ActionAuthService::class);
			$auth->method('getMatrix')->willReturn(array_fill(0, $size, ['allow' => ['admin']]));
			$auth->expects($this->never())->method('setMatrix');

			$this->lines = [];
			(new InitializeActions($auth, $this->createMock(LoggerInterface::class)))->run($this->recordingOutput());

			$this->assertSame(['info: Action matrix already has ' . $size . ' ' . $word . ' — preserving.'], $this->lines);
		}
	}//end testAnExistingMatrixIsPreserved()

	/**
	 * An empty matrix is seeded with exactly the shipped actions.
	 *
	 * @return void
	 */
	public function testAnEmptyMatrixIsSeededWithTheShippedActions(): void {
		$expected = $this->seedActions();
		$written = null;
		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('getMatrix')->willReturn([]);
		$auth->expects($this->once())->method('setMatrix')->willReturnCallback(
			function (array $matrix) use (&$written): void {
				$written = $matrix;
			}
		);

		(new InitializeActions($auth, $this->createMock(LoggerInterface::class)))->run($this->recordingOutput());

		$this->assertSame($expected, $written);
		$this->assertSame(
			['info: Seeded action matrix with ' . count($expected) . ' actions (default: admin-only).'],
			$this->lines
		);
	}//end testAnEmptyMatrixIsSeededWithTheShippedActions()

	/**
	 * A write that fails is reported and nothing is claimed seeded.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReported(): void {
		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('getMatrix')->willReturn([]);
		$auth->method('setMatrix')->willThrowException(new JsonException('depth'));

		(new InitializeActions($auth, $this->createMock(LoggerInterface::class)))->run($this->recordingOutput());

		$this->assertSame(['warning: Failed to write matrix: depth'], $this->lines);
	}//end testAFailedWriteIsReported()
}//end class
