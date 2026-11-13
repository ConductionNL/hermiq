<?php

/**
 * Hermiq NotesWriteService update and failure-path unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
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

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\NotesWriteService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for updating a note and for every Notes call that throws.
 *
 * @covers \OCA\Hermiq\Service\NcNative\NotesWriteService
 */
class NotesWriteServiceUpdateTest extends TestCase {

	/**
	 * A Notes service double whose calls run the given closures.
	 *
	 * @param \Closure|null $getAll Replaces getAll().
	 * @param \Closure|null $get    Replaces get().
	 * @param \Closure|null $create Replaces create().
	 *
	 * @return object The double.
	 */
	private function notes(?\Closure $getAll = null, ?\Closure $get = null, ?\Closure $create = null): object {
		return new class($getAll, $get, $create) {
			/**
			 * The calls made, as [method, args].
			 *
			 * @var list<array{0: string, 1: array<int, mixed>}>
			 */
			public array $calls = [];

			public function __construct(private ?\Closure $getAll, private ?\Closure $get, private ?\Closure $create) {
			}

			public function getAll(string $uid): array {
				$this->calls[] = ['getAll', [$uid]];
				return ($this->getAll)($uid);
			}

			public function get(string $uid, int $id): object {
				$this->calls[] = ['get', [$uid, $id]];
				return ($this->get)($uid, $id);
			}

			public function create(string $uid, string $title, string $category): object {
				$this->calls[] = ['create', [$uid, $title, $category]];
				return ($this->create)($uid, $title, $category);
			}
		};
	}//end notes()

	/**
	 * A note double recording content and title writes.
	 *
	 * @param bool $readOnly Whether the note is read-only.
	 *
	 * @return object The note.
	 */
	private function note(bool $readOnly = false): object {
		return new class($readOnly) {
			public ?string $content = null;

			public string $title = 'Boodschappen';

			public function __construct(private bool $readOnly) {
			}

			public function getId(): int {
				return 12;
			}

			public function getTitle(): string {
				return $this->title;
			}

			public function getReadOnly(): bool {
				return $this->readOnly;
			}

			public function setContent(string $content): void {
				$this->content = $content;
			}

			public function setTitle(string $title): void {
				$this->title = $title;
			}

			public function getFile(): object {
				return new class() {
					public function getId(): int {
						return 4711;
					}
				};
			}
		};
	}//end note()

	/**
	 * The service over a Notes double.
	 *
	 * @param object                   $notes  The Notes double.
	 * @param LoggerInterface|null     $logger The logger.
	 * @param AgentArtefactMarker|null $marker The marker.
	 *
	 * @return NotesWriteService The service.
	 */
	private function service(object $notes, ?LoggerInterface $logger = null, ?AgentArtefactMarker $marker = null): NotesWriteService {
		return new class(
			$marker ?? $this->createMock(AgentArtefactMarker::class),
			$this->createMock(ContainerInterface::class),
			$logger ?? $this->createMock(LoggerInterface::class),
			$notes
		) extends NotesWriteService {
			public function __construct(AgentArtefactMarker $marker, ContainerInterface $container, LoggerInterface $logger, private object $double) {
				parent::__construct($marker, $container, $logger);
			}

			protected function notesService(): ?object {
				return $this->double;
			}
		};
	}//end service()

	/**
	 * An update reads the note as the user, applies content and a trimmed title, and marks the file.
	 *
	 * @return void
	 */
	public function testAnUpdateAppliesContentAndTitleAndMarks(): void {
		$note = $this->note();
		$notes = $this->notes(get: fn (): object => $note);
		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->expects($this->once())->method('markFile')->with(4711);

		$result = $this->service($notes, marker: $marker)->updateNote('alice', ['id' => '12', 'content' => '', 'title' => '  Nieuw  ']);

		$this->assertSame([['get', ['alice', 12]]], $notes->calls);
		$this->assertSame('', $note->content);
		$this->assertSame('Nieuw', $note->title);
		$this->assertSame(['updated' => true, 'id' => 12, 'title' => 'Nieuw', 'artefact' => ['type' => 'note', 'id' => '4711']], $result);
	}//end testAnUpdateAppliesContentAndTitleAndMarks()

	/**
	 * Without content or a non-blank title the note is left as it was.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutChangesLeavesTheNote(): void {
		$note = $this->note();

		$result = $this->service($this->notes(get: fn (): object => $note))->updateNote('alice', ['id' => 12, 'title' => '   ']);

		$this->assertNull($note->content);
		$this->assertSame('Boodschappen', $note->title);
		$this->assertTrue($result['updated']);
	}//end testAnUpdateWithoutChangesLeavesTheNote()

	/**
	 * A note that cannot be found (or belongs to someone else) is reported as not found and logged.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsNotFound(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Hermiq: note update failed', $this->anything());
		$notes = $this->notes(get: function (): object {
			throw new RuntimeException('not found');
		});

		$result = $this->service($notes, $logger)->updateNote('alice', ['id' => 99, 'content' => 'x']);

		$this->assertSame(['error' => ['code' => 'note_not_found', 'message' => 'That note could not be found or updated.']], $result);
	}//end testAFailedLookupIsNotFound()

	/**
	 * A failing listing is reported as unavailable and logged.
	 *
	 * @return void
	 */
	public function testAFailingListingIsUnavailable(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Hermiq: listing notes failed', $this->anything());
		$notes = $this->notes(getAll: function (): array {
			throw new RuntimeException('db');
		});

		$result = $this->service($notes, $logger)->listNotes('alice');

		$this->assertSame(['error' => ['code' => 'notes_unavailable', 'message' => 'The notes could not be listed.']], $result);
	}//end testAFailingListingIsUnavailable()

	/**
	 * A failing creation is reported as unavailable, logged, and nothing is marked.
	 *
	 * @return void
	 */
	public function testAFailingCreationIsUnavailable(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Hermiq: note creation failed', $this->anything());
		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->expects($this->never())->method('markFile');
		$notes = $this->notes(create: function (): object {
			throw new RuntimeException('quota');
		});

		$result = $this->service($notes, $logger, $marker)->createNote('alice', ['title' => 'Lijst', 'category' => 'Werk']);

		$this->assertSame([['create', ['alice', 'Lijst', 'Werk']]], $notes->calls);
		$this->assertSame(['error' => ['code' => 'notes_unavailable', 'message' => 'The note could not be created.']], $result);
	}//end testAFailingCreationIsUnavailable()
}//end class
