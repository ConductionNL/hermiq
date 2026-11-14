<?php

/**
 * Hermiq ScopedPassageSource.
 *
 * The passages of a scoped chat turn: the one file the turn is about, or every
 * file under the folder it is about that the speaker can read. Each file is read
 * as the speaker — a text file straight from Files, a PDF or office file through
 * the AttachmentTextSource — and split into numbered passages that keep the page
 * they came from (a form feed separates pages in extracted text). Only passages
 * that share a word with the question are kept, best first.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/tasks.md#task-2-retrieval-honours-a-file-or-folder-scope
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a turn's scope as the speaker and returns the passages that match the question.
 *
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
 */
class ScopedPassageSource {

	/**
	 * The most files a folder scope reads in one turn.
	 *
	 * @var int
	 */
	public const MAX_FILES = 100;

	/**
	 * The most characters read from one file.
	 *
	 * @var int
	 */
	public const MAX_FILE_CHARS = 200000;

	/**
	 * Types read straight from Files as text, besides every `text/*` type.
	 *
	 * @var list<string>
	 */
	private const TEXT_TYPES = ['application/json', 'application/xml', 'application/x-yaml'];

	/**
	 * Constructor.
	 *
	 * @param IRootFolder               $rootFolder Opens the speaker's own Files.
	 * @param IL10N                     $l10n       The reply when nothing in scope matches.
	 * @param LoggerInterface           $logger     Logger.
	 * @param AttachmentTextSource|null $textSource  Extracts a PDF's or office file's text; null
	 *                                               leaves such files out.
	 * @param PassageText               $passageText Splits text into passages and scores them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?AttachmentTextSource $textSource = null,
		private readonly PassageText $passageText = new PassageText(),
	) {
	}//end __construct()

	/**
	 * Refuse a scope the speaker cannot open, or whose node is not of its type.
	 *
	 * @param array{type: string, fileId: int} $scope   The turn's scope.
	 * @param string                           $speaker The uid the turn is read as.
	 *
	 * @return void
	 *
	 * @throws TurnScopeException With code 404 when the speaker cannot open it.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function assertReadable(array $scope, string $speaker): void {
		if ($this->scopeNode(scope: $scope, speaker: $speaker) === null) {
			throw new TurnScopeException(status: 404);
		}
	}//end assertReadable()

	/**
	 * The passages in scope that share a word with the question, best first and
	 * numbered from 1 in that order.
	 *
	 * @param array{type: string, fileId: int} $scope   The turn's scope.
	 * @param string                           $query   The question.
	 * @param string                           $speaker The uid the files are read as.
	 * @param int                              $limit   The most passages returned.
	 *
	 * @return list<array<string, mixed>> The passages: number, file_id, name, file_path, page, text, score.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-a-collection-req-dchat-002
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-every-scoped-answer-cites-its-sources-req-dchat-003
	 */
	public function matching(array $scope, string $query, string $speaker, int $limit): array {
		$terms = $this->passageText->terms(text: $query);
		if ($terms === [] || $limit < 1) {
			return [];
		}

		$scored = $this->scored(scope: $scope, speaker: $speaker, terms: $terms);
		usort(
			$scored,
			static fn (array $left, array $right): int => [$right['score'], $left['order']] <=> [$left['score'], $right['order']]
		);

		$matching = [];
		foreach (array_slice($scored, 0, $limit) as $index => $passage) {
			unset($passage['order']);
			$matching[] = ['number' => ($index + 1)] + $passage;
		}

		return $matching;
	}//end matching()

	/**
	 * The passages in scope that share a word with the question, with their score and reading order.
	 *
	 * @param array{type: string, fileId: int} $scope   The turn's scope.
	 * @param string                           $speaker The uid the files are read as.
	 * @param list<string>                     $terms   The question's words.
	 *
	 * @return list<array<string, mixed>> The scored passages, in reading order.
	 */
	private function scored(array $scope, string $speaker, array $terms): array {
		$scored = [];
		foreach ($this->passages(scope: $scope, speaker: $speaker) as $order => $passage) {
			$score = $this->passageText->score(text: $passage['text'], terms: $terms);
			if ($score > 0.0) {
				$scored[] = $passage + ['score' => $score, 'order' => $order];
			}
		}

		return $scored;
	}//end scored()

