<?php

/**
 * Hermiq ReportSubscriptions.
 *
 * The signed-in person's subscriptions to reports on the Reports page
 * (reports-on-a-schedule 1.2): which reports she has sent to her, and
 * subscribing to or cancelling one. ReportSubscriptionJob does the sending.
 *
 * The person on a subscription is always the session user. The schema's own
 * authorization lets any authenticated user create a row for any person, so
 * the page writes through here rather than through the object API, and a
 * second subscription to the same report is not written: an existing row is
 * switched back on with the new frequency instead.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Read and change the session user's report subscriptions.
 *
 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
 */
class ReportSubscriptions {

	/**
	 * The register the subscriptions live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'hermiq';

	/**
	 * The subscription schema.
	 *
	 * @var string
	 */
	private const SCHEMA = 'reportsubscription';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The person's subscriptions, one row per report.
	 *
	 * @param string $person The session user.
	 *
	 * @return list<array{id: string, report: string, frequency: string, status: string}>
	 *
	 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
	 */
	public function mine(string $person): array {
		$rows = [];
		foreach ($this->find(filters: ['person' => $person]) as $entity) {
			$row = self::row(entity: $entity);
			if ($row['report'] !== '') {
				$rows[$row['report']] = $row;
			}
		}

		return array_values($rows);
	}//end mine()

	/**
	 * Send a report to the person weekly or monthly.
	 *
	 * @param string $person    The session user.
	 * @param string $report    The report, by its card id.
	 * @param string $frequency `weekly` or `monthly`.
	 *
	 * @return array{id: string, report: string, frequency: string, status: string} The subscription.
	 *
	 * @throws InvalidArgumentException When the report is empty or the frequency is neither.
	 *
	 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
	 */
	public function subscribe(string $person, string $report, string $frequency): array {
		$report = trim($report);
		if ($report === '') {
			throw new InvalidArgumentException('Name the report to send.');
		}

		if (in_array($frequency, [ReportSubscriptionSchedule::WEEKLY, ReportSubscriptionSchedule::MONTHLY], true) === false) {
			throw new InvalidArgumentException('A report is sent weekly or monthly.');
		}

		$existing = $this->subscriptionTo(person: $person, report: $report);
		$data = ['person' => $person, 'report' => $report, 'frequency' => $frequency, 'status' => ReportSubscriptionSchedule::ACTIVE];
		if ($existing !== null) {
			$data = array_merge($existing->getObject(), $data);
		}

		return self::row(entity: $this->save(data: $data, uuid: $existing?->getUuid()));
	}//end subscribe()

	/**
	 * Stop sending a report to the person.
	 *
	 * @param string $person The session user.
	 * @param string $report The report, by its card id.
	 *
	 * @return bool True when an active subscription was cancelled.
	 *
	 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
	 */
	public function cancel(string $person, string $report): bool {
		$existing = $this->subscriptionTo(person: $person, report: trim($report));
		if ($existing === null || ($existing->getObject()['status'] ?? '') !== ReportSubscriptionSchedule::ACTIVE) {
			return false;
		}

		$this->save(
			data: array_merge($existing->getObject(), ['status' => ReportSubscriptionSchedule::CANCELLED]),
			uuid: $existing->getUuid()
		);

		return true;
	}//end cancel()

	/**
	 * The person's subscription to one report, or null.
	 *
	 * @param string $person The session user.
	 * @param string $report The report.
	 *
	 * @return ObjectEntity|null
	 */
	private function subscriptionTo(string $person, string $report): ?ObjectEntity {
		foreach ($this->find(filters: ['person' => $person, 'report' => $report]) as $entity) {
			if (($entity->getObject()['person'] ?? '') === $person && ($entity->getObject()['report'] ?? '') === $report) {
				return $entity;
			}
		}

		return null;
	}//end subscriptionTo()

	/**
	 * Subscriptions matching the filters, as the caller sees them.
	 *
	 * @param array<string, string> $filters Field filters.
	 *
	 * @return list<ObjectEntity>
	 */
	private function find(array $filters): array {
		$found = $this->objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
			config: ['filters' => $filters, 'limit' => 100]
		);

		return array_values(array_filter($found, static fn (mixed $row): bool => $row instanceof ObjectEntity));
	}//end find()

	/**
	 * Save a subscription.
	 *
	 * @param array<string, mixed> $data The subscription.
	 * @param string|null          $uuid The row to update, or null to create.
	 *
	 * @return ObjectEntity The saved row.
	 */
	private function save(array $data, ?string $uuid): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA), uuid: $uuid)
		);
	}//end save()

	/**
	 * The row the page reads for a subscription.
	 *
	 * @param ObjectEntity $entity The subscription.
	 *
	 * @return array{id: string, report: string, frequency: string, status: string}
	 */
	private static function row(ObjectEntity $entity): array {
		$data = $entity->getObject();

		return [
			'id' => (string)$entity->getUuid(),
			'report' => (string)($data['report'] ?? ''),
			'frequency' => (string)($data['frequency'] ?? ''),
			'status' => (string)($data['status'] ?? ReportSubscriptionSchedule::ACTIVE),
		];
	}//end row()
}//end class
