<?php

/**
 * SessionSubject records the record a session is about only when the caller
 * can read it, and narrows a session list to one record, latest activity first
 * (session-about-a-subject, T01 to T03).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use DateTime;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Tests\Unit\Support\FindCallShape;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SessionSubject.
 *
 * @covers \OCA\Hermiq\Service\Chat\SessionSubject
 *
 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
 */
class SessionSubjectTest extends TestCase {

	/**
	 * The arguments of each find() call.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $finds = [];

	/**
	 * A SessionSubject whose object store answers find() with $answer.
	 *
	 * @param ObjectEntity|null|\Throwable $answer The record, null, or a failure.
	 *
	 * @return SessionSubject
	 */
	private function subject(mixed $answer): SessionSubject {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed ...$args) use ($answer): ?ObjectEntity {
				$this->finds[] = FindCallShape::flat(args: $args);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		return new SessionSubject(objectService: $objectService);
	}//end subject()

	/**
	 * A record or session entity.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The payload.
	 * @param string|null          $updated The last update, or null.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload = [], ?string $updated = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		$entity->setRegister('5');
		$entity->setSchema('9');
		if ($updated !== null) {
			$entity->setUpdated(new DateTime($updated));
		}

		return $entity;
	}//end entity()

	/**
	 * A record the caller can read becomes the session's subject, read as the
	 * caller (default RBAC and multitenancy), with the record's own register and
	 * schema and the context's app.
	 *
	 * @return void
	 */
	public function testAReadableRecordBecomesTheSubject(): void {
		$session = $this->subject(answer: $this->entity(uuid: 'case-1'))->stamp(
			session: ['userId' => 'alice'],
			context: ['objectUuid' => 'case-1', 'registerSlug' => 'zaken', 'schemaSlug' => 'zaak', 'appId' => 'dossiq']
		);

		$this->assertSame(
			['userId' => 'alice', 'metadata' => ['subject' => ['objectUuid' => 'case-1', 'register' => '5', 'schema' => '9', 'appId' => 'dossiq']]],
			$session
		);
		$this->assertCount(1, $this->finds);
		$this->assertSame(['case-1', 'zaken', 'zaak'], [$this->finds[0][0], $this->finds[0][3], $this->finds[0][4]]);
		$this->assertTrue(($this->finds[0][5] ?? true), 'The subject must be read under the caller\'s RBAC.');
		$this->assertTrue(($this->finds[0][6] ?? true), 'The subject must be read under the caller\'s multitenancy.');
	}//end testAReadableRecordBecomesTheSubject()

	/**
	 * Without a register or schema in the context, the record's own are stored.
	 *
	 * @return void
	 */
	public function testTheRecordFillsAMissingRegisterAndSchema(): void {
		$session = $this->subject(answer: $this->entity(uuid: 'case-1'))->stamp(
			session: [],
			context: ['objectUuid' => 'case-1']
		);

		$this->assertSame(['objectUuid' => 'case-1', 'register' => '5', 'schema' => '9', 'appId' => ''], $session['metadata']['subject']);
		$this->assertNull($this->finds[0][3]);
		$this->assertNull($this->finds[0][4]);
	}//end testTheRecordFillsAMissingRegisterAndSchema()

	/**
	 * A forged uuid that names no record gives no subject.
	 *
	 * @return void
	 */
	public function testAForgedUuidGivesNoSubject(): void {
		$session = $this->subject(answer: null)->stamp(session: ['userId' => 'alice'], context: ['objectUuid' => 'forged']);

		$this->assertSame(['userId' => 'alice'], $session);
	}//end testAForgedUuidGivesNoSubject()

	/**
	 * A record the caller may not read (OpenRegister refuses the read) gives no subject.
	 *
	 * @return void
	 */
	public function testARecordTheCallerCannotReadGivesNoSubject(): void {
		$session = $this->subject(answer: new DoesNotExistException('not readable'))->stamp(
			session: ['userId' => 'alice'],
			context: ['objectUuid' => 'case-secret', 'register' => 'zaken', 'schema' => 'zaak']
		);

		$this->assertSame(['userId' => 'alice'], $session);
	}//end testARecordTheCallerCannotReadGivesNoSubject()

