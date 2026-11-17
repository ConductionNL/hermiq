<?php

/**
 * Characterisation tests for how MigrateConversationsToSessions reads its source
 * objects and normalises carried values.
 *
 * Pins that the live and the archived reads are both taken (a failure of either is
 * logged, never fatal), that an archived object replaces a live one with the same
 * uuid in place, that only archived objects of the source schema are kept, and the
 * exact string normalisation of carried provenance values, so the read can be split
 * into named steps without dropping or reordering a migrated object.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use DateTimeImmutable;
use OCA\Hermiq\Repair\MigrateConversationsToSessions;
use OCA\Hermiq\Repair\Support\RegisterScopedSchema;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Characterisation tests for the source read of the session migration.
 */
class MigrateConversationsToSessionsReadTest extends TestCase {

	/**
	 * Logged error lines.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * An object entity of a schema.
	 *
	 * @param string $uuid The uuid.
	 * @param string $schema The schema id.
	 * @param string $tag A marker to tell copies apart.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $schema, string $tag): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject(['tag' => $tag]);
		return $entity;
	}//end object()

	/**
	 * The repair step over a container serving the given archived read.
	 *
	 * @param mixed $deleted The archived objects, or a Throwable to throw.
	 * @param string|null $schemaId The source schema's id, null when unknown.
	 *
	 * @return MigrateConversationsToSessions
	 */
	private function repair(mixed $deleted, ?string $schemaId): MigrateConversationsToSessions {
		$mapper = new class($deleted) {
			/**
			 * @param mixed $deleted The archived objects, or a Throwable.
			 */
			public function __construct(private readonly mixed $deleted) {
			}

			/**
			 * @param int $limit The limit.
			 * @param int $offset The offset.
			 *
			 * @return array<int, mixed>
			 */
			public function findDeletedAcrossAllMagicTables(int $limit, int $offset): array {
				if ($this->deleted instanceof \Throwable) {
					throw $this->deleted;
				}

				return $this->deleted;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$schemas = $this->createMock(RegisterScopedSchema::class);
		$schemas->method('idFor')->willReturn($schemaId);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new MigrateConversationsToSessions(
			$container,
			$this->createMock(\OCP\App\IAppManager::class),
			$this->createMock(\OCP\IAppConfig::class),
			$schemas,
			$this->createMock(\OCP\IDBConnection::class),
			$logger
		);
	}//end repair()

	/**
	 * An object service whose live read returns the given objects, or throws.
	 *
	 * @param mixed $live The live objects, or a Throwable to throw.
	 *
	 * @return ObjectService
	 */
	private function liveService(mixed $live): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		if ($live instanceof \Throwable) {
			$objectService->method('searchObjectsPaginated')->willThrowException($live);
		} else {
			$objectService->method('searchObjectsPaginated')->willReturn(['results' => $live]);
		}

		return $objectService;
	}//end liveService()

	/**
	 * The tags of a read, in order.
	 *
	 * @param MigrateConversationsToSessions $repair The repair step.
	 * @param ObjectService $objectService The object service.
	 *
	 * @return array<int, string>
	 */
	private function read(MigrateConversationsToSessions $repair, ObjectService $objectService): array {
		$rows = (new ReflectionMethod(MigrateConversationsToSessions::class, 'readSource'))->invoke($repair, $objectService, 'conversation');
		return array_map(static fn (ObjectEntity $row): string => $row->getObject()['tag'], $rows);
	}//end read()

	/**
	 * Live and archived objects of the schema are both read; an archived copy replaces
	 * the live one in place; other schemas and non-objects are skipped.
	 *
	 * @return void
	 */
	public function testLiveAndArchivedObjectsAreMergedByUuid(): void {
		$live = ['x', $this->object('a', '7', 'live-a'), $this->object('b', '7', 'live-b')];
		$deleted = [$this->object('b', '7', 'arch-b'), $this->object('c', '8', 'other'), 'y', $this->object('d', '7', 'arch-d')];

		$this->assertSame(['live-a', 'arch-b', 'arch-d'], $this->read($this->repair($deleted, '7'), $this->liveService($live)));
		$this->assertSame(['live-a', 'live-b'], $this->read($this->repair($deleted, null), $this->liveService($live)));
		$this->assertSame([], $this->errors);

	}//end testLiveAndArchivedObjectsAreMergedByUuid()

	/**
	 * A failing live read still reads the archive, and a failing archive read keeps
	 * the live objects; each failure is logged.
	 *
	 * @return void
	 */
	public function testEachReadFailureIsLoggedAndTheOtherReadStillCounts(): void {
		$archived = [$this->object('d', '7', 'arch-d')];

		$this->assertSame(['arch-d'], $this->read($this->repair($archived, '7'), $this->liveService(new RuntimeException('db busy'))));
		$this->assertSame(['live-a'], $this->read($this->repair(new RuntimeException('no table'), '7'), $this->liveService([$this->object('a', '7', 'live-a')])));
		$this->assertSame(
			[
				'[hermiq] session-data-migration: live read of conversation failed: db busy',
				'[hermiq] session-data-migration: ARCHIVED read of conversation failed, archived objects were NOT migrated: no table',
			],
			$this->errors
		);

	}//end testEachReadFailureIsLoggedAndTheOtherReadStillCounts()

	/**
	 * Carried values become a trimmed string, an ISO date for a date, or null for
	 * null, blanks and anything that is not a scalar.
	 *
	 * @return void
	 */
	public function testCarriedValuesAreNormalisedToStringOrNull(): void {
		$normalise = new ReflectionMethod(MigrateConversationsToSessions::class, 'stringOrNull');
		$repair = $this->repair([], null);
		$cases = [
			[null, null],
			['', null],
			['  ', null],
			[' org-1 ', 'org-1'],
			[0, '0'],
			[1.5, '1.5'],
			[true, '1'],
			[false, null],
			[['x'], null],
			[new \stdClass(), null],
			[new DateTimeImmutable('2026-01-02T03:04:05+00:00'), '2026-01-02T03:04:05+00:00'],
		];
		foreach ($cases as $index => [$value, $expected]) {
			$this->assertSame($expected, $normalise->invoke($repair, $value), 'case ' . $index);
		}

	}//end testCarriedValuesAreNormalisedToStringOrNull()
}//end class