	/**
	 * Every passage in scope, in file and reading order.
	 *
	 * @param array{type: string, fileId: int} $scope   The turn's scope.
	 * @param string                           $speaker The uid the files are read as.
	 *
	 * @return list<array{file_id: int, name: string, file_path: string, page: int|null, text: string}>
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function passages(array $scope, string $speaker): array {
		$passages = [];
		foreach ($this->filesInScope(scope: $scope, speaker: $speaker) as $file) {
			$text = $this->readText(file: $file, speaker: $speaker);
			if ($text === null) {
				continue;
			}

			array_push($passages, ...$this->split(file: $file, text: $text, speaker: $speaker));
		}

		return $passages;
	}//end passages()

	/**
	 * The sentence a scoped turn is answered with when nothing in scope matches.
	 *
	 * @param array{type: string, fileId: int} $scope The turn's scope.
	 *
	 * @return string The reply.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-every-scoped-answer-cites-its-sources-req-dchat-003
	 */
	public function noMatchReply(array $scope): string {
		if ($scope['type'] === 'folder') {
			return $this->l10n->t('The documents in this folder do not answer this question.');
		}

		return $this->l10n->t('This document does not answer this question.');
	}//end noMatchReply()

	/**
	 * The scope's node in the speaker's Files when it is readable and of the scope's type.
	 *
	 * @param array{type: string, fileId: int} $scope   The scope.
	 * @param string                           $speaker The uid.
	 *
	 * @return Node|null The file or folder.
	 */
	private function scopeNode(array $scope, string $speaker): ?Node {
		if ($speaker === '' || $scope['fileId'] < 1) {
			return null;
		}

		$node = $this->openNode(fileId: $scope['fileId'], speaker: $speaker);
		if (self::isScopeNode(node: $node, type: $scope['type']) === false) {
			return null;
		}

		return $node;
	}//end scopeNode()

	/**
	 * Whether a node is readable and of the scope's type: a folder for `folder`, else a file.
	 *
	 * @param Node|null $node The node.
	 * @param string    $type The scope's type.
	 *
	 * @return bool
	 */
	private static function isScopeNode(?Node $node, string $type): bool {
		$expected = File::class;
		if ($type === 'folder') {
			$expected = Folder::class;
		}

		return $node instanceof $expected && $node->isReadable() === true;
	}//end isScopeNode()

