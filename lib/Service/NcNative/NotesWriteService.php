<?php

/**
 * Hermiq Notes Write Service (nc-native-write-tools).
 *
 * List, create and update notes for the acting user.
 *
 * Notes publishes no OCP contract, so its service is resolved lazily from the
 * server container behind a `class_exists()` guard and a shape probe — the same
 * pattern `listDeckBoards` already uses for Deck, and for the same reason: Hermiq
 * must boot and complete runs on an instance where the app is absent, and an
 * internal API with no deprecation contract can move underneath us.
 *
 * There is deliberately NO agent id in the create/update signatures. A note is
 * marked with a system tag, and a tag is a shared label that cannot carry
 * per-agent data — one tag per agent would turn the instance's tag list into an
 * agent registry. Which agent authored a note lives in the run trace beside the
 * file id; the tag's job is only to make that record discoverable from the file.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notes operations scoped to the acting user.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
class NotesWriteService {

	use ErrorEnvelopeTrait;

	/**
	 * The Notes app's service class, resolved lazily and only if present.
	 *
	 * @var string
	 */
	private const NOTES_SERVICE = '\OCA\Notes\Service\NotesService';

	/**
	 * Constructor.
	 *
	 * @param AgentArtefactMarker $marker ADR-088 marking.
	 * @param ContainerInterface $container Lazy Notes service resolution.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly AgentArtefactMarker $marker,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * List the acting user's notes (titles and ids only — never bodies).
	 *
	 * @param string $uid The acting user id.
	 *
	 * @return array<string, mixed> The notes, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function listNotes(string $uid): array {
		$notes = $this->notesService();
		if ($notes === null) {
			return $this->notesAbsent();
		}

		try {
			$all = $notes->getAll($uid);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: listing notes failed', ['exception' => $e]);

			return $this->err(code: 'notes_unavailable', message: 'The notes could not be listed.');
		}

		$results = [];
		foreach ($all as $note) {
			$results[] = [
				'id' => $note->getId(),
				'title' => $note->getTitle(),
				'category' => $note->getCategory(),
				'modified' => $note->getModified(),
			];
		}

		return ['notes' => $results];

	}//end listNotes()

	/**
	 * Create a note for the acting user.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments title, content, category.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function createNote(string $uid, array $arguments): array {
		$title = trim((string)($arguments['title'] ?? ''));
		if ($title === '') {
			return $this->err(code: 'invalid_argument', message: 'A note title is required.');
		}

		$notes = $this->notesService();
		if ($notes === null) {
			return $this->notesAbsent();
		}

		try {
			$note = $notes->create($uid, $title, (string)($arguments['category'] ?? ''));
			$note->setContent((string)($arguments['content'] ?? ''));
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: note creation failed', ['exception' => $e]);

			return $this->err(code: 'notes_unavailable', message: 'The note could not be created.');
		}

		return $this->markedResult(note: $note, verb: 'created');

	}//end createNote()

	/**
	 * Update a note the acting user owns.
	 *
	 * Notes has NO version history, so an overwrite here is not recoverable the way
	 * an in-place document edit is. That is why the tool is classified destructive
	 * and why a read-only note is refused rather than forced — neither the approval
	 * gate nor the ADR-088 mark restores lost prose.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments id, content, title.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function updateNote(string $uid, array $arguments): array {
		$id = (int)($arguments['id'] ?? 0);
		if ($id <= 0) {
			return $this->err(code: 'invalid_argument', message: 'A note id is required.');
		}

		$notes = $this->notesService();
		if ($notes === null) {
			return $this->notesAbsent();
		}

		try {
			// IDOR: `get()` is scoped to $uid, so another user's note id resolves
			// to a not-found rather than to their note.
			$note = $notes->get($uid, $id);

			if ($note->getReadOnly() === true) {
				return $this->err(code: 'note_read_only', message: 'That note is read-only and cannot be changed.');
			}

			$this->applyChanges(note: $note, arguments: $arguments);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: note update failed', ['exception' => $e]);

			return $this->err(code: 'note_not_found', message: 'That note could not be found or updated.');
		}

		return $this->markedResult(note: $note, verb: 'updated');

	}//end updateNote()

	/**
	 * Apply the caller's requested changes to a note.
	 *
	 * @param object $note The Notes app note.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return void
	 */
	private function applyChanges(object $note, array $arguments): void {
		if (array_key_exists('content', $arguments) === true) {
			$note->setContent((string)$arguments['content']);
		}

		$title = trim((string)($arguments['title'] ?? ''));
		if ($title !== '') {
			$note->setTitle($title);
		}

	}//end applyChanges()

	/**
	 * Apply the ADR-088 mark to a note's underlying file and build its result.
	 *
	 * A note IS a file, so it takes a system tag — visible and filterable in the
	 * Files UI the user already has.
	 *
	 * @param object $note The Notes app note.
	 * @param string $verb Either 'created' or 'updated'.
	 *
	 * @return array<string, mixed> The result, or an error envelope when marking failed.
	 */
	private function markedResult(object $note, string $verb): array {
		$fileId = 0;

		try {
			$fileId = $note->getFile()->getId();
			$this->marker->markFile(fileId: $fileId);
		} catch (Throwable $e) {
			// ADR-088 §5: written but unmarked is reported as a FAILURE. Returning
			// success here would produce the one artefact nothing downstream ever
			// re-examines.
			$this->logger->warning('Hermiq: note mark failed', ['exception' => $e]);

			return $this->err(
				code: 'artefact_not_marked',
				message: 'The note was written but could not be marked as agent-authored.'
			);
		}

		return [
			$verb => true,
			'id' => $note->getId(),
			'title' => $note->getTitle(),
			'artefact' => ['type' => 'note', 'id' => (string)$fileId],
		];

	}//end markedResult()

	/**
	 * Resolve the Notes app's service, or null when Notes is absent or has drifted.
	 *
	 * `protected` so tests can substitute a double. Notes is resolved by hard-coded
	 * class name, which means the success paths are unreachable on any environment
	 * where Notes is not installed — including CI. Leaving it private would make
	 * "create a note" permanently untested rather than merely untestable here.
	 *
	 * @return object|null The Notes service, or null.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	protected function notesService(): ?object {
		if (class_exists(self::NOTES_SERVICE) === false) {
			return null;
		}

		try {
			$service = $this->container->get(self::NOTES_SERVICE);
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq: Notes service could not be resolved', ['exception' => $e]);

			return null;
		}

		// A container is not obliged to throw — it may hand back a non-object for
		// a binding it cannot build. Passing that to method_exists() below would be
		// a fatal, breaking the "invokeTool() never throws" guarantee.
		if (is_object($service) === false) {
			return null;
		}

		foreach (['getAll', 'get', 'create'] as $method) {
			if (method_exists($service, $method) === false) {
				$this->logger->warning('Hermiq: Notes service is missing expected method {method}', ['method' => $method]);

				return null;
			}
		}

		return $service;

	}//end notesService()

	/**
	 * The structured error returned when Notes is unavailable.
	 *
	 * @return array<string, mixed> The error envelope.
	 */
	private function notesAbsent(): array {
		return $this->err(
			code: 'notes_not_available',
			message: 'The Notes app is not available on this instance.'
		);

	}//end notesAbsent()

}//end class
