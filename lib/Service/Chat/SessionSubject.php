<?php

/**
 * Hermiq SessionSubject.
 *
 * The record a session is about, kept in `metadata.subject` as
 * `{objectUuid, register, schema, appId}`. A new session takes it from the
 * page context of its first turn, and only when the caller can read that
 * record in OpenRegister under their own rights.
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
 * @spec openspec/changes/session-about-a-subject/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Reads, records and filters on the subject of a session.
 *
 * @spec openspec/changes/session-about-a-subject/tasks.md
 */
class SessionSubject {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the subject record as the caller.
	 *
	 * @spec openspec/changes/session-about-a-subject/tasks.md
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The new session's payload, with `metadata.subject` when the turn's page
	 * context names a record the caller can read. Anything else leaves the
	 * payload as it was.
	 *
	 * @param array<string, mixed> $session The new session's payload.
	 * @param array<string, mixed> $context The turn's page context.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
	 */
	public function stamp(array $session, array $context): array {
		$subject = $this->readableSubject(context: $context);
		if ($subject !== null) {
			$session['metadata'] = ['subject' => $subject];
		}

		return $session;
	}//end stamp()

	/**
	 * The subject a stored session carries, or null when it names none.
	 *
	 * @param array<string, mixed> $sessionData The session payload.
	 *
	 * @return array<string, string>|null The subject.
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
	 */
	public function subjectOf(array $sessionData): ?array {
		$metadata = ($sessionData['metadata'] ?? null);
		if (is_array($metadata) === false || is_array($metadata['subject'] ?? null) === false) {
			return null;
		}

		$subject = $this->reference(context: $metadata['subject']);
		if ($subject['objectUuid'] === '') {
			return null;
		}

		return $subject;
	}//end subjectOf()

	/**
	 * Session metadata as it may be written: a subject the session already has is
	 * kept, a new one is read as the caller and stored with the record's own
	 * register and schema, and one the caller cannot read is left out.
	 *
	 * @param mixed                     $metadata The metadata the caller sends.
	 * @param array<string, string>|null $stored   The session's current subject, or null.
	 *
	 * @return mixed The metadata to store.
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
	 */
	public function checkedMetadata(mixed $metadata, ?array $stored): mixed {
		if (is_array($metadata) === false || array_key_exists('subject', $metadata) === false) {
			return $metadata;
		}

		$subject = $this->writtenSubject(sent: $metadata['subject'], stored: $stored);
		if ($subject === null) {
			unset($metadata['subject']);
			return $metadata;
		}

		$metadata['subject'] = $subject;
		return $metadata;
	}//end checkedMetadata()

	/**
	 * The subject to store for one the caller sent: the stored one when it is
	 * repeated, else the record read as the caller, else null.
	 *
	 * @param mixed                      $sent   The subject the caller sent.
	 * @param array<string, string>|null $stored The session's current subject, or null.
	 *
	 * @return array<string, string>|null The subject to store, or null to leave it out.
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
	 */
	private function writtenSubject(mixed $sent, ?array $stored): ?array {
		if (is_array($sent) === false) {
			return null;
		}

		if ($stored !== null && $this->reference(context: $sent) === $stored) {
			return $stored;
		}

		return $this->readableSubject(context: $sent);
	}//end writtenSubject()

	/**
	 * The sessions about one record, latest activity first. An empty or
	 * non-text uuid leaves the list as it was.
	 *
	 * @param ObjectEntity[] $sessions   Sessions the caller may already read.
	 * @param mixed          $objectUuid The `subject` query value.
	 *
	 * @return ObjectEntity[] The sessions.
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-the-session-list-can-be-narrowed-to-one-record
	 */
	public function about(array $sessions, mixed $objectUuid): array {
		$uuid = $this->text(value: $objectUuid);
		if ($uuid === '') {
			return $sessions;
		}

		$matching = array_values(
			array_filter(
				$sessions,
				fn (ObjectEntity $session): bool => ($this->subjectOf(sessionData: $session->getObject())['objectUuid'] ?? '') === $uuid
			)
		);
		usort(
			$matching,
			fn (ObjectEntity $a, ObjectEntity $b): int => $this->lastActivity(session: $b) <=> $this->lastActivity(session: $a)
		);

		return $matching;
	}//end about()

	/**
	 * The record the context names, when the caller can read it.
	 *
	 * @param array<string, mixed> $context The turn's page context.
	 *
	 * @return array<string, string>|null The subject.
	 */
	private function readableSubject(array $context): ?array {
		$subject = $this->reference(context: $context);
		if ($subject['objectUuid'] === '') {
			return null;
		}

		$record = $this->readAsCaller(subject: $subject);
		if ($record === null) {
			return null;
		}

		$subject['register'] = (string)$record->getRegister();
		$subject['schema'] = (string)$record->getSchema();

		return $subject;
	}//end readableSubject()

	/**
	 * The record a subject names, read with OpenRegister's default RBAC and
	 * multitenancy, so as the signed-in caller. A record that is missing,
	 * unreadable or answers with another uuid gives null.
	 *
	 * @param array<string, string> $subject The subject reference.
	 *
	 * @return ObjectEntity|null The record.
	 */
	private function readAsCaller(array $subject): ?ObjectEntity {
		try {
			$record = $this->objectService->find(
				id: $subject['objectUuid'],
				register: $this->orNull(value: $subject['register']),
				schema: $this->orNull(value: $subject['schema'])
			);
		} catch (Throwable $e) {
			$record = null;
		}

		if (($record instanceof ObjectEntity) === false || (string)$record->getUuid() !== $subject['objectUuid']) {
			return null;
		}

		return $record;
	}//end readAsCaller()

	/**
	 * The subject fields a context or a stored subject names, as text.
	 *
	 * @param array<string, mixed> $context The page context or stored subject.
	 *
	 * @return array{objectUuid: string, register: string, schema: string, appId: string}
	 */
	private function reference(array $context): array {
		return [
			'objectUuid' => $this->text(value: ($context['objectUuid'] ?? null)),
			'register' => $this->text(value: ($context['register'] ?? $context['registerSlug'] ?? null)),
			'schema' => $this->text(value: ($context['schema'] ?? $context['schemaSlug'] ?? null)),
			'appId' => $this->text(value: ($context['appId'] ?? null)),
		];
	}//end reference()

	/**
	 * Null for an empty text, else the text.
	 *
	 * @param string $value The text.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()

	/**
	 * When a session last saw activity: its `lastActivityAt` or its last update,
	 * whichever is later.
	 *
	 * @param ObjectEntity $session The session.
	 *
	 * @return int Unix time.
	 */
	private function lastActivity(ObjectEntity $session): int {
		$stamp = strtotime($this->text(value: ($session->getObject()['lastActivityAt'] ?? null)));

		return max((int)$stamp, (int)$session->getUpdated()?->getTimestamp());
	}//end lastActivity()

	/**
	 * A trimmed string from a scalar text or number, else ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false && is_int($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
