<?php

/**
 * Tests for the passages a scoped chat turn reads from a document or folder.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
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
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-a-collection-req-dchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\Chat\AttachmentTextSource;
use OCA\Hermiq\Service\Chat\ScopedPassageSource;
use OCA\Hermiq\Service\Chat\TurnScopeException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Chat\ScopedPassageSource
 * @uses \OCA\Hermiq\Service\Chat\PassageText
 * @uses \OCA\Hermiq\Service\Chat\TurnScopeException
 */
class ScopedPassageSourceTest extends TestCase {

	/**
	 * Messages logged, as level, message and context.
	 *
	 * @var list<array{string, string, array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The source over a speaker's folder whose getById answers the given node.
	 *
	 * @param Node|\Throwable|null      $scope      What the scope id opens to.
	 * @param AttachmentTextSource|null $textSource The extractor for non-text files.
	 *
	 * @return ScopedPassageSource The source.
	 */
	private function source(Node|\Throwable|null $scope, ?AttachmentTextSource $textSource = null): ScopedPassageSource {
		$home = $this->createMock(Folder::class);
		if ($scope instanceof \Throwable) {
			$home->method('getById')->willThrowException($scope);
		} else {
			$home->method('getById')->willReturnCallback(static fn (int $id): array => ($id === 7 && $scope !== null ? [$scope] : []));
		}
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new ScopedPassageSource(rootFolder: $root, l10n: $l10n, logger: $logger, textSource: $textSource);
	}//end source()

	/**
	 * A file double.
	 *
	 * @param int                  $id      The file id.
	 * @param string               $name    The name.
	 * @param string|\Throwable    $content The content, or what reading throws.
	 * @param string               $mime    The mime type.
	 * @param bool                 $readable Whether the speaker may read it.
	 *
	 * @return File The file.
	 */
	private function file(int $id, string $name, string|\Throwable $content, string $mime = 'text/plain', bool $readable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/alice/files/Dossier/' . $name);
		$file->method('getMimetype')->willReturn($mime);
		$file->method('isReadable')->willReturn($readable);
		if ($content instanceof \Throwable) {
			$file->method('getContent')->willThrowException($content);
		} else {
			$file->method('getContent')->willReturn($content);
		}
		return $file;
	}//end file()

