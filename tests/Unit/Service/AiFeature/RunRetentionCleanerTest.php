<?php

/**
 * Hermiq RunRetentionCleaner unit tests.
 *
 * Covers what retention removes and what it must keep: the payload goes, the chain
 * entry stays, and the tombstone still says a run happened, when, for which feature
 * and provider, and that the rest was deleted under retention.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
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
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-retention-must-remove-the-payload-and-must-not-break-the-chain
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use DateTimeImmutable;
use OCA\Hermiq\Service\AiFeature\RunRetentionCleaner;
use OCA\Hermiq\Service\AiFeature\RunRetentionPolicy;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * RunRetentionCleaner unit tests.
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-retention-must-remove-the-payload-and-must-not-break-the-chain
 */
class RunRetentionCleanerTest extends TestCase {

	/**
	 * The entries the mapper double holds, so a test can read back what a cleanup
	 * left rather than what it hoped it left.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $entries = [];

	/**
	 * How many times the mapper's update() was called, which is the count of rows
	 * the chain would actually have to carry a change for.
	 *
	 * @var int
	 */
	private int $updates = 0;

	/**
	 * One run entry.
	 *
	 * @param string $uuid The entry uuid.
	 * @param array<string, mixed> $changed The run payload.
	 *
	 * @return AuditTrail The entry.
	 */
	private function entry(string $uuid, array $changed): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid($uuid);
		$entry->setAction('run');
		$entry->setChanged($changed);
		return $entry;
	}//end entry()

	/**
	 * An AuditTrailMapper double serving the given entries and recording updates.
	 * It never deletes, because nothing in this change may delete a chain entry:
	 * a double that offered a delete would let a wrong implementation pass.
	 *
	 * @param array<int, AuditTrail> $entries The stored entries.
	 *
	 * @return AuditTrailMapper The double.
	 */
	private function mapper(array $entries): AuditTrailMapper {
		$this->entries = $entries;
		$this->updates = 0;

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = null, ?string $search = null): array {
				if (($filters['action'] ?? '') !== 'run') {
					return [];
				}

				return $this->entries;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (AuditTrail $entry): AuditTrail {
				$this->updates++;
				return $entry;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * A policy double that only has to record the cleanup.
	 *
	 * @return RunRetentionPolicy The double.
	 */
	private function policy(): RunRetentionPolicy {
		return $this->createMock(RunRetentionPolicy::class);
	}//end policy()

	/**
	 * An expired run loses its payload, and an unexpired one is untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-expired-runs-lose-their-payload
	 */
	public function testExpiredRunsLoseTheirPayload(): void {
		$expired = $this->entry(
			'run-1',
			[
				'summary' => 'Mevrouw De Vries vraagt om uitstel van betaling',
				'prompt' => 'Vat deze zaak samen',
				'feature' => 'samenvatten',
				'retentionDays' => 90,
				'retentionExpiresAt' => '2026-01-01T00:00:00+00:00',
			]
		);

		$fresh = $this->entry(
			'run-2',
			[
				'summary' => 'Nog een zaak',
				'retentionDays' => 90,
				'retentionExpiresAt' => '2027-01-01T00:00:00+00:00',
			]
		);

		$cleaner = new RunRetentionCleaner($this->mapper([$expired, $fresh]), $this->policy(), new NullLogger());

		$removed = $cleaner->clean(now: new DateTimeImmutable('2026-06-01T00:00:00+00:00'));

		$this->assertSame(1, $removed);
		$this->assertArrayNotHasKey('summary', (array)$expired->getChanged());
		$this->assertArrayNotHasKey('prompt', (array)$expired->getChanged());
		$this->assertSame('Nog een zaak', $fresh->getChanged()['summary']);
	}//end testExpiredRunsLoseTheirPayload()

	/**
	 * The processing is still recorded after the data is gone: when it happened, for
	 * which feature and provider, and that the payload went under retention.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-the-processing-is-still-recorded-after-the-data-is-gone
	 */
	public function testTheProcessingIsStillRecordedAfterTheDataIsGone(): void {
		$cleaner = new RunRetentionCleaner($this->mapper([]), $this->policy(), new NullLogger());

		$tombstone = $cleaner->tombstone(
			changed: [
				'summary' => 'Mevrouw De Vries vraagt om uitstel van betaling',
				'prompt' => 'Vat deze zaak samen',
				'feature' => 'samenvatten',
				'startedAt' => '2026-01-01T09:00:00+00:00',
				'retentionDays' => 90,
				'providerDisclosure' => [
					'feature' => 'samenvatten',
					'provider' => 'ollama',
					'model' => 'llama3',
					'residency' => 'on-premise',
					'location' => 'Serverruimte Stadskantoor',
					'input' => 'should never survive',
				],
			],
			at: new DateTimeImmutable('2026-06-01T00:00:00+00:00')
		);

		$this->assertSame(RunRetentionCleaner::TOMBSTONE_MARKER, $tombstone['retention']);
		$this->assertStringStartsWith('2026-06-01', $tombstone['deletedUnderRetentionAt']);
		$this->assertSame('samenvatten', $tombstone['feature']);
		$this->assertSame('2026-01-01T09:00:00+00:00', $tombstone['startedAt']);
		$this->assertSame('ollama', $tombstone['providerDisclosure']['provider']);
		$this->assertSame('on-premise', $tombstone['providerDisclosure']['residency']);
	}//end testTheProcessingIsStillRecordedAfterTheDataIsGone()

	/**
	 * No personal data survives the tombstone, including anything that travelled
	 * inside the provider disclosure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-no-personal-data-survives-the-tombstone
	 */
	public function testNoPersonalDataSurvivesTheTombstone(): void {
		$cleaner = new RunRetentionCleaner($this->mapper([]), $this->policy(), new NullLogger());

		$tombstone = $cleaner->tombstone(
			changed: [
				'summary' => 'Mevrouw De Vries vraagt om uitstel van betaling',
				'prompt' => 'Vat deze zaak samen',
				'steps' => [['name' => 'llm', 'arguments' => 'burgerservicenummer 123456789']],
				'providerDisclosure' => ['provider' => 'ollama', 'input' => 'burgerservicenummer 123456789'],
			],
			at: new DateTimeImmutable('2026-06-01T00:00:00+00:00')
		);

		$flattened = (string)json_encode($tombstone);

		$this->assertStringNotContainsString('De Vries', $flattened);
		$this->assertStringNotContainsString('123456789', $flattened);
		$this->assertArrayNotHasKey('steps', $tombstone);
		$this->assertArrayNotHasKey('input', $tombstone['providerDisclosure']);
	}//end testNoPersonalDataSurvivesTheTombstone()

	/**
	 * Nothing is deleted, only updated. Removing a row from a hash and previousHash
	 * chain invalidates every hash after it, which is the property the chain exists
	 * for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-the-chain-still-verifies-after-a-cleanup
	 */
	public function testACleanupUpdatesEntriesAndDeletesNone(): void {
		$expired = $this->entry(
			'run-1',
			['summary' => 'gone', 'retentionExpiresAt' => '2026-01-01T00:00:00+00:00']
		);

		$cleaner = new RunRetentionCleaner($this->mapper([$expired]), $this->policy(), new NullLogger());

		$cleaner->clean(now: new DateTimeImmutable('2026-06-01T00:00:00+00:00'));

		$this->assertSame(1, $this->updates);
		$this->assertCount(1, $this->entries);
		$this->assertSame('run-1', $this->entries[0]->getUuid());
	}//end testACleanupUpdatesEntriesAndDeletesNone()

	/**
	 * A second cleanup does not tombstone an entry twice, so the report counts work
	 * done rather than entries looked at.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-expired-runs-lose-their-payload
	 */
	public function testASecondCleanupTombstonesNothingAgain(): void {
		$expired = $this->entry(
			'run-1',
			['summary' => 'gone', 'retentionExpiresAt' => '2026-01-01T00:00:00+00:00']
		);

		$cleaner = new RunRetentionCleaner($this->mapper([$expired]), $this->policy(), new NullLogger());

		$cleaner->clean(now: new DateTimeImmutable('2026-06-01T00:00:00+00:00'));
		$second = $cleaner->clean(now: new DateTimeImmutable('2026-06-02T00:00:00+00:00'));

		$this->assertSame(0, $second);
	}//end testASecondCleanupTombstonesNothingAgain()

	/**
	 * A run written before this change carries no retention, and is left alone. A
	 * rule applied backwards would delete records under a promise they never made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-retention-must-remove-the-payload-and-must-not-break-the-chain
	 */
	public function testAnEntryWithNoRecordedRetentionIsLeftAlone(): void {
		$legacy = $this->entry('run-0', ['summary' => 'written before retention existed']);

		$cleaner = new RunRetentionCleaner($this->mapper([$legacy]), $this->policy(), new NullLogger());

		$removed = $cleaner->clean(now: new DateTimeImmutable('2030-01-01T00:00:00+00:00'));

		$this->assertSame(0, $removed);
		$this->assertSame('written before retention existed', $legacy->getChanged()['summary']);
	}//end testAnEntryWithNoRecordedRetentionIsLeftAlone()

	/**
	 * The cleanup reports what it did, so enforcement is checkable rather than
	 * assumed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-a-scheduled-job-must-enforce-retention-and-must-report-that-it-did
	 */
	public function testTheCleanupRecordsWhatItDid(): void {
		$expired = $this->entry('run-1', ['summary' => 'gone', 'retentionExpiresAt' => '2026-01-01T00:00:00+00:00']);

		$policy = $this->createMock(RunRetentionPolicy::class);
		$policy->expects($this->once())
			->method('recordCleanup')
			->with(1, $this->anything())
			->willReturn(['ran' => true, 'at' => '2026-06-01T00:00:00+00:00', 'removed' => 1]);

		$cleaner = new RunRetentionCleaner($this->mapper([$expired]), $policy, new NullLogger());

		$cleaner->clean(now: new DateTimeImmutable('2026-06-01T00:00:00+00:00'));
	}//end testTheCleanupRecordsWhatItDid()
}//end class
