<?php

/**
 * Hermiq ReportSimilarityService edge-case unit tests.
 *
 * Covers the paths the scene tests do not reach: a group that does not exist or
 * cannot be read, a group whose stored members are not a list, stored objects that
 * are not groups at all, a register that cannot be read, and an audit write that
 * fails without failing the judgement.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ReportSimilarity
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ReportSimilarity;

use DateTimeImmutable;
use OCA\Hermiq\Service\ReportSimilarity\ReportEvaluation;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilarityService;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings;
use OCA\Hermiq\Service\ReportSimilarity\TermOverlapScorer;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ReportSimilarityService edge-case unit tests.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md
 */
#[CoversClass(ReportSimilarityService::class)]
#[UsesClass(ReportSimilaritySettings::class)]
#[UsesClass(TermOverlapScorer::class)]
#[UsesClass(ReportEvaluation::class)]
class ReportSimilarityServiceEdgesTest extends TestCase {

	/**
	 * The ObjectService double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The audit mapper double.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $auditTrailMapper;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the service over the doubles and real value classes.
	 *
	 * @param array<string, mixed> $stored The stored similarity settings.
	 *
	 * @return ReportSimilarityService The service.
	 */
	private function service(array $stored = []): ReportSimilarityService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(($stored === [] ? '' : (string)json_encode($stored)));

