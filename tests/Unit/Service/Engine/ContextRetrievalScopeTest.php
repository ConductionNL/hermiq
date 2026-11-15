<?php

/**
 * Hermiq ContextRetrievalHandler: a turn scoped to one file or one folder.
 *
 * Drives the real handler and the real ScopedPassageSource; Files, the
 * translator and the text source are doubles.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Chat\AttachmentTextSource;
use OCA\Hermiq\Service\Chat\ScopedPassageSource;
use OCA\Hermiq\Service\Chat\TurnScopeException;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for scoped retrieval.
 */
class ContextRetrievalScopeTest extends TestCase {

	/**
	 * The uids Files were opened as.
	 *
	 * @var list<string>
	 */
	private array $openedAs = [];

	/**
	 * The (fileId, uid) pairs the text source was asked for.
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	public array $extracted = [];

	/**
	 * A file in alice's Files.
	 *
	 * @param int    $fileId   The id.
	 * @param string $name     The name.
	 * @param string $content  The bytes.
	 * @param string $mimeType The type.
	 * @param bool   $readable Whether alice may read it.
	 *
	 * @return File&MockObject
	 */
	private function file(int $fileId, string $name, string $content, string $mimeType = 'text/plain', bool $readable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/alice/files/Dossiers/' . $name);
		$file->method('getMimetype')->willReturn($mimeType);
		$file->method('getContent')->willReturn($content);
		$file->method('isReadable')->willReturn($readable);
		return $file;
	}//end file()

