<?php

/**
 * Hermiq RestoreRunner.
 *
 * Restores one organisation to a backup or a moment (operations-backup-and-restore,
 * design D4):
 *
 * 1. engages the organisation's kill switch with the reason "Restore in progress"
 *    before the first write, so no schedule, flow or chat turn runs against
 *    half-restored data;
 * 2. writes each object back through OpenRegister's object service with its full
 *    content, so every change is on the audit trail (hermiq ADR-004);
 * 3. moves the objects created after the point to OpenRegister's deleted objects
 *    when the administrator chose that;
 * 4. releases the kill switch only when every write succeeded and it was not
 *    engaged before the restore;
 * 5. records the restore on the organisation's TenantControl audit trail (action
 *    `restore`) and notifies the administrator.
 *
 * A failing write does not stop the restore: the rest is still written, the
 * kill switch stays engaged and the record and notification name the failures,
 * so a person decides before the agents run again.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Backup
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

namespace OCA\Hermiq\Service\Backup;

use DateTime;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\OrganisationControlServiceInterface;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runs one restore.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
 */
class RestoreRunner {

	public const REASON = 'Restore in progress';

	public const AUDIT_ACTION = 'restore';

	public const NOTIFICATION_SUBJECT = 'restore_finished';

	/**
	 * How many failures the notification names; the record holds them all.
	 */
	private const NOTIFIED_FAILURES = 10;