		return new ReportSimilarityService(
			$this->objectService,
			new ReportSimilaritySettings($config),
			new TermOverlapScorer(),
			$this->auditTrailMapper,
			$this->logger
		);
	}//end service()

	/**
	 * A stored group entity.
	 *
	 * @param string               $uuid The group uuid.
	 * @param array<string, mixed> $data The group's data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * A saveObject double that echoes the saved data back as an entity, recording it.
	 *
	 * @param array<int, array<string, mixed>> $saved Receives each saved payload.
	 *
	 * @return void
	 */
	private function echoSaves(array &$saved): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved): ObjectEntity {
				$saved[] = ['object' => (array)$object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];

				return $this->entity(($uuid ?? 'new-group'), (array)$object);
			}
		);
	}//end echoSaves()

	/**
	 * An empty group id is no group: nothing is looked up and the answer is null.
	 *
	 * @return void
	 */
	public function testAnEmptyGroupIdIsNoGroup(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->assertNull($this->service()->group(groupId: ''));
	}//end testAnEmptyGroupIdIsNoGroup()

	/**
	 * A lookup that throws reads as no group rather than failing the reader.
	 *
	 * @return void
	 */
	public function testAGroupThatCannotBeReadIsNoGroup(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('missing-uuid', [], false, 'hermiq', 'agentreportgroup')
			->willThrowException(new RuntimeException('not found'));

		$this->assertNull($this->service()->group(groupId: 'missing-uuid'));
	}//end testAGroupThatCannotBeReadIsNoGroup()

	/**
	 * Removing a member from a group that does not exist changes nothing and answers null.
	 *
	 * @return void
	 */
	public function testRemovingFromAMissingGroupSavesNothing(): void {
		$this->objectService->expects($this->once())->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull($this->service()->removeMember(groupId: 'gone', reportId: 'melding-1'));
	}//end testRemovingFromAMissingGroupSavesNothing()

	/**
	 * A group whose stored members are not a list loses them on removal and is saved
	 * back, under its own uuid, with an empty member list.
	 *
	 * @return void
	 */
	public function testRemovingFromAGroupWithUnreadableMembersSavesAnEmptyList(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity('g-1', ['reportType' => 'melding', 'members' => 'corrupt'])
		);
		$saved = [];
		$this->echoSaves($saved);

		$after = $this->service()->removeMember(groupId: 'g-1', reportId: 'melding-1');

		$this->assertCount(1, $saved);
		$this->assertSame([], $saved[0]['object']['members']);
		$this->assertSame('hermiq', $saved[0]['register']);
		$this->assertSame('agentreportgroup', $saved[0]['schema']);
		$this->assertSame('g-1', $saved[0]['uuid']);
		$this->assertSame('g-1', $after['groupId']);
		$this->assertSame(0, $after['count']);
		$this->assertSame([], $after['nearDuplicates']);
	}//end testRemovingFromAGroupWithUnreadableMembersSavesAnEmptyList()

	/**
	 * A group read back with non-list members shapes as an empty group rather than
	 * counting the characters of whatever is stored there.
	 *
	 * @return void
	 */
	public function testAGroupWithUnreadableMembersShapesAsEmpty(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity('g-2', ['reportType' => 'melding', 'windowMinutes' => 60, 'terms' => ['stroom'], 'members' => 'corrupt'])
		);

		$group = $this->service()->group(groupId: 'g-2');

		$this->assertSame(
			[
				'groupId' => 'g-2',
				'reportType' => 'melding',
				'deterministicKey' => '',
				'windowMinutes' => 60,
				'terms' => ['stroom'],
				'members' => [],
				'count' => 0,
				'nearDuplicates' => [],
			],
			$group
		);
	}//end testAGroupWithUnreadableMembersShapesAsEmpty()

	/**
	 * When the groups cannot be read the failure is logged with the report type and
	 * the report starts a group of its own rather than failing.
	 *
	 * @return void
	 */
	public function testUnreadableGroupsAreLoggedAndTheReportStartsAGroup(): void {
		$failure = new RuntimeException('register offline');
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['reportType' => 'melding'], 'limit' => 200])
			->willThrowException($failure);
		$saved = [];
		$this->echoSaves($saved);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Hermiq could not read the report groups: register offline',
				['exception' => $failure, 'reportType' => 'melding']
			);

		$answer = $this->service()->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat',
			now: new DateTimeImmutable('2026-03-01T10:00:00+00:00')
		);

		$this->assertTrue($answer['newGroup']);
		$this->assertSame('new-group', $answer['groupId']);
		$this->assertCount(1, $saved);
		$this->assertNull($saved[0]['uuid']);
	}//end testUnreadableGroupsAreLoggedAndTheReportStartsAGroup()

	/**
	 * A stored object that is not an ObjectEntity is never a candidate, even when
	 * its data would otherwise match by key.
	 *
	 * @return void
	 */
	public function testAStoredObjectThatIsNotAnEntityIsNeverACandidate(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				[
					'reportType' => 'melding',
					'deterministicKey' => 'k-1',
					'members' => [['reportId' => 'x', 'joinedAt' => '2026-03-01T09:59:00+00:00']],
				],
			]
		);
		$saved = [];
		$this->echoSaves($saved);

		$answer = $this->service()->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat',
			deterministicKey: 'k-1',
			now: new DateTimeImmutable('2026-03-01T10:00:00+00:00')
		);

		$this->assertTrue($answer['newGroup']);
		$this->assertCount(1, $saved);
		$this->assertNull($saved[0]['uuid']);
		$this->assertSame(ReportSimilarityService::DECIDED_BY_KEY, $saved[0]['object']['members'][0]['decidedBy']);
	}//end testAStoredObjectThatIsNotAnEntityIsNeverACandidate()

	/**
	 * Joining a group whose stored members are not a list replaces them with a list
	 * holding only the joining report. The group reads as open at the epoch, which
	 * is where an unreadable join time places it.
	 *
	 * @return void
	 */
	public function testJoiningAGroupWithUnreadableMembersStartsAFreshList(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->entity('g-3', ['reportType' => 'melding', 'deterministicKey' => 'k-1', 'terms' => ['stroomstoring'], 'members' => 'corrupt'])]
		);
		$saved = [];
		$this->echoSaves($saved);

		$answer = $this->service(['windows' => ['melding' => 60]])->evaluate(
			reportId: 'melding-9',
			reportType: 'melding',
			text: 'Iets anders',
			deterministicKey: 'k-1',
			now: new DateTimeImmutable('1970-01-01T00:00:00+00:00')
		);

		$this->assertFalse($answer['newGroup']);
		$this->assertSame('g-3', $answer['groupId']);
		$this->assertSame(1, $answer['count']);
		$this->assertSame(1.0, $answer['score']);
		$this->assertFalse($answer['uncertain']);
		$this->assertCount(1, $saved);
		$this->assertSame('g-3', $saved[0]['uuid']);
		$this->assertSame(
			[
				[
					'reportId' => 'melding-9',
					'score' => 1.0,
					'decidedBy' => ReportSimilarityService::DECIDED_BY_KEY,
					'scorer' => ReportSimilarityService::DECIDED_BY_KEY,
					'uncertain' => false,
					'joinedAt' => '1970-01-01T00:00:00+00:00',
				],
			],
			$saved[0]['object']['members']
		);
		// A key match carries no terms, so the group's terms are left as they were.
		$this->assertSame(['stroomstoring'], $saved[0]['object']['terms']);
	}//end testJoiningAGroupWithUnreadableMembersStartsAFreshList()

	/**
	 * A failed audit write is logged and never fails the judgement it records.
	 *
	 * @return void
	 */
	public function testAFailedAuditWriteIsLoggedAndTheAnswerStands(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$saved = [];
		$this->echoSaves($saved);
		$failure = new RuntimeException('audit table locked');
		$this->auditTrailMapper->expects($this->once())
			->method('recordObjectEvent')
			->with(
				$this->callback(
					static fn (ObjectAuditEvent $event): bool => $event->action === ReportSimilarityService::AUDIT_ACTION
						&& $event->context['reportId'] === 'melding-1'
						&& $event->context['groupId'] === 'new-group'
						&& $event->context['newGroup'] === true
				)
			)
			->willThrowException($failure);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Hermiq could not record a report-similarity judgement: audit table locked',
				['exception' => $failure]
			);

		$answer = $this->service()->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat',
			now: new DateTimeImmutable('2026-03-01T10:00:00+00:00')
		);

		$this->assertTrue($answer['newGroup']);
		$this->assertSame('new-group', $answer['groupId']);
		$this->assertSame(1, $answer['count']);
	}//end testAFailedAuditWriteIsLoggedAndTheAnswerStands()
}//end class