	/**
	 * A folder listing the given nodes.
	 *
	 * @param list<File|Folder> $nodes The nodes.
	 *
	 * @return Folder&MockObject
	 */
	private function folder(array $nodes): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($nodes);
		$folder->method('isReadable')->willReturn(true);
		return $folder;
	}//end folder()

	/**
	 * The handler over alice's Files, where each id resolves to the given node.
	 *
	 * @param array<int, File|Folder>   $byId       The nodes by id.
	 * @param ObjectService|null        $objects    The object search, which a scoped turn never uses.
	 * @param AttachmentTextSource|null $textSource The PDF text source.
	 *
	 * @return ContextRetrievalHandler
	 */
	private function handler(array $byId, ?ObjectService $objects = null, ?AttachmentTextSource $textSource = null): ContextRetrievalHandler {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturnCallback(
			static fn (int $id): array => (isset($byId[$id]) === true ? [$byId[$id]] : [])
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($userFolder): Folder {
				$this->openedAs[] = $uid;
				return $userFolder;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		if ($objects === null) {
			$objects = $this->createMock(ObjectService::class);
			$objects->expects($this->never())->method('searchObjectsPaginated');
		}

		return new ContextRetrievalHandler(
			objectService: $objects,
			logger: new NullLogger(),
			scopedSource: new ScopedPassageSource(rootFolder: $root, l10n: $l10n, logger: new NullLogger(), textSource: $textSource)
		);
	}//end handler()

	/**
	 * A file scope searches only that file, read as the speaker, and numbers its
	 * passages in the order the sources are listed.
	 *
	 * @return void
	 */
	public function testAFileScopeUsesOnlyThatFileAndNumbersItsSources(): void {
		$besluit = $this->file(
			fileId: 42,
			name: 'besluit-2026-0042.txt',
			content: "Aanvrager: J. Jansen.\n\nDe beslistermijn is acht weken.\n\nDe beslistermijn kan eenmaal worden verlengd."
		);
		$handler = $this->handler(byId: [42 => $besluit]);

		$context = $handler->retrieveContext(
			query: 'Wat is de beslistermijn?',
			agent: null,
			ragSettings: ['scope' => ['type' => 'file', 'fileId' => 42], 'numSourcesFiles' => 5],
			speaker: 'alice'
		);

		$this->assertTrue($context['scoped']);
		$this->assertSame(['alice'], array_values(array_unique($this->openedAs)));
		$this->assertCount(1, $context['sources']);
		$source = $context['sources'][0];
		$this->assertSame(42, $source['file_id']);
		$this->assertSame('file', $source['type']);
		$this->assertSame('besluit-2026-0042.txt', $source['name']);
		$this->assertSame('/Dossiers/besluit-2026-0042.txt', $source['file_path']);
		$this->assertSame(1, $source['passage']);
		$this->assertNull($source['page']);
		$this->assertStringContainsString('De beslistermijn is acht weken.', $source['text']);
		$this->assertStringStartsWith("[1] Source: besluit-2026-0042.txt\n", $context['text']);
	}

	/**
	 * A folder scope reads the files under it at any depth that the speaker can
	 * read, and never a file outside it, however well that file matches.
	 *
	 * @return void
	 */
	public function testAFolderScopeStaysInsideTheFolder(): void {
		$inside = $this->file(fileId: 11, name: 'aanvraag.txt', content: 'De parkeervergunning is aangevraagd.');
		$deeper = $this->file(fileId: 12, name: 'advies.txt', content: 'Advies: de parkeervergunning wordt toegekend.');
		$hidden = $this->file(fileId: 13, name: 'geheim.txt', content: 'parkeervergunning parkeervergunning', readable: false);
		$outside = $this->file(fileId: 99, name: 'buiten.txt', content: 'parkeervergunning parkeervergunning parkeervergunning');
		$dossier = $this->folder(nodes: [$inside, $this->folder(nodes: [$deeper, $hidden])]);
		$handler = $this->handler(byId: [5 => $dossier, 99 => $outside]);

		$context = $handler->retrieveContext(
			query: 'Is de parkeervergunning toegekend?',
			agent: null,
			ragSettings: ['scope' => ['type' => 'folder', 'fileId' => 5]],
			speaker: 'alice'
		);

		$ids = array_column($context['sources'], 'file_id');
		$this->assertSame([12, 11], $ids, 'The better match comes first; nothing outside the folder or unreadable');
		$this->assertSame([1, 2], array_column($context['sources'], 'passage'));
		$this->assertStringContainsString('[2] Source: aanvraag.txt', $context['text']);
	}

	/**
	 * A PDF without an index entry is read through the text source as the speaker,
	 * and each passage keeps the page it came from.
	 *
	 * @return void
	 */
	public function testAPdfIsReadThroughTheTextSourceWithPages(): void {
		$pdf = $this->file(fileId: 42, name: 'besluit.pdf', content: '', mimeType: 'application/pdf');
		$test = $this;
		$source = new class($test) implements AttachmentTextSource {
			public function __construct(private readonly ContextRetrievalScopeTest $test) {
			}

			public function isAvailable(): bool {
				return true;
			}

			public function extractText(int $fileId, string $userId): ?string {
				$this->test->extracted[] = [$fileId, $userId];
				return "Besluit op uw aanvraag.\fDe beslistermijn is acht weken.";
			}
		};
		$handler = $this->handler(byId: [42 => $pdf], textSource: $source);

		$context = $handler->retrieveContext(
			query: 'beslistermijn',
			agent: null,
			ragSettings: ['scope' => ['type' => 'file', 'fileId' => 42]],
			speaker: 'bob'
		);

		$this->assertSame([[42, 'bob']], $this->extracted);
		$this->assertSame(2, $context['sources'][0]['page']);
		$this->assertStringStartsWith("[1] Source: besluit.pdf, page 2\n", $context['text']);
	}

	/**
	 * Nothing in scope matches: no sources, and the reply says the document does not answer it.
	 *
	 * @return void
	 */
	public function testNothingInScopeLeavesNoSourcesAndTheNoMatchReply(): void {
		$permit = $this->file(fileId: 42, name: 'omgevingsvergunning.txt', content: 'Bouwen van een dakkapel.');
		$handler = $this->handler(byId: [42 => $permit, 5 => $this->folder(nodes: [$permit])]);

		$file = $handler->retrieveContext(
			query: 'Hoe hoog is de parkeerboete?',
			agent: null,
			ragSettings: ['scope' => ['type' => 'file', 'fileId' => 42]],
			speaker: 'alice'
		);
		$folder = $handler->retrieveContext(
			query: 'Hoe hoog is de parkeerboete?',
			agent: null,
			ragSettings: ['scope' => ['type' => 'folder', 'fileId' => 5]],
			speaker: 'alice'
		);

		$this->assertSame([], $file['sources']);
		$this->assertSame('', $file['text']);
		$this->assertSame('This document does not answer this question.', $file['noMatch']);
		$this->assertSame('The documents in this folder do not answer this question.', $folder['noMatch']);
	}

	/**
	 * A file the speaker cannot open, an unknown id, and a file scope naming a
	 * folder are each refused with 404.
	 *
	 * @return void
	 */
	public function testAScopeTheSpeakerCannotOpenIsA404(): void {
		$locked = $this->file(fileId: 42, name: 'geheim.txt', content: 'x', readable: false);
		$handler = $this->handler(byId: [42 => $locked, 5 => $this->folder(nodes: [])]);

		foreach ([['type' => 'file', 'fileId' => 42], ['type' => 'file', 'fileId' => 404], ['type' => 'file', 'fileId' => 5]] as $scope) {
			try {
				$handler->assertScopeReadable(ragSettings: ['scope' => $scope], speaker: 'alice');
				$this->fail('Scope ' . json_encode($scope) . ' was accepted');
			} catch (TurnScopeException $e) {
				$this->assertSame(404, $e->getCode());
			}
		}

		$handler->assertScopeReadable(ragSettings: ['scope' => ['type' => 'folder', 'fileId' => 5]], speaker: 'alice');
		$handler->assertScopeReadable(ragSettings: [], speaker: 'alice');
		$this->assertSame(['alice', 'alice', 'alice', 'alice'], $this->openedAs);
	}

	/**
	 * Without a passage source wired, a scoped turn is refused rather than
	 * answered from everything the agent can reach.
	 *
	 * @return void
	 */
	public function testAScopeWithoutAPassageSourceIsRefused(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('searchObjectsPaginated');
		$handler = new ContextRetrievalHandler(objectService: $objects, logger: new NullLogger());

		$this->expectException(TurnScopeException::class);
		$this->expectExceptionCode(404);
		$handler->assertScopeReadable(ragSettings: ['scope' => ['type' => 'file', 'fileId' => 42]], speaker: 'alice');
	}
}