	/**
	 * Constructor.
	 *
	 * @param RestorePlanner                      $planner       Works out what to write.
	 * @param OrganisationControlServiceInterface $killSwitch    The organisation's kill switch.
	 * @param ObjectService                       $objectService OpenRegister's object service (the single write path).
	 * @param AuditTrailMapper                    $auditTrail    Records the restore.
	 * @param INotificationManager                $notifications Tells the administrator how it went.
	 * @param LoggerInterface                     $logger        The logger.
	 */
	public function __construct(
		private readonly RestorePlanner $planner,
		private readonly OrganisationControlServiceInterface $killSwitch,
		private readonly ObjectService $objectService,
		private readonly AuditTrailMapper $auditTrail,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Restore one organisation to a point.
	 *
	 * @param RestorePoint   $point    The point.
	 * @param BackupSettings $settings The backup settings (the folder).
	 * @param string         $adminUid The administrator who asked for it.
	 *
	 * @return array{organisation: string, outcome: string, written: int, removed: int, failures: list<string>, released: bool} The result.
	 *
	 * @throws RuntimeException When the point is refused; nothing has been written then.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003
	 */
	public function run(RestorePoint $point, BackupSettings $settings, string $adminUid): array {
		$plan = $this->planner->plan(point: $point, settings: $settings);
		if ($plan['refused'] !== null) {
			throw new RuntimeException((string)$plan['refused']);
		}

		$organisation = (string)$plan['organisation'];
		$wasEngaged = $this->isEngaged(organisation: $organisation);
		$control = null;
		if ($wasEngaged === false) {
			$control = $this->killSwitch->toggle(organisation: $organisation, engaged: true, reason: self::REASON, actorUid: $adminUid);
		}

		$failures = [];
		$written = 0;
		foreach ($plan['writes'] as $write) {
			if ($this->write(organisation: $organisation, write: $write, failures: $failures) === true) {
				$written++;
			}
		}

		$removed = 0;
		if ($point->removeCreatedAfter === true) {
			foreach ($plan['createdAfter'] as $later) {
				if ($this->remove(later: $later, failures: $failures) === true) {
					$removed++;
				}
			}
		}

		$released = false;
		if ($failures === [] && $wasEngaged === false) {
			$control = $this->killSwitch->toggle(organisation: $organisation, engaged: false, reason: null, actorUid: $adminUid);
			$released = true;
		}

		$outcome = 'completed';
		if ($failures !== []) {
			$outcome = sprintf('completed with %d failures', count($failures));
		}

		$result = [
			'organisation' => $organisation,
			'outcome'      => $outcome,
			'written'      => $written,
			'removed'      => $removed,
			'failures'     => $failures,
			'released'     => $released,
		];
		$control = ($control ?? $this->killSwitch->getForOrganisation(organisation: $organisation));
		$this->record(control: $control, plan: $plan, result: $result, adminUid: $adminUid);
		$this->notify(adminUid: $adminUid, result: $result);

		return $result;
	}//end run()

	/**
	 * Whether the organisation's kill switch is engaged now.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return bool Whether it is.
	 */
	private function isEngaged(string $organisation): bool {
		$control = $this->killSwitch->getForOrganisation(organisation: $organisation);
		if ($control === null) {
			return false;
		}

		return (($control->getObject()['engaged'] ?? false) === true);
	}//end isEngaged()

	/**
	 * Write one object back, with its full content, pinned to the organisation.
	 *
	 * @param string                                                      $organisation The organisation.
	 * @param array{schema: string, uuid: string, object: array<string, mixed>} $write        The write.
	 * @param list<string>                                                $failures     Collects failures.
	 *
	 * @return bool Whether it was written.
	 */
	private function write(string $organisation, array $write, array &$failures): bool {
		$object = $write['object'];
		$object['@self'] = ['organisation' => $organisation];
		try {
			$this->objectService->saveObject(
				object: $object,
				register: BackupWriter::REGISTER_SLUG,
				schema: $write['schema'],
				uuid: $write['uuid'],
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $e) {
			$failures[] = $write['schema'] . ' ' . $write['uuid'] . ': ' . $e->getMessage();
			return false;
		}
	}//end write()

	/**
	 * Move one object created after the point to OpenRegister's deleted objects.
	 *
	 * @param array{schema: string, uuid: string} $later    The object.
	 * @param list<string>                        $failures Collects failures.
	 *
	 * @return bool Whether it was moved.
	 */
	private function remove(array $later, array &$failures): bool {
		try {
			$deleted = $this->objectService->deleteObject(
				uuid: $later['uuid'],
				register: BackupWriter::REGISTER_SLUG,
				schema: $later['schema'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$failures[] = $later['schema'] . ' ' . $later['uuid'] . ': ' . $e->getMessage();
			return false;
		}

		if ($deleted !== true) {
			$failures[] = $later['schema'] . ' ' . $later['uuid'] . ': not deleted';
			return false;
		}

		return true;
	}//end remove()

	/**
	 * Record the restore on the organisation's TenantControl audit trail.
	 *
	 * @param ObjectEntity|null    $control  The organisation's TenantControl.
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<string, mixed> $result   The result.
	 * @param string               $adminUid The administrator.
	 *
	 * @return void
	 */
	private function record(?ObjectEntity $control, array $plan, array $result, string $adminUid): void {
		if ($control === null) {
			$control = new ObjectEntity();
			$control->setOrganisation((string)$result['organisation']);
		}

		$counts = [];
		foreach ((array)$plan['schemas'] as $slug => $schemaCounts) {
			$counts[(string)$slug] = array_intersect_key((array)$schemaCounts, array_flip(RestorePlanner::COUNTS));
		}

		try {
			$this->auditTrail->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $control,
					action: self::AUDIT_ACTION,
					context: [
						'point'         => $plan['point'],
						'organisation'  => $result['organisation'],
						'administrator' => $adminUid,
						'counts'        => $counts,
						'written'       => $result['written'],
						'removed'       => $result['removed'],
						'failures'      => $result['failures'],
						'outcome'       => $result['outcome'],
						'released'      => $result['released'],
					]
				)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[RestoreRunner] The restore record could not be written',
				['organisation' => $result['organisation'], 'exception' => $e->getMessage(), 'app' => AppIdentity::APP_ID]
			);
		}
	}//end record()

	/**
	 * Tell the administrator how the restore went.
	 *
	 * @param string               $adminUid The administrator.
	 * @param array<string, mixed> $result   The result.
	 *
	 * @return void
	 */
	private function notify(string $adminUid, array $result): void {
		if ($adminUid === '') {
			return;
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(AppIdentity::APP_ID)
				->setUser($adminUid)
				->setDateTime(new DateTime())
				->setObject('organisation', (string)$result['organisation'])
				->setSubject(
					self::NOTIFICATION_SUBJECT,
					[
						'organisation' => (string)$result['organisation'],
						'failures'     => count($result['failures']),
						'failed'       => array_slice($result['failures'], 0, self::NOTIFIED_FAILURES),
						'released'     => $result['released'],
					]
				);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('[RestoreRunner] The restore notification could not be sent: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
		}
	}//end notify()
}//end class
