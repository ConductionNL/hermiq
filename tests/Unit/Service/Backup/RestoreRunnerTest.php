<?php

/**
 * Unit tests for RestoreRunner.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Backup
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Backup;

use OCA\Hermiq\Kernel\Contracts\OrganisationControlServiceInterface;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\RestorePlanner;
use OCA\Hermiq\Service\Backup\RestorePoint;
use OCA\Hermiq\Service\Backup\RestoreRunner;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The kill switch is on before the first write and off only after a clean restore.
 *
 * @covers \OCA\Hermiq\Service\Backup\RestoreRunner
 * @uses   \OCA\Hermiq\Service\Backup\RestorePoint
 * @uses   \OCA\Hermiq\Service\Backup\BackupSettings
 */
final class RestoreRunnerTest extends TestCase {

	/**
	 * What happened, in order: toggle on/off, save <uuid>, delete <uuid>, record, notify.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The recorded audit event.
	 *
	 * @var ObjectAuditEvent|null
	 */
	private ?ObjectAuditEvent $recorded = null;

	/**
	 * The notification subject parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $notified = [];

	/**
	 * The saved objects, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * A plan for Tilburg with two writes and one later object.
	 *
	 * @param string|null $refused A refusal.
	 *
	 * @return array<string, mixed>
	 */
	private static function plan(?string $refused=null): array {
		return [
			'point'           => ['kind' => 'backup', 'file' => 'hermiq-backup-tilburg-2026-09-28-0200.json.gz'],
			'organisation'    => 'tilburg',
			'registerVersion' => '0.54.0',
			'refused'         => $refused,
			'schemas'         => ['agent' => ['putBack' => 1, 'changed' => 1, 'unchanged' => 0, 'createdAfter' => 1, 'missing' => 0, 'noHistory' => 0, 'notRestorable' => []]],
			'writes'          => [
				['schema' => 'agent', 'uuid' => 'a-1', 'object' => ['name' => 'Intake', 'prompt' => 'Be kind']],
				['schema' => 'agent', 'uuid' => 'a-2', 'object' => ['name' => 'Router']],
			],
			'createdAfter'    => [['schema' => 'agent', 'uuid' => 'a-9']],
		];
	}//end plan()

