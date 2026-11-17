<?php

/**
 * Unit tests for ReportWriter (tools-deep-research-report task 4).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use DateTimeImmutable;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\Research\ReportWriter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Files, the tag manager and the URL generator are the boundaries; the folder
 * walk, the file name, the mark-or-remove rule and the message are asserted.
 *
 * @covers \OCA\Hermiq\Service\Research\ReportWriter
 */
final class ReportWriterTest extends TestCase {

	/**
	 * The report file.
	 *
	 * @var File&MockObject
	 */
	private File&MockObject $file;

	/**
	 * The folders made, by name.
	 *
	 * @var list<string>
	 */
	private array $made = [];

	/**
	 * The name and content the report was written with.
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private ?array $written = null;

	/**
	 * A user folder holding `Hermiq` but not `Onderzoek`.
	 *
	 * @return IRootFolder
	 */
	private function root(): IRootFolder {
		$this->file = $this->createMock(File::class);
		$this->file->method('getId')->willReturn(4711);

		$onderzoek = $this->createMock(Folder::class);
		$onderzoek->method('getNonExistingName')->willReturnCallback(static fn (string $name): string => $name);
		$onderzoek->method('newFile')->willReturnCallback(
			function (string $name, string $content): File {
				$this->written = [$name, $content];
				return $this->file;
			}
		);

		$hermiq = $this->createMock(Folder::class);
		$hermiq->method('nodeExists')->willReturnMap([['Onderzoek', false]]);
		$hermiq->method('newFolder')->willReturnCallback(
			function (string $name) use ($onderzoek): Folder {
				$this->made[] = $name;
				return $onderzoek;
			}
		);

		$user = $this->createMock(Folder::class);
		$user->method('nodeExists')->willReturnMap([['Hermiq', true]]);
		$user->method('get')->with('Hermiq')->willReturn($hermiq);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('beleid')->willReturn($user);
		return $root;
	}//end root()

	/**
	 * The writer over the root, with a marker that may fail.
	 *
	 * @param AgentArtefactMarker&MockObject $marker The marker.
	 *
	 * @return ReportWriter
	 */
	private function writer(AgentArtefactMarker&MockObject $marker): ReportWriter {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->with('files.viewcontroller.showFile', ['fileid' => 4711])->willReturn('https://cloud.example/f/4711');
		return new ReportWriter(rootFolder: $this->root(), marker: $marker, urls: $urls, logger: new NullLogger());
	}//end writer()

	/**
	 * The report lands in Hermiq/Onderzoek with the day and title in its name, marked, with a link.
	 *
	 * @return void
	 */
	public function testTheReportIsSavedMarkedAndLinked(): void {
		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->expects(self::once())->method('markFile')->with(4711);
		$writer = $this->writer(marker: $marker);
		$this->file->expects(self::never())->method('delete');

		$report = $writer->write(
			userId: 'beleid',
			title: 'Woningbouw / stikstof: wat zeggen "de bronnen"?',
			markdown: "# Woningbouw\n\nTekst.",
			date: new DateTimeImmutable('2026-11-14')
		);

		self::assertSame(['Onderzoek'], $this->made);
		self::assertSame(['2026-11-14 Woningbouw stikstof wat zeggen de bronnen.md', "# Woningbouw\n\nTekst."], $this->written);
		self::assertSame(
			[
				'fileId' => 4711,
				'name' => '2026-11-14 Woningbouw stikstof wat zeggen de bronnen.md',
				'path' => 'Hermiq/Onderzoek/2026-11-14 Woningbouw stikstof wat zeggen de bronnen.md',
				'url' => 'https://cloud.example/f/4711',
			],
			$report
		);
	}//end testTheReportIsSavedMarkedAndLinked()

	/**
	 * A failed mark removes the file and fails the run.
	 *
	 * @return void
	 */
	public function testAFailedMarkLeavesNoFileBehind(): void {
		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->method('markFile')->willThrowException(new RuntimeException('tag manager down'));
		$writer = $this->writer(marker: $marker);
		$this->file->expects(self::once())->method('delete');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(ReportWriter::NOT_MARKED);

		$writer->write(userId: 'beleid', title: 'Stikstof', markdown: 'x', date: new DateTimeImmutable('2026-11-14'));
	}//end testAFailedMarkLeavesNoFileBehind()

	/**
	 * A title with nothing usable in it still gives a name.
	 *
	 * @return void
	 */
	public function testAnEmptyTitleIsNamedResearchReport(): void {
		$marker = $this->createMock(AgentArtefactMarker::class);

		$report = $this->writer(marker: $marker)->write(userId: 'beleid', title: ' / ', markdown: 'x', date: new DateTimeImmutable('2026-11-14'));

		self::assertSame('2026-11-14 Research report.md', $report['name']);
	}//end testAnEmptyTitleIsNamedResearchReport()

	/**
	 * The message carries at most five summary lines, the counts and the link.
	 *
	 * @return void
	 */
	public function testTheMessageHasFiveLinesTheCountsAndTheLink(): void {
		$writer = new ReportWriter(
			rootFolder: $this->createMock(IRootFolder::class),
			marker: $this->createMock(AgentArtefactMarker::class),
			urls: $this->createMock(IURLGenerator::class),
			logger: new NullLogger()
		);

		$message = $writer->message(
			summary: ['Een', ' ', 'Twee', 'Drie', 'Vier', 'Vijf', 'Zes'],
			sources: 14,
			checked: 31,
			unverified: 2,
			report: ['name' => '2026-11-14 Stikstof.md', 'url' => 'https://cloud.example/f/4711']
		);

		self::assertSame(
			"Een\nTwee\nDrie\nVier\nVijf\n\n14 sources, 31 citations checked, 2 unverified\n\n[2026-11-14 Stikstof.md](https://cloud.example/f/4711)",
			$message
		);
	}//end testTheMessageHasFiveLinesTheCountsAndTheLink()
}//end class
