<?php

/**
 * Tests for the nc-native-write-tools NotesWriteService.
 *
 * Notes is an OPTIONAL app reached through an internal API with no deprecation
 * contract, so the absent path is not an edge case — it is the path most
 * instances take, and it must degrade to a structured error rather than break a
 * run.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCA\Hermiq\Service\NcNative\CalendarWriteService;
use OCA\Hermiq\Service\NcNative\ContactWriteService;
use OCA\Hermiq\Service\NcNative\NotesWriteService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NotesWriteService.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 */
class NotesWriteServiceTest extends TestCase {

	/**
	 * Build the service with a container that cannot resolve the Notes service.
	 *
	 * @return NotesWriteService
	 */
	private function service(): NotesWriteService {
		return new NotesWriteService(
			$this->createMock(AgentArtefactMarker::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * Every notes tool degrades to a structured error when Notes cannot be
	 * resolved — never an exception, so the agent run still completes.
	 *
	 * @return void
	 */
	public function testUnresolvableNotesReturnsStructuredErrorForEveryTool(): void {
		$service = $this->service();

		$results = [
			$service->listNotes('alice'),
			$service->createNote('alice', ['title' => 'x']),
			$service->updateNote('alice', ['id' => 1, 'content' => 'x']),
		];

		foreach ($results as $result) {
			$this->assertArrayHasKey('error', $result);
			$this->assertSame('notes_not_available', $result['error']['code']);
		}

	}//end testUnresolvableNotesReturnsStructuredErrorForEveryTool()

	/**
	 * A missing note id is refused before any lookup is attempted.
	 *
	 * @return void
	 */
	public function testUpdateNoteRequiresAnId(): void {
		$result = $this->service()->updateNote('alice', ['content' => 'x']);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testUpdateNoteRequiresAnId()

	/**
	 * A missing title is refused before any lookup is attempted.
	 *
	 * @return void
	 */
	public function testCreateNoteRequiresATitle(): void {
		$result = $this->service()->createNote('alice', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testCreateNoteRequiresATitle()

	/**
	 * A note double backed by an in-memory file id.
	 *
	 * @param int $fileId The underlying file id.
	 * @param bool $readOnly Whether the note is read-only.
	 *
	 * @return object The note double.
	 */
	private function note(int $fileId = 4711, bool $readOnly = false): object {
		return new class($fileId, $readOnly) {
			/**
			 * Captured content.
			 *
			 * @var string
			 */
			public string $content = '';

			/**
			 * Captured title.
			 *
			 * @var string
			 */
			public string $title = 'Groceries';

			/**
			 * Constructor.
			 *
			 * @param int $fileId The file id.
			 * @param bool $readOnly Whether the note is read-only.
			 */
			public function __construct(private int $fileId, private bool $readOnly) {
			}

			/**
			 * The note id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return 12;
			}

			/**
			 * The note title.
			 *
			 * @return string
			 */
			public function getTitle(): string {
				return $this->title;
			}

			/**
			 * The note category.
			 *
			 * @return string
			 */
			public function getCategory(): string {
				return 'Shopping';
			}

			/**
			 * The modification time.
			 *
			 * @return int
			 */
			public function getModified(): int {
				return 1700000000;
			}

			/**
			 * Whether the note is read-only.
			 *
			 * @return bool
			 */
			public function getReadOnly(): bool {
				return $this->readOnly;
			}

			/**
			 * Set the content.
			 *
			 * @param string $content The content.
			 *
			 * @return void
			 */
			public function setContent(string $content): void {
				$this->content = $content;
			}

			/**
			 * Set the title.
			 *
			 * @param string $title The title.
			 *
			 * @return void
			 */
			public function setTitle(string $title): void {
				$this->title = $title;
			}

			/**
			 * The underlying file.
			 *
			 * @return object
			 */
			public function getFile(): object {
				return new class($this->fileId) {
					/**
					 * Constructor.
					 *
					 * @param int $id The file id.
					 */
					public function __construct(private int $id) {
					}

					/**
					 * The file id.
					 *
					 * @return int
					 */
					public function getId(): int {
						return $this->id;
					}
				};
			}
		};

	}//end note()

	/**
	 * Build a service whose Notes resolution is replaced by the given double.
	 *
	 * @param object|null $notesDouble The Notes service double, or null for absent.
	 * @param AgentArtefactMarker|null $marker Marker double.
	 *
	 * @return NotesWriteService
	 */
	private function serviceWithNotes(?object $notesDouble, ?AgentArtefactMarker $marker = null): NotesWriteService {
		return new class(
			$marker ?? $this->createMock(AgentArtefactMarker::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$notesDouble
		) extends NotesWriteService {
			/**
			 * Constructor.
			 *
			 * @param AgentArtefactMarker $marker The marker.
			 * @param ContainerInterface $container The container.
			 * @param LoggerInterface $logger The logger.
			 * @param object|null $double The Notes double.
			 */
			public function __construct(
				AgentArtefactMarker $marker,
				ContainerInterface $container,
				LoggerInterface $logger,
				private ?object $double,
			) {
				parent::__construct($marker, $container, $logger);
			}

			/**
			 * Return the injected double instead of resolving Notes.
			 *
			 * @return object|null
			 */
			protected function notesService(): ?object {
				return $this->double;
			}
		};

	}//end serviceWithNotes()

	/**
	 * A created note is written, content applied, and the underlying file marked.
	 *
	 * @return void
	 */
	public function testCreateNoteWritesContentAndMarksTheFile(): void {
		$note = $this->note();
		$notes = new class($note) {
			/**
			 * Constructor.
			 *
			 * @param object $note The note to return.
			 */
			public function __construct(private object $note) {
			}

			/**
			 * List notes.
			 *
			 * @param string $uid The user id.
			 *
			 * @return array<int, object>
			 */
			public function getAll(string $uid): array {
				return [$this->note];
			}

			/**
			 * Get a note.
			 *
			 * @param string $uid The user id.
			 * @param int $id The note id.
			 *
			 * @return object
			 */
			public function get(string $uid, int $id): object {
				return $this->note;
			}

			/**
			 * Create a note.
			 *
			 * @param string $uid The user id.
			 * @param string $title The title.
			 * @param string $category The category.
			 *
			 * @return object
			 */
			public function create(string $uid, string $title, string $category): object {
				return $this->note;
			}
		};

		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->expects($this->once())->method('markFile')->with(4711);

		$result = $this->serviceWithNotes($notes, $marker)->createNote(
			'alice',
			['title' => 'Groceries', 'content' => 'milk']
		);

		$this->assertTrue($result['created']);
		$this->assertSame('note', $result['artefact']['type']);
		$this->assertSame('4711', $result['artefact']['id']);
		$this->assertSame('milk', $note->content);

	}//end testCreateNoteWritesContentAndMarksTheFile()

	/**
	 * A read-only note is refused rather than forced.
	 *
	 * @return void
	 */
	public function testReadOnlyNoteIsRefused(): void {
		$note = $this->note(4711, true);
		$notes = new class($note) {
			/**
			 * Constructor.
			 *
			 * @param object $note The note.
			 */
			public function __construct(private object $note) {
			}

			/**
			 * Get a note.
			 *
			 * @param string $uid The user id.
			 * @param int $id The note id.
			 *
			 * @return object
			 */
			public function get(string $uid, int $id): object {
				return $this->note;
			}
		};

		$result = $this->serviceWithNotes($notes)->updateNote('alice', ['id' => 12, 'content' => 'overwrite']);

		$this->assertSame('note_read_only', $result['error']['code']);
		$this->assertSame('', $note->content, 'A refused update must not have written content.');

	}//end testReadOnlyNoteIsRefused()

	/**
	 * ADR-088 §5: written but unmarked is reported as a FAILURE, never a success.
	 *
	 * @return void
	 */
	public function testMarkingFailureIsReportedAsAFailedWrite(): void {
		$note = $this->note();
		$notes = new class($note) {
			/**
			 * Constructor.
			 *
			 * @param object $note The note.
			 */
			public function __construct(private object $note) {
			}

			/**
			 * Create a note.
			 *
			 * @param string $uid The user id.
			 * @param string $title The title.
			 * @param string $category The category.
			 *
			 * @return object
			 */
			public function create(string $uid, string $title, string $category): object {
				return $this->note;
			}
		};

		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->method('markFile')->willThrowException(
			new ArtefactMarkingFailedException('could not mark')
		);

		$result = $this->serviceWithNotes($notes, $marker)->createNote('alice', ['title' => 'Groceries']);

		$this->assertSame('artefact_not_marked', $result['error']['code']);
		$this->assertArrayNotHasKey('created', $result);

	}//end testMarkingFailureIsReportedAsAFailedWrite()

	/**
	 * Listing returns titles and metadata, never bodies.
	 *
	 * @return void
	 */
	public function testListNotesReturnsNoBodies(): void {
		$note = $this->note();
		$note->setContent('a secret shopping list');
		$notes = new class($note) {
			/**
			 * Constructor.
			 *
			 * @param object $note The note.
			 */
			public function __construct(private object $note) {
			}

			/**
			 * List notes.
			 *
			 * @param string $uid The user id.
			 *
			 * @return array<int, object>
			 */
			public function getAll(string $uid): array {
				return [$this->note];
			}
		};

		$result = $this->serviceWithNotes($notes)->listNotes('alice');

		$this->assertCount(1, $result['notes']);
		$this->assertSame('Groceries', $result['notes'][0]['title']);
		$this->assertStringNotContainsString(
			'secret shopping list',
			(string)json_encode($result),
			'Listing must not leak note bodies.'
		);

	}//end testListNotesReturnsNoBodies()

	/**
	 * No NC-native write service offers a delete or remove verb.
	 *
	 * A structural assertion rather than a behavioural one: the guarantee is that
	 * the capability does not exist, and that is only true if no method provides it.
	 *
	 * @return void
	 */
	public function testNoWriteServiceExposesADeleteVerb(): void {
		$services = [CalendarWriteService::class, ContactWriteService::class, NotesWriteService::class];

		foreach ($services as $service) {
			foreach (get_class_methods($service) as $method) {
				$this->assertStringNotContainsStringIgnoringCase(
					'delete',
					$method,
					"{$service}::{$method} — nc-native-write-tools grants create and update only."
				);
				$this->assertStringNotContainsStringIgnoringCase('remove', $method);
			}
		}

	}//end testNoWriteServiceExposesADeleteVerb()

}//end class