	/**
	 * A read that answers with another record (an id or slug lookup) gives no subject.
	 *
	 * @return void
	 */
	public function testARecordWithAnotherUuidGivesNoSubject(): void {
		$session = $this->subject(answer: $this->entity(uuid: 'case-2'))->stamp(session: [], context: ['objectUuid' => '42']);

		$this->assertSame([], $session);
	}//end testARecordWithAnotherUuidGivesNoSubject()

	/**
	 * A context without a text objectUuid reads nothing and gives no subject.
	 *
	 * @return void
	 */
	public function testAContextWithoutAnObjectReadsNothing(): void {
		$subject = $this->subject(answer: $this->entity(uuid: 'case-1'));

		$this->assertSame([], $subject->stamp(session: [], context: ['appId' => 'dossiq']));
		$this->assertSame([], $subject->stamp(session: [], context: ['objectUuid' => ['case-1']]));
		$this->assertSame([], $subject->stamp(session: [], context: ['objectUuid' => '  ']));
		$this->assertSame([], $this->finds);
	}//end testAContextWithoutAnObjectReadsNothing()

	/**
	 * A stored session gives its subject; a session without one, or with a
	 * malformed one, gives null.
	 *
	 * @return void
	 */
	public function testOfReadsTheStoredSubject(): void {
		$subject = $this->subject(answer: null);

		$this->assertSame(
			['objectUuid' => 'case-1', 'register' => 'zaken', 'schema' => 'zaak', 'appId' => 'dossiq'],
			$subject->subjectOf(sessionData: ['metadata' => ['subject' => ['objectUuid' => 'case-1', 'register' => 'zaken', 'schema' => 'zaak', 'appId' => 'dossiq']]])
		);
		$this->assertNull($subject->subjectOf(sessionData: []));
		$this->assertNull($subject->subjectOf(sessionData: ['metadata' => 'text']));
		$this->assertNull($subject->subjectOf(sessionData: ['metadata' => ['subject' => 'case-1']]));
		$this->assertNull($subject->subjectOf(sessionData: ['metadata' => ['subject' => ['register' => 'zaken']]]));
	}//end testOfReadsTheStoredSubject()

	/**
	 * The list narrows to the sessions about the record, latest activity first,
	 * where activity is the later of lastActivityAt and the last update.
	 *
	 * @return void
	 */
	public function testAboutNarrowsAndSortsByLatestActivity(): void {
		$about = static fn (string $uuid): array => ['metadata' => ['subject' => ['objectUuid' => $uuid]]];
		$old = $this->entity(uuid: 's-old', payload: $about('case-1'), updated: '2026-01-01T00:00:00Z');
		$active = $this->entity(uuid: 's-active', payload: ($about('case-1') + ['lastActivityAt' => '2026-03-01T00:00:00Z']), updated: '2026-01-02T00:00:00Z');
		$new = $this->entity(uuid: 's-new', payload: $about('case-1'), updated: '2026-02-01T00:00:00Z');
		$other = $this->entity(uuid: 's-other', payload: $about('case-2'), updated: '2026-04-01T00:00:00Z');
		$none = $this->entity(uuid: 's-none', updated: '2026-05-01T00:00:00Z');

		$found = $this->subject(answer: null)->about(sessions: [$old, $other, $new, $none, $active], objectUuid: 'case-1');

		$this->assertSame(['s-active', 's-new', 's-old'], array_map(static fn (ObjectEntity $s): string => (string)$s->getUuid(), $found));
	}//end testAboutNarrowsAndSortsByLatestActivity()

