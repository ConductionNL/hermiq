<?php

/**
 * Unit tests for the RenameDutchColumns repair step.
 *
 * The step finds the shard tables of the hermiq registers, renames each
 * Dutch column whose English twin is absent, back-fills the English column
 * when both exist, and degrades to a no-op or a warning when the database
 * cannot answer.
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

use OCA\Hermiq\Repair\RenameDutchColumns;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RenameDutchColumns.
 *
 * @covers \OCA\Hermiq\Repair\RenameDutchColumns
 * @uses   \OCA\Hermiq\Repair\ColumnMigrationRun
 */
class RenameDutchColumnsTest extends TestCase {

	/**
	 * Statements the step executed.
	 *
	 * @var list<string>
	 */
	private array $executed = [];

	/**
	 * A prepared statement yielding the given rows.
	 *
	 * @param list<array<string, string>> $rows Rows to fetch.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(array $rows): IPreparedStatement {
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		return $stmt;
	}

	/**
	 * A connection over the given registers, tables and per-table columns.
	 *
	 * @param list<int>|DbException       $registerIds Register ids, or the error resolving them.
	 * @param list<string>                $tables      Table names information_schema lists.
	 * @param array<string, list<string>> $columns     Table name → column names.
	 * @param list<string>                $failing     Statements (substring) that throw.
	 *
	 * @return IDBConnection
	 */
	private function connection(array|DbException $registerIds, array $tables, array $columns = [], array $failing = []): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		if ($registerIds instanceof DbException) {
			$db->method('executeQuery')->willThrowException($registerIds);
		} else {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->with(\PDO::FETCH_COLUMN)->willReturn($registerIds);
			$db->method('executeQuery')
				->with('SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?', ['hermiq%'])
				->willReturn($result);
		}

		$tableNames = array_map(static fn (string $t): array => ['table_name' => $t], $tables);
		$db->method('prepare')->willReturnCallback(function (string $sql) use ($tableNames, $columns) {
			if (str_contains($sql, 'information_schema.tables') === true) {
				return $this->statement($tableNames);
			}
			$stmt = $this->createMock(IPreparedStatement::class);
			$current = '';
			$stmt->method('bindValue')->willReturnCallback(function ($name, $value) use (&$current) {
				$current = $value;
				return true;
			});
			$rows = null;
			$stmt->method('fetch')->willReturnCallback(function () use (&$current, &$rows, $columns) {
				if ($rows === null) {
					$rows = array_map(static fn (string $c): array => ['column_name' => $c], ($columns[$current] ?? []));
				}
				return (array_shift($rows) ?? false);
			});
			return $stmt;
		});

		$db->method('getDatabasePlatform')->willReturn(new class {
			/**
			 * Quote like MySQL does.
			 *
			 * @param string $identifier The identifier.
			 *
			 * @return string
			 */
			public function quoteSingleIdentifier(string $identifier): string {
				return '`' . $identifier . '`';
			}
		});

