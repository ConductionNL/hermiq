<?php

/**
 * Unit tests for ReportSubscriptions: the session user's subscriptions to the Reports page cards.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-on-a-schedule/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Hermiq\Service\ReportSubscriptions;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Subscribing, switching back on, cancelling and listing, always for the session user.
 */
class ReportSubscriptionsTest extends TestCase {

	/**
	 * What saveObject() received, as [object, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $saved = [];

	/**
	 * A stored subscription.
	 *
	 * @param string               $uuid The row.
	 * @param array<string, mixed> $data Its fields.
	 *
	 * @return ObjectEntity
	 */
	private function row(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end row()

	/**
	 * The service over an object store holding `$stored`.
	 *
	 * @param list<ObjectEntity> $stored The rows findAll() answers.
	 * @param array|null         $config Receives the last findAll() config.
	 *
	 * @return ReportSubscriptions
	 */
	private function service(array $stored, ?array &$config = null): ReportSubscriptions {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config_ = []) use ($stored, &$config): array {
				$config = $config_;
				return $stored;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$this->saved[] = [$object, $options->uuid];
				return $this->row(uuid: $options->uuid ?? 'new-row', data: $object);
			}
		);

		return new ReportSubscriptions(objectService: $objects);
	}//end service()

	/**
	 * A first subscription is written for the session user, active, with the chosen frequency.
	 *
	 * @return void
	 */
	public function testSubscribingWritesAnActiveRowForTheSessionUser(): void {
		$row = $this->service(stored: [])->subscribe(person: 'anna', report: 'AiOversight', frequency: 'weekly');

		$this->assertSame(['id' => 'new-row', 'report' => 'AiOversight', 'frequency' => 'weekly', 'status' => 'active'], $row);
		$this->assertSame(
			[[['person' => 'anna', 'report' => 'AiOversight', 'frequency' => 'weekly', 'status' => 'active'], null]],
			$this->saved
		);
	}//end testSubscribingWritesAnActiveRowForTheSessionUser()

	/**
	 * Subscribing again switches the existing row back on with the new frequency, keeping its other fields.
	 *
	 * @return void
	 */
	public function testSubscribingAgainReusesTheRow(): void {
		$stored = $this->row(uuid: 'sub-1', data: ['person' => 'anna', 'report' => 'AiOversight', 'frequency' => 'weekly', 'status' => 'cancelled', 'lastSent' => '2026-11-02T08:00:00+00:00']);

		$row = $this->service(stored: [$stored])->subscribe(person: 'anna', report: 'AiOversight', frequency: 'monthly');

		$this->assertSame('sub-1', $row['id']);
		$this->assertSame('monthly', $row['frequency']);
		$this->assertSame('active', $row['status']);
		$this->assertSame('sub-1', $this->saved[0][1]);
		$this->assertSame('2026-11-02T08:00:00+00:00', $this->saved[0][0]['lastSent']);
	}//end testSubscribingAgainReusesTheRow()

	/**
	 * Another person's row for the same report is never taken over.
	 *
	 * @return void
	 */
	public function testAnotherPersonsRowIsNotReused(): void {
		$theirs = $this->row(uuid: 'sub-2', data: ['person' => 'kees', 'report' => 'AiOversight', 'frequency' => 'weekly', 'status' => 'active']);

		$this->service(stored: [$theirs])->subscribe(person: 'anna', report: 'AiOversight', frequency: 'weekly');

		$this->assertNull($this->saved[0][1], 'a new row is written');
		$this->assertSame('anna', $this->saved[0][0]['person']);
	}//end testAnotherPersonsRowIsNotReused()

	/**
	 * A frequency other than weekly or monthly, or no report, is refused before anything is written.
	 *
	 * @return void
	 */
	public function testABadFrequencyOrNoReportIsRefused(): void {
		$service = $this->service(stored: []);
		foreach ([['AiOversight', 'daily'], ['  ', 'weekly']] as [$report, $frequency]) {
			try {
				$service->subscribe(person: 'anna', report: $report, frequency: $frequency);
				$this->fail('refused: ' . $report . '/' . $frequency);
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

		$this->assertSame([], $this->saved);
	}//end testABadFrequencyOrNoReportIsRefused()

	/**
	 * Cancelling marks the active row cancelled; cancelling nothing active writes nothing.
	 *
	 * @return void
	 */
	public function testCancelling(): void {
		$active = $this->row(uuid: 'sub-1', data: ['person' => 'anna', 'report' => 'AiOversight', 'frequency' => 'weekly', 'status' => 'active']);
		$this->assertTrue($this->service(stored: [$active])->cancel(person: 'anna', report: 'AiOversight'));
		$this->assertSame('cancelled', $this->saved[0][0]['status']);
		$this->assertSame('sub-1', $this->saved[0][1]);

		$this->saved = [];
		$cancelled = $this->row(uuid: 'sub-1', data: ['person' => 'anna', 'report' => 'AiOversight', 'frequency' => 'weekly', 'status' => 'cancelled']);
		$this->assertFalse($this->service(stored: [$cancelled])->cancel(person: 'anna', report: 'AiOversight'));
		$this->assertFalse($this->service(stored: [])->cancel(person: 'anna', report: 'AiOversight'));
		$this->assertSame([], $this->saved);
	}//end testCancelling()

	/**
	 * The list asks for the person's rows only and answers one row per report.
	 *
	 * @return void
	 */
	public function testMineListsOneRowPerReport(): void {
		$config = null;
		$rows = $this->service(
			stored: [$this->row(uuid: 'sub-1', data: ['person' => 'anna', 'report' => 'AiOversight', 'frequency' => 'monthly', 'status' => 'active'])],
			config: $config
		)->mine(person: 'anna');

		$this->assertSame([['id' => 'sub-1', 'report' => 'AiOversight', 'frequency' => 'monthly', 'status' => 'active']], $rows);
		$this->assertSame(['person' => 'anna'], $config['filters']);
	}//end testMineListsOneRowPerReport()
}//end class