	/**
	 * The node with this id in the speaker's Files, or null when it cannot be opened.
	 *
	 * @param int    $fileId  The file or folder id.
	 * @param string $speaker The uid.
	 *
	 * @return Node|null The node.
	 */
	private function openNode(int $fileId, string $speaker): ?Node {
		try {
			return ($this->rootFolder->getUserFolder($speaker)->getById($fileId)[0] ?? null);
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[ScopedPassageSource] The scope could not be opened as the speaker',
				context: ['fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end openNode()

	/**
	 * The readable files in scope: the file itself, or the folder's files at any depth.
	 *
	 * @param array{type: string, fileId: int} $scope   The scope.
	 * @param string                           $speaker The uid.
	 *
	 * @return list<File>
	 */
	private function filesInScope(array $scope, string $speaker): array {
		$node = $this->scopeNode(scope: $scope, speaker: $speaker);
		if ($node instanceof File) {
			return [$node];
		}

		if ($node instanceof Folder === false) {
			return [];
		}

		$files = [];
		$this->collectFiles(folder: $node, files: $files);
		return $files;
	}//end filesInScope()

	/**
	 * Add the folder's readable files, depth first, up to MAX_FILES.
	 *
	 * @param Folder     $folder The folder.
	 * @param list<File> $files  The files so far, filled in place.
	 *
	 * @return void
	 */
	private function collectFiles(Folder $folder, array &$files): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if (count($files) >= self::MAX_FILES) {
				return;
			}

			$this->collectNode(node: $node, files: $files);
		}
	}//end collectFiles()

	/**
	 * Add one readable node: a file itself, a folder's files depth first.
	 *
	 * @param Node       $node  The node.
	 * @param list<File> $files The files so far, filled in place.
	 *
	 * @return void
	 */
	private function collectNode(Node $node, array &$files): void {
		if ($node->isReadable() === false) {
			return;
		}

		if ($node instanceof Folder) {
			$this->collectFiles(folder: $node, files: $files);
			return;
		}

		if ($node instanceof File) {
			$files[] = $node;
		}
	}//end collectNode()

	/**
	 * A file's text as the speaker reads it, or null when it has none hermiq can read.
	 *
	 * @param File   $file    The file.
	 * @param string $speaker The uid.
	 *
	 * @return string|null The text, capped at MAX_FILE_CHARS.
	 */
	private function readText(File $file, string $speaker): ?string {
		$mimeType = strtolower((string)$file->getMimetype());
		try {
			$text = $this->rawText(file: $file, mimeType: $mimeType, speaker: $speaker);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ScopedPassageSource] A file in scope could not be read; it is left out',
				context: ['fileId' => $file->getId(), 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_string($text) === false || trim($text) === '') {
			return null;
		}

		return mb_substr($text, 0, self::MAX_FILE_CHARS);
	}//end readText()

	/**
	 * A file's content when it is text, else what the text source extracts, else null.
	 *
	 * @param File   $file     The file.
	 * @param string $mimeType The file's lower-cased MIME type.
	 * @param string $speaker  The uid.
	 *
	 * @return mixed The text, or whatever the reader returned.
	 */
	private function rawText(File $file, string $mimeType, string $speaker): mixed {
		if (str_starts_with($mimeType, 'text/') === true || in_array($mimeType, self::TEXT_TYPES, true) === true) {
			return $file->getContent();
		}

		if ($this->textSource !== null && $this->textSource->isAvailable() === true) {
			return $this->textSource->extractText(fileId: (int)$file->getId(), userId: $speaker);
		}

		return null;
	}//end rawText()

	/**
	 * Split a file's text into passages: per page (form feeds), then per run of
	 * paragraphs up to PassageText::PASSAGE_CHARS. A file without form feeds has no page numbers.
	 *
	 * @param File   $file    The file.
	 * @param string $text    Its text.
	 * @param string $speaker The uid, for the path relative to their Files.
	 *
	 * @return list<array{file_id: int, name: string, file_path: string, page: int|null, text: string}>
	 */
	private function split(File $file, string $text, string $speaker): array {
		$pages = explode("\f", $text);
		$paged = (count($pages) > 1);
		$passages = [];
		foreach ($pages as $index => $pageText) {
			$page = null;
			if ($paged === true) {
				$page = ($index + 1);
			}

			foreach ($this->passageText->chunks(text: $pageText) as $chunk) {
				$passages[] = [
					'file_id' => (int)$file->getId(),
					'name' => (string)$file->getName(),
					'file_path' => $this->relativePath(file: $file, speaker: $speaker),
					'page' => $page,
					'text' => $chunk,
				];
			}
		}

		return $passages;
	}//end split()


	/**
	 * The file's path within the speaker's Files, e.g. `/Dossiers/besluit.pdf`.
	 *
	 * @param File   $file    The file.
	 * @param string $speaker The uid.
	 *
	 * @return string The path, or the bare name when it lies outside their Files.
	 */
	private function relativePath(File $file, string $speaker): string {
		$path = (string)$file->getPath();
		$prefix = '/' . $speaker . '/files';
		if (str_starts_with($path, $prefix . '/') === true) {
			return substr($path, strlen($prefix));
		}

		return '/' . (string)$file->getName();
	}//end relativePath()



}//end class