	/**
	 * The runner over a fixed plan.
	 *
	 * @param array<string, mixed> $plan       The plan.
	 * @param list<string>         $failing    Uuids whose save throws.
	 * @param bool                 $wasEngaged Whether the kill switch is on before the restore.
	 *
	 * @return RestoreRunner
	 */
	private function runner(array $plan, array $failing=[], bool $wasEngaged=false): RestoreRunner {
		$planner = $this->createMock(RestorePlanner::class);
		$planner->method('plan')->willReturn($plan);

		$control = new ObjectEntity();
		$control->setUuid('tc-1');
		$control->setOrganisation('tilburg');
		$control->setObject(['engaged' => $wasEngaged]);

		$killSwitch = $this->createMock(OrganisationControlServiceInterface::class);
		$killSwitch->method('getForOrganisation')->with('tilburg')->willReturn($control);
		$killSwitch->method('toggle')->willReturnCallback(
			function (string $organisation, bool $engaged, ?string $reason, string $actorUid) use ($control): ObjectEntity {
				$this->assertSame('tilburg', $organisation);
				$this->assertSame('admin', $actorUid);
				$this->calls[] = ($engaged === true ? 'on: ' . $reason : 'off');
				return $control;
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true) use ($failing): ObjectEntity {
				$this->assertSame('hermiq', $register);
				$this->assertSame('agent', $schema);
				$this->calls[] = 'save ' . $uuid;
				if (in_array($uuid, $failing, true) === true) {
					throw new RuntimeException('validation failed');
				}

				$this->saved[(string)$uuid] = $object;
				return new ObjectEntity();
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				$this->calls[] = 'delete ' . $uuid;
				return true;
			}
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event): AuditTrail {
				$this->calls[] = 'record';
				$this->recorded = $event;
				return new AuditTrail();
			}
		);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}

		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use ($notification): INotification {
				$this->assertSame('restore_finished', $subject);
				$this->notified = $parameters;
				return $notification;
			}
		);
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('notify')->willReturnCallback(
			function (): void {
				$this->calls[] = 'notify';
			}
		);

		return new RestoreRunner(
			planner: $planner,
			killSwitch: $killSwitch,
			objectService: $objects,
			auditTrail: $audit,
			notifications: $notifications,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end runner()

	/**
	 * The point the plans above are for.
	 *
	 * @param bool $removeCreatedAfter Whether later objects are removed.
	 *
	 * @return RestorePoint
	 */
	private static function point(bool $removeCreatedAfter=false): RestorePoint {
		return new RestorePoint(kind: 'backup', file: 'hermiq-backup-tilburg-2026-09-28-0200.json.gz', removeCreatedAfter: $removeCreatedAfter);
	}//end point()

	/**
	 * A clean restore: on first, every write, off, then the record and the notification.
	 *
	 * @return void
	 */
	public function testACleanRestoreEngagesTheSwitchFirstAndReleasesItAfter(): void {
		$result = $this->runner(plan: self::plan())->run(point: self::point(), settings: new BackupSettings(ownerUid: 'admin'), adminUid: 'admin');

		$this->assertSame(['on: Restore in progress', 'save a-1', 'save a-2', 'off', 'record', 'notify'], $this->calls);
		$this->assertSame('completed', $result['outcome']);
		$this->assertTrue($result['released']);
		$this->assertSame(2, $result['written']);
		$this->assertSame(0, $result['removed'], 'objects created after the point are kept by default');
		$this->assertSame(['name' => 'Intake', 'prompt' => 'Be kind', '@self' => ['organisation' => 'tilburg']], $this->saved['a-1']);
		$this->assertSame('restore', $this->recorded?->action);
		$this->assertSame('tc-1', $this->recorded?->object->getUuid());
		$this->assertSame('admin', $this->recorded?->context['administrator']);
		$this->assertSame(1, $this->recorded?->context['counts']['agent']['changed']);
		$this->assertArrayNotHasKey('notRestorable', $this->recorded?->context['counts']['agent']);
		$this->assertSame(0, $this->notified['failures']);
	}//end testACleanRestoreEngagesTheSwitchFirstAndReleasesItAfter()

	/**
	 * Two failing saves: the rest is written, the switch stays on, both are named.
	 *
	 * @return void
	 */
	public function testFailingSavesKeepTheAgentsStoppedAndAreNamed(): void {
		$plan = self::plan();
		$plan['writes'][] = ['schema' => 'agent', 'uuid' => 'a-3', 'object' => ['name' => 'Third']];

		$result = $this->runner(plan: $plan, failing: ['a-1', 'a-3'])->run(point: self::point(), settings: new BackupSettings(ownerUid: 'admin'), adminUid: 'admin');

		$this->assertSame(['on: Restore in progress', 'save a-1', 'save a-2', 'save a-3', 'record', 'notify'], $this->calls);
		$this->assertSame('completed with 2 failures', $result['outcome']);
		$this->assertFalse($result['released']);
		$this->assertSame(1, $result['written']);
		$this->assertSame(['agent a-1: validation failed', 'agent a-3: validation failed'], $result['failures']);
		$this->assertSame('completed with 2 failures', $this->recorded?->context['outcome']);
		$this->assertSame(2, $this->notified['failures']);
		$this->assertSame(['agent a-1: validation failed', 'agent a-3: validation failed'], $this->notified['failed']);
	}//end testFailingSavesKeepTheAgentsStoppedAndAreNamed()

	/**
	 * A switch that was already on stays on and keeps its own reason.
	 *
	 * @return void
	 */
	public function testASwitchThatWasAlreadyOnIsLeftAsItWas(): void {
		$result = $this->runner(plan: self::plan(), wasEngaged: true)->run(point: self::point(), settings: new BackupSettings(ownerUid: 'admin'), adminUid: 'admin');

		$this->assertSame(['save a-1', 'save a-2', 'record', 'notify'], $this->calls);
		$this->assertFalse($result['released']);
		$this->assertSame('completed', $result['outcome']);
	}//end testASwitchThatWasAlreadyOnIsLeftAsItWas()

	/**
	 * Objects created after the point move to the deleted objects when chosen.
	 *
	 * @return void
	 */
	public function testLaterObjectsAreRemovedWhenChosen(): void {
		$result = $this->runner(plan: self::plan())->run(point: self::point(removeCreatedAfter: true), settings: new BackupSettings(ownerUid: 'admin'), adminUid: 'admin');

		$this->assertSame(['on: Restore in progress', 'save a-1', 'save a-2', 'delete a-9', 'off', 'record', 'notify'], $this->calls);
		$this->assertSame(1, $result['removed']);
	}//end testLaterObjectsAreRemovedWhenChosen()

	/**
	 * A refused point (a newer register version) writes nothing and touches no switch.
	 *
	 * @return void
	 */
	public function testARefusedPointIsRefusedBeforeAnything(): void {
		$runner = $this->runner(plan: self::plan(refused: 'This backup was written by register version 0.60.0'));

		try {
			$runner->run(point: self::point(), settings: new BackupSettings(ownerUid: 'admin'), adminUid: 'admin');
			$this->fail('a refused point must throw');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('0.60.0', $e->getMessage());
		}

		$this->assertSame([], $this->calls);
	}//end testARefusedPointIsRefusedBeforeAnything()
}//end class