	/**
	 * A folder double listing the given nodes.
	 *
	 * @param list<Node> $nodes    The listing.
	 * @param bool       $readable Whether the speaker may read it.
	 *
	 * @return Folder The folder.
	 */
	private function folder(array $nodes, bool $readable = true): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($nodes);
		$folder->method('isReadable')->willReturn($readable);
		return $folder;
	}//end folder()

	/**
	 * A scope.
	 *
	 * @param string $type   `file` or `folder`.
	 * @param int    $fileId The scope id.
	 *
	 * @return array{type: string, fileId: int} The scope.
	 */
	private static function scope(string $type = 'file', int $fileId = 7): array {
		return ['type' => $type, 'fileId' => $fileId];
	}//end scope()

	/**
	 * A readable file of the right type passes; anything else is a 404.
	 *
	 * @return void
	 */
	public function testOnlyAReadableScopeOfTheRightTypeIsReadable(): void {
		$file = $this->file(7, 'a.txt', 'x');
		$this->source($file)->assertReadable(scope: self::scope(), speaker: 'alice');

		$refused = [
			[$this->source($file), self::scope(), ''],
			[$this->source($file), self::scope('file', 0), 'alice'],
			[$this->source($file), self::scope('folder'), 'alice'],
			[$this->source($this->file(7, 'a.txt', 'x', 'text/plain', false)), self::scope(), 'alice'],
			[$this->source(null), self::scope(), 'alice'],
			[$this->source(new RuntimeException('no such user')), self::scope(), 'alice'],
		];
		foreach ($refused as $index => [$source, $scope, $speaker]) {
			try {
				$source->assertReadable(scope: $scope, speaker: $speaker);
				$this->fail('case ' . $index . ' must be refused');
			} catch (TurnScopeException $e) {
				$this->assertSame(404, $e->getCode(), 'case ' . $index);
			}
		}

		$this->assertSame(
			['info', '[ScopedPassageSource] The scope could not be opened as the speaker', ['fileId' => 7, 'error' => 'no such user']],
			$this->logged[0]
		);
	}//end testOnlyAReadableScopeOfTheRightTypeIsReadable()

	/**
	 * A text file splits into passages carrying its id, name and path; pages are numbered.
	 *
	 * @return void
	 */
	public function testAFileSplitsIntoCitedPassagesWithPages(): void {
		$passages = $this->source($this->file(7, 'besluit.txt', "Page one text.\fPage two text."))->passages(scope: self::scope(), speaker: 'alice');

		$this->assertSame(
			[
				['file_id' => 7, 'name' => 'besluit.txt', 'file_path' => '/Dossier/besluit.txt', 'page' => 1, 'text' => 'Page one text.'],
				['file_id' => 7, 'name' => 'besluit.txt', 'file_path' => '/Dossier/besluit.txt', 'page' => 2, 'text' => 'Page two text.'],
			],
			$passages
		);
	}//end testAFileSplitsIntoCitedPassagesWithPages()

	/**
	 * A folder is read recursively; unreadable nodes and files without text are left out.
	 *
	 * @return void
	 */
	public function testAFolderIsReadRecursivelyLeavingOutWhatCannotBeRead(): void {
		$folder = $this->folder([
			$this->file(1, 'one.md', 'The permit was granted.', 'text/markdown'),
			$this->folder([$this->file(2, 'two.json', '{"permit": "refused"}', 'application/json')]),
			$this->folder([$this->file(3, 'hidden.txt', 'secret')], false),
			$this->file(4, 'private.txt', 'secret', 'text/plain', false),
			$this->file(5, 'blank.txt', "  \n "),
			$this->file(6, 'broken.txt', new RuntimeException('storage offline')),
			$this->file(8, 'scan.pdf', 'binary', 'application/pdf'),
		]);

		$passages = $this->source($folder)->passages(scope: self::scope('folder'), speaker: 'alice');

		$this->assertSame([1, 2], array_column($passages, 'file_id'));
		$this->assertSame([null, null], array_column($passages, 'page'));
		$this->assertSame(
			['warning', '[ScopedPassageSource] A file in scope could not be read; it is left out', ['fileId' => 6, 'error' => 'storage offline']],
			$this->logged[0]
		);
	}//end testAFolderIsReadRecursivelyLeavingOutWhatCannotBeRead()

	/**
	 * A folder reads at most MAX_FILES files.
	 *
	 * @return void
	 */
	public function testAFolderReadsAtMostTheFileCap(): void {
		$files = [];
		for ($id = 1; $id <= ScopedPassageSource::MAX_FILES + 5; $id++) {
			$files[] = $this->file($id + 100, 'f' . $id . '.txt', 'text ' . $id);
		}

		$passages = $this->source($this->folder($files))->passages(scope: self::scope('folder'), speaker: 'alice');

		$this->assertCount(ScopedPassageSource::MAX_FILES, $passages);
	}//end testAFolderReadsAtMostTheFileCap()

	/**
	 * A file that is not text is read through the text source, as the speaker.
	 *
	 * @return void
	 */
	public function testANonTextFileIsReadThroughTheTextSource(): void {
		$text = $this->createMock(AttachmentTextSource::class);
		$text->method('isAvailable')->willReturn(true);
		$text->expects($this->once())->method('extractText')->with(7, 'alice')->willReturn('Extracted permit text.');

		$passages = $this->source($this->file(7, 'scan.pdf', 'binary', 'application/pdf'), $text)->passages(scope: self::scope(), speaker: 'alice');

		$this->assertSame(['Extracted permit text.'], array_column($passages, 'text'));
	}//end testANonTextFileIsReadThroughTheTextSource()

	/**
	 * Matching ranks passages by score, numbers them and keeps the limit.
	 *
	 * @return void
	 */
	public function testMatchingRanksNumbersAndLimits(): void {
		$folder = $this->folder([
			$this->file(1, 'a.txt', 'The weather is fine.'),
			$this->file(2, 'b.txt', 'The permit for the bakery was granted.'),
			$this->file(3, 'c.txt', 'A permit is needed.'),
		]);
		$source = $this->source($folder);

		$matching = $source->matching(scope: self::scope('folder'), query: 'bakery permit', speaker: 'alice', limit: 1);

		$this->assertCount(1, $matching);
		$this->assertSame([1, 2], [$matching[0]['number'], $matching[0]['file_id']]);
		$this->assertGreaterThan(0.0, $matching[0]['score']);
		$this->assertArrayNotHasKey('order', $matching[0]);
		$this->assertSame([], $source->matching(scope: self::scope('folder'), query: 'permit', speaker: 'alice', limit: 0));
		$this->assertSame([], $source->matching(scope: self::scope('folder'), query: '', speaker: 'alice', limit: 5));
	}//end testMatchingRanksNumbersAndLimits()

	/**
	 * The no-match reply names a folder or a document.
	 *
	 * @return void
	 */
	public function testTheNoMatchReplyNamesTheScope(): void {
		$source = $this->source(null);

		$this->assertSame('The documents in this folder do not answer this question.', $source->noMatchReply(scope: self::scope('folder')));
		$this->assertSame('This document does not answer this question.', $source->noMatchReply(scope: self::scope()));
	}//end testTheNoMatchReplyNamesTheScope()
}//end class