		$db->method('executeStatement')->willReturnCallback(function (string $sql) use ($failing): int {
			foreach ($failing as $needle) {
				if (str_contains($sql, $needle) === true) {
					throw new DbException('denied');
				}
			}
			$this->executed[] = $sql;
			return 1;
		});
		return $db;
	}

	/**
	 * An output expecting exactly the given info line.
	 *
	 * @param string $line The expected line.
	 *
	 * @return IOutput
	 */
	private function outputExpecting(string $line): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with($line);
		return $output;
	}

	/**
	 * The step has a human-readable name.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new RenameDutchColumns($this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
		self::assertSame('Move hermiq data from the Dutch columns to the English ones', $step->getName());
	}

	/**
	 * A Dutch column without an English twin is renamed; with one it is back-filled.
	 *
	 * @return void
	 */
	public function testRenamesOrBackfillsEachDutchColumn(): void {
		$db = $this->connection(
			[7],
			['oc_openregister_table_7_12'],
			['oc_openregister_table_7_12' => ['id', 'soort', 'organisatie', 'organisation', 'unrelated']]
		);
		$step = new RenameDutchColumns($db, $this->createMock(LoggerInterface::class));

		$step->run($this->outputExpecting('RenameDutchColumns: 1 renamed, 1 back-filled, 0 refused, across 1 shard table(s).'));

		self::assertSame([
			'UPDATE `oc_openregister_table_7_12` SET `organisation` = `organisatie` WHERE `organisation` IS NULL AND `organisatie` IS NOT NULL',
			'ALTER TABLE `oc_openregister_table_7_12` RENAME COLUMN `soort` TO `kind`',
		], $this->executed);
	}

	/**
	 * Only shards of the hermiq registers, with a numeric suffix, are migrated, each once.
	 *
	 * @return void
	 */
	public function testOnlyMigratesTheShardsOfTheHermiqRegisters(): void {
		$db = $this->connection(
			[3, 30],
			['oc_openregister_table_3_1', 'oc_openregister_table_3_x', 'oc_openregister_table_4_1', 'oc_openregister_table_30_2'],
			[
				'oc_openregister_table_3_1' => ['referentie'],
				'oc_openregister_table_3_x' => ['referentie'],
				'oc_openregister_table_4_1' => ['referentie'],
				'oc_openregister_table_30_2' => ['verantwoordelijke'],
			]
		);
		$step = new RenameDutchColumns($db, $this->createMock(LoggerInterface::class));

		$step->run($this->outputExpecting('RenameDutchColumns: 2 renamed, 0 back-filled, 0 refused, across 2 shard table(s).'));

		self::assertSame([
			'ALTER TABLE `oc_openregister_table_3_1` RENAME COLUMN `referentie` TO `reference`',
			'ALTER TABLE `oc_openregister_table_30_2` RENAME COLUMN `verantwoordelijke` TO `responsible`',
		], $this->executed);
	}

	/**
	 * A failed statement is logged and not counted; the rest of the table still migrates.
	 *
	 * @return void
	 */
	public function testAFailedStatementIsNotCounted(): void {
		$db = $this->connection(
			[7],
			['oc_openregister_table_7_1'],
			['oc_openregister_table_7_1' => ['soort', 'referentie']],
			['`soort`']
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'RenameDutchColumns: statement failed; leaving the column as it was.',
			self::callback(static fn (array $c): bool => $c['exception'] === 'denied' && str_contains($c['sql'], '`soort` TO `kind`'))
		);

		(new RenameDutchColumns($db, $logger))->run(
			$this->outputExpecting('RenameDutchColumns: 1 renamed, 0 back-filled, 0 refused, across 1 shard table(s).')
		);

		self::assertSame(['ALTER TABLE `oc_openregister_table_7_1` RENAME COLUMN `referentie` TO `reference`'], $this->executed);
	}

	/**
	 * Without hermiq registers nothing is listed or changed.
	 *
	 * @return void
	 */
	public function testNoRegistersIsANoOp(): void {
		$db = $this->connection([], ['oc_openregister_table_7_1'], ['oc_openregister_table_7_1' => ['soort']]);
		$db->expects(self::never())->method('prepare');

		(new RenameDutchColumns($db, $this->createMock(LoggerInterface::class)))->run(
			$this->outputExpecting('RenameDutchColumns: no hermiq shard tables on this install; nothing to do.')
		);

		self::assertSame([], $this->executed);
	}

	/**
	 * No shard tables at all is a no-op too.
	 *
	 * @return void
	 */
	public function testNoTablesIsANoOp(): void {
		$db = $this->connection([7], []);

		(new RenameDutchColumns($db, $this->createMock(LoggerInterface::class)))->run(
			$this->outputExpecting('RenameDutchColumns: no hermiq shard tables on this install; nothing to do.')
		);

		self::assertSame([], $this->executed);
	}

	/**
	 * A register lookup error is logged and the step does nothing.
	 *
	 * @return void
	 */
	public function testARegisterLookupErrorSkips(): void {
		$db = $this->connection(new DbException('no table'), []);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('RenameDutchColumns: could not resolve the registers; skipping.', ['exception' => 'no table']);

		(new RenameDutchColumns($db, $logger))->run(
			$this->outputExpecting('RenameDutchColumns: no hermiq shard tables on this install; nothing to do.')
		);
	}

	/**
	 * A database that cannot list tables (no information_schema) skips.
	 *
	 * @return void
	 */
	public function testATableListingErrorSkips(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([7]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);
		$db->method('prepare')->willThrowException(new \RuntimeException('no information_schema'));
		$db->expects(self::never())->method('executeStatement');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('RenameDutchColumns: could not list tables; skipping.', ['exception' => 'no information_schema']);

		(new RenameDutchColumns($db, $logger))->run(
			$this->outputExpecting('RenameDutchColumns: no hermiq shard tables on this install; nothing to do.')
		);
	}

	/**
	 * A table whose columns cannot be read is skipped, still counted as a shard.
	 *
	 * @return void
	 */
	public function testAColumnListingErrorSkipsThatTable(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([7]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);
		$tables = $this->statement([['table_name' => 'oc_openregister_table_7_1']]);
		$db->method('prepare')->willReturnCallback(static function (string $sql) use ($tables) {
			if (str_contains($sql, 'information_schema.tables') === true) {
				return $tables;
			}
			throw new \RuntimeException('denied');
		});
		$db->expects(self::never())->method('executeStatement');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'RenameDutchColumns: could not read columns; skipping table.',
			['table' => 'oc_openregister_table_7_1', 'exception' => 'denied']
		);

		(new RenameDutchColumns($db, $logger))->run(
			$this->outputExpecting('RenameDutchColumns: 0 renamed, 0 back-filled, 0 refused, across 1 shard table(s).')
		);
	}
}
