<?php

/**
 * Unit tests for RunRetentionCleaner's expiry judgement and its behaviour when
 * the audit trail cannot be read.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use DateTimeImmutable;
use OCA\Hermiq\Service\AiFeature\RunRetentionCleaner;
use OCA\Hermiq\Service\AiFeature\RunRetentionPolicy;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Only an entry with a parseable expiry in the past is expired, and an unreadable trail removes nothing.
 */
#[CoversClass(RunRetentionCleaner::class)]
final class RunRetentionCleanerEdgeTest extends TestCase {

	private AuditTrailMapper&MockObject $mapper;
	private RunRetentionPolicy&MockObject $policy;
	private LoggerInterface&MockObject $logger;
	private RunRetentionCleaner $cleaner;
	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->mapper  = $this->createMock(AuditTrailMapper::class);
		$this->policy  = $this->createMock(RunRetentionPolicy::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->cleaner = new RunRetentionCleaner($this->mapper, $this->policy, $this->logger);
		$this->now     = new DateTimeImmutable('2026-06-01T12:00:00+00:00');
	}//end setUp()

	/**
	 * A run entry with the given payload.
	 *
	 * @param array<string, mixed> $changed The payload.
	 *
	 * @return AuditTrail
	 */
	private function entry(array $changed): AuditTrail {
		$entry = new AuditTrail();
		$entry->setChanged($changed);
		return $entry;
	}//end entry()

	public function testAnEntryWithoutAnExpiryNeverExpires(): void {
		$this->assertFalse($this->cleaner->isExpired($this->entry(['prompt' => 'x']), $this->now));
		$this->assertFalse($this->cleaner->isExpired($this->entry(['retentionExpiresAt' => '']), $this->now));
	}//end testAnEntryWithoutAnExpiryNeverExpires()

	public function testAnUnparseableExpiryIsKeptRatherThanRemoved(): void {
		$this->assertFalse($this->cleaner->isExpired($this->entry(['retentionExpiresAt' => 'not a date']), $this->now));
	}//end testAnUnparseableExpiryIsKeptRatherThanRemoved()

	public function testAnExpiryAtOrBeforeNowIsExpiredAndAfterItIsNot(): void {
		$this->assertTrue($this->cleaner->isExpired($this->entry(['retentionExpiresAt' => '2026-06-01T12:00:00+00:00']), $this->now));
		$this->assertTrue($this->cleaner->isExpired($this->entry(['retentionExpiresAt' => '2026-05-01T00:00:00+00:00']), $this->now));
		$this->assertFalse($this->cleaner->isExpired($this->entry(['retentionExpiresAt' => '2026-06-01T12:00:01+00:00']), $this->now));
	}//end testAnExpiryAtOrBeforeNowIsExpiredAndAfterItIsNot()

	public function testATombstoneIsNeverExpiredAgain(): void {
		$entry = $this->entry(['retention' => RunRetentionCleaner::TOMBSTONE_MARKER, 'retentionExpiresAt' => '2020-01-01T00:00:00+00:00']);

		$this->assertFalse($this->cleaner->isExpired($entry, $this->now));
	}//end testATombstoneIsNeverExpiredAgain()

	public function testAnUnreadableTrailIsLoggedPerActionAndRemovesNothing(): void {
		$actions = [];
		$this->mapper->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			static function (?int $limit, ?int $offset, ?array $filters, ?array $sort) use (&$actions): array {
				TestCase::assertSame(500, $limit);
				TestCase::assertSame(0, $offset);
				TestCase::assertSame(['created' => 'ASC'], $sort);
				$actions[] = $filters['action'];
				throw new RuntimeException('db gone');
			}
		);
		$this->mapper->expects($this->never())->method('update');
		$this->logger->expects($this->exactly(2))->method('warning')->with('Hermiq could not read run entries for retention: db gone', $this->anything());
		$this->policy->expects($this->once())->method('recordCleanup')->with(0, $this->now);

		$this->assertSame(0, $this->cleaner->clean($this->now));
		$this->assertSame(['run', 'agent-run'], $actions);
	}//end testAnUnreadableTrailIsLoggedPerActionAndRemovesNothing()

	public function testOnlyAuditTrailRowsAreConsidered(): void {
		$expired = $this->entry(['retentionExpiresAt' => '2026-01-01T00:00:00+00:00']);
		$this->mapper->method('findAll')->willReturnOnConsecutiveCalls([new \stdClass(), $expired], []);
		$this->mapper->expects($this->once())->method('update')->with($expired)->willReturn($expired);
		$this->policy->expects($this->once())->method('recordCleanup')->with(1, $this->now);

		$this->assertSame(1, $this->cleaner->clean($this->now));
		$this->assertSame(RunRetentionCleaner::TOMBSTONE_MARKER, $expired->getChanged()['retention']);
	}//end testOnlyAuditTrailRowsAreConsidered()
}//end class