	/**
	 * No subject, or a subject that is not text, leaves the list as it was.
	 *
	 * @return void
	 */
	public function testAboutWithoutAUuidKeepsTheList(): void {
		$sessions = [$this->entity(uuid: 's-1'), $this->entity(uuid: 's-2')];
		$subject = $this->subject(answer: null);

		$this->assertSame($sessions, $subject->about(sessions: $sessions, objectUuid: null));
		$this->assertSame($sessions, $subject->about(sessions: $sessions, objectUuid: ''));
		$this->assertSame($sessions, $subject->about(sessions: $sessions, objectUuid: ['case-1']));
	}//end testAboutWithoutAUuidKeepsTheList()
	/**
	 * A register or schema label in the context never reaches the stored subject:
	 * a forged label beside a real uuid is replaced by the record's own.
	 *
	 * @return void
	 */
	public function testAContextLabelNeverReplacesTheRecordsRegisterAndSchema(): void {
		$session = $this->subject(answer: $this->entity(uuid: 'case-1'))->stamp(
			session: [],
			context: ['objectUuid' => 'case-1', 'register' => 'forged-register', 'schema' => 'forged-schema']
		);

		$this->assertSame(['objectUuid' => 'case-1', 'register' => '5', 'schema' => '9', 'appId' => ''], $session['metadata']['subject']);
	}//end testAContextLabelNeverReplacesTheRecordsRegisterAndSchema()

	/**
	 * Metadata without a subject, or not a map, passes through unread.
	 *
	 * @return void
	 */
	public function testMetadataWithoutASubjectPassesThrough(): void {
		$subject = $this->subject(answer: $this->entity(uuid: 'case-1'));

		$this->assertSame(['pinned' => true], $subject->checkedMetadata(metadata: ['pinned' => true], stored: null));
		$this->assertSame('text', $subject->checkedMetadata(metadata: 'text', stored: null));
		$this->assertSame([], $this->finds);
	}//end testMetadataWithoutASubjectPassesThrough()

	/**
	 * A new subject in written metadata is read as the caller: an unreadable one
	 * is dropped, a readable one is stored with the record's register and schema.
	 *
	 * @return void
	 */
	public function testANewSubjectInWrittenMetadataIsReadAsTheCaller(): void {
		$unreadable = $this->subject(answer: null)->checkedMetadata(
			metadata: ['pinned' => true, 'subject' => ['objectUuid' => 'case-secret', 'register' => 'zaken', 'schema' => 'zaak']],
			stored: null
		);
		$this->assertSame(['pinned' => true], $unreadable);

		$readable = $this->subject(answer: $this->entity(uuid: 'case-1'))->checkedMetadata(
			metadata: ['subject' => ['objectUuid' => 'case-1', 'schema' => 'forged', 'appId' => 'dossiq']],
			stored: ['objectUuid' => 'case-0', 'register' => '5', 'schema' => '9', 'appId' => 'dossiq']
		);
		$this->assertSame(['subject' => ['objectUuid' => 'case-1', 'register' => '5', 'schema' => '9', 'appId' => 'dossiq']], $readable);

		$notAMap = $this->subject(answer: $this->entity(uuid: 'case-1'))->checkedMetadata(metadata: ['subject' => 'case-1'], stored: null);
		$this->assertSame([], $notAMap);
	}//end testANewSubjectInWrittenMetadataIsReadAsTheCaller()

	/**
	 * Written metadata that repeats the stored subject keeps it without a new read.
	 *
	 * @return void
	 */
	public function testAnUnchangedSubjectIsKeptWithoutARead(): void {
		$stored = ['objectUuid' => 'case-1', 'register' => '5', 'schema' => '9', 'appId' => 'dossiq'];
		$kept = $this->subject(answer: null)->checkedMetadata(metadata: ['subject' => $stored, 'pinned' => true], stored: $stored);

		$this->assertSame(['subject' => $stored, 'pinned' => true], $kept);
		$this->assertSame([], $this->finds);
	}//end testAnUnchangedSubjectIsKeptWithoutARead()
}//end class
