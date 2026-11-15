<?php

/**
 * Hermiq ReportWriter.
 *
 * Saves a research report as the requesting user into their own Files under
 * `Hermiq/Onderzoek`, marks it as agent-authored in the same operation, and
 * words the conversation message that links to it (design D5). A report that
 * cannot be marked is not left behind: the file is deleted and the run fails,
 * as NotesWriteService treats a written but unmarked note.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
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

namespace OCA\Hermiq\Service\Research;

use DateTimeInterface;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Writes the report file and the message that announces it.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004
 */
class ReportWriter {

	/**
	 * Where in the user's Files the reports go.
	 */
	public const FOLDER = 'Hermiq/Onderzoek';

	/**
	 * The refusal when the report could not be marked as agent-authored.
	 */
	public const NOT_MARKED = 'The report was written but could not be marked as agent-authored, so it was removed.';

	/**
	 * The longest title kept in a file name.
	 */
	private const TITLE_LENGTH = 80;

	/**
	 * Constructor.
	 *
	 * @param IRootFolder         $rootFolder The files root.
	 * @param AgentArtefactMarker $marker     Applies the "Agent authored" tag.
	 * @param IURLGenerator       $urls       Builds the link to the file.
	 * @param LoggerInterface     $logger     The logger.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly AgentArtefactMarker $marker,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Save the report and mark it, or leave nothing behind.
	 *
	 * @param string            $userId   The user who asked for the research.
	 * @param string            $title    The report's title.
	 * @param string            $markdown The checked and rendered report.
	 * @param DateTimeInterface $date     The day the run finished, for the file name.
	 *
	 * @return array{fileId: int, name: string, path: string, url: string} The saved report.
	 *
	 * @throws RuntimeException When the report cannot be marked; the file is removed first.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004
	 */
	public function write(string $userId, string $title, string $markdown, DateTimeInterface $date): array {
		$folder = $this->folderOf(userId: $userId);
		$name = $folder->getNonExistingName($date->format('Y-m-d') . ' ' . self::fileTitle(title: $title) . '.md');
		$file = $folder->newFile($name, $markdown);

		$this->markOrRemove(file: $file);

		$fileId = (int)$file->getId();
		return [
			'fileId' => $fileId,
			'name' => $name,
			'path' => self::FOLDER . '/' . $name,
			'url' => $this->urls->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]),
		];
	}//end write()

	/**
	 * The conversation message: the summary lines, the counts and the link.
	 *
	 * @param list<string>                     $summary    Up to five summary lines; more are cut.
	 * @param int                              $sources    The sources the report rests on.
	 * @param int                              $checked    The citations checked.
	 * @param int                              $unverified The citations that did not pass.
	 * @param array{url: string, name: string} $report     The saved report.
	 *
	 * @return string The message, in Markdown.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004
	 */
	public function message(array $summary, int $sources, int $checked, int $unverified, array $report): string {
		$lines = array_slice(array_values(array_filter(array_map('trim', $summary), static fn (string $line): bool => $line !== '')), 0, 5);
		$counts = sprintf('%d sources, %d citations checked, %d unverified', $sources, $checked, $unverified);

		return implode("\n", $lines) . "\n\n" . $counts . "\n\n" . '[' . $report['name'] . '](' . $report['url'] . ')';
	}//end message()

	/**
	 * The user's report folder, made when it is missing.
	 *
	 * @param string $userId The user.
	 *
	 * @return Folder
	 */
	private function folderOf(string $userId): Folder {
		$folder = $this->rootFolder->getUserFolder($userId);
		foreach (explode('/', self::FOLDER) as $segment) {
			$next = null;
			if ($folder->nodeExists($segment) === true) {
				$next = $folder->get($segment);
			}

			if ($next instanceof Folder === false) {
				$next = $folder->newFolder($segment);
			}

			$folder = $next;
		}

		return $folder;
	}//end folderOf()

	/**
	 * Mark the report as agent-authored; on failure delete it and fail.
	 *
	 * @param File $file The report.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the mark fails.
	 */
	private function markOrRemove(File $file): void {
		try {
			$this->marker->markFile(fileId: (int)$file->getId());
		} catch (Throwable $e) {
			// Written but unmarked is a failure (ADR-088 §5): nothing downstream
			// would ever re-examine an unmarked agent-authored report.
			$this->logger->warning('Hermiq: research report mark failed; report removed', ['exception' => $e]);
			$file->delete();
			throw new RuntimeException(self::NOT_MARKED, 0, $e);
		}
	}//end markOrRemove()

	/**
	 * A title fit for a file name: no path separators or control characters, one line, at most 80 characters.
	 *
	 * @param string $title The title.
	 *
	 * @return string
	 */
	private static function fileTitle(string $title): string {
		$clean = trim((string)preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $title));
		$clean = (string)preg_replace('/\s+/u', ' ', $clean);
		if ($clean === '') {
			return 'Research report';
		}

		return rtrim(mb_substr($clean, 0, self::TITLE_LENGTH));
	}//end fileTitle()
}//end class
