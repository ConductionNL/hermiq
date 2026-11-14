<?php

/**
 * Every run writer hands its run to trace export.
 *
 * Scans lib/ for audit writes with the action `run` or `agent-run` and asserts each such
 * file also hands the run to the trace export queue, so a new writer cannot silently
 * skip export. The chat turn's end in the engine is checked the same way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\TraceExport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\TraceExport;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A source scan over the run writers.
 */
#[CoversNothing]
final class RunWritersEnqueueTest extends TestCase {

	/**
	 * Each file that writes a `run` or `agent-run` audit entry calls `enqueueRun()`.
	 *
	 * @return void
	 */
	public function testEveryRunWriterEnqueues(): void {
		$lib = dirname(__DIR__, 4) . '/lib';
		$writers = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (preg_match("/recordObjectEvent\\([^;]*action:\\s*'(run|agent-run)'/s", $source) === 1) {
				$writers[] = substr($file->getPathname(), strlen($lib) + 1);
				self::assertStringContainsString(
					'traceExportQueue?->enqueueRun(',
					$source,
					$file->getPathname() . ' writes a run audit entry but does not hand the run to trace export.'
				);
			}
		}

		self::assertContains('Service/ScheduleService.php', $writers);
		self::assertContains('Service/FlowAgentRunService.php', $writers);
		self::assertContains('Service/WebhookAgentRunService.php', $writers);

	}//end testEveryRunWriterEnqueues()

	/**
	 * The engine hands a finished chat turn to the queue.
	 *
	 * @return void
	 */
	public function testTheChatTurnEnqueues(): void {
		$engine = (string)file_get_contents(dirname(__DIR__, 4) . '/lib/Service/Engine/Engine.php');

		self::assertStringContainsString('->enqueueChatTurn(', $engine);
		self::assertStringContainsString('$this->exportTurn(turn: $turn, envelope: $envelope, timings: $timings);', $engine);

	}//end testTheChatTurnEnqueues()
}//end class
