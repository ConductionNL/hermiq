<?php

/**
 * Unit tests for DatabaseRunTokenStore.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\DatabaseRunTokenStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each store operation builds: table, columns, predicates and bound values.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */
final class DatabaseRunTokenStoreTest extends TestCase {

	/**
	 * Every call made on the query builder, in order, as [method, args].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $calls = [];

	/**
	 * Values bound through createNamedParameter(), keyed by the placeholder handed back.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * A query builder that records its calls and returns itself for chaining.
	 *
	 * @param array|false $row The row the executed SELECT yields.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(array|false $row = false): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);

		foreach (['insert', 'values', 'select', 'from', 'where', 'setMaxResults', 'update', 'set', 'delete'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($method, $qb) {
					$this->calls[] = [$method, $args];
					return $qb;
				}
			);
		}

		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value): string {
				$placeholder = ':p' . count($this->params);
				$this->params[$placeholder] = $value;
				return $placeholder;
			}
		);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($col, $val): string => $col . ' = ' . $val);
		$expr->method('lt')->willReturnCallback(static fn ($col, $val): string => $col . ' < ' . $val);
		$qb->method('expr')->willReturn($expr);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturnCallback(
			function (): int {
				$this->calls[] = ['executeStatement', []];
				return 1;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * A store over a connection handing out the given builder.
	 *
	 * @param IQueryBuilder $qb The builder.
	 *
	 * @return DatabaseRunTokenStore
	 */
	private function store(IQueryBuilder $qb): DatabaseRunTokenStore {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return new DatabaseRunTokenStore($db);
	}//end store()

	/**
	 * The calls made with the given method name.
	 *
	 * @param string $method The method name.
	 *
	 * @return array<int, array<int, mixed>> The argument lists.
	 */
	private function callsTo(string $method): array {
		return array_values(
			array_map(
				static fn (array $call): array => $call[1],
				array_filter($this->calls, static fn (array $call): bool => $call[0] === $method)
			)
		);
	}//end callsTo()

	/**
	 * put() inserts one unconsumed row keyed by the digest, with both deadlines.
	 *
	 * @return void
	 */
	public function testPutInsertsAnUnconsumedRow(): void {
		$this->store($this->queryBuilder())->put(digest: 'abc', record: '{"hash":"abc"}', expiresAt: 150, retainUntil: 3750);

		$this->assertSame(DatabaseRunTokenStore::TABLE, $this->callsTo('insert')[0][0]);
		$values = $this->callsTo('values')[0][0];
		$bound = array_map(fn (string $placeholder) => $this->params[$placeholder], $values);
		$this->assertSame(
			['token_hash' => 'abc', 'record' => '{"hash":"abc"}', 'expires_at' => 150, 'retain_until' => 3750, 'consumed' => 0],
			$bound
		);
		$this->assertCount(1, $this->callsTo('executeStatement'));
	}//end testPutInsertsAnUnconsumedRow()

	/**
	 * find() reads the row by digest and maps its columns to typed values.
	 *
	 * @return void
	 */
	public function testFindMapsTheRow(): void {
		$store = $this->store($this->queryBuilder(['record' => '{"x":1}', 'expires_at' => '150', 'consumed' => '1']));

		$this->assertSame(['record' => '{"x":1}', 'expiresAt' => 150, 'consumed' => true], $store->find(digest: 'abc'));
		$this->assertSame(DatabaseRunTokenStore::TABLE, $this->callsTo('from')[0][0]);
		$this->assertSame('token_hash = :p0', $this->callsTo('where')[0][0]);
		$this->assertSame('abc', $this->params[':p0']);
		$this->assertSame(1, $this->callsTo('setMaxResults')[0][0]);
	}//end testFindMapsTheRow()

	/**
	 * find() answers null, not an empty record, when no row exists.
	 *
	 * @return void
	 */
	public function testFindReturnsNullForAMissingRow(): void {
		$this->assertNull($this->store($this->queryBuilder(false))->find(digest: 'missing'));
	}//end testFindReturnsNullForAMissingRow()

	/**
	 * markConsumed() flags the row instead of deleting it, so it stays recognisable.
	 *
	 * @return void
	 */
	public function testMarkConsumedUpdatesTheFlagAndDeletesNothing(): void {
		$this->store($this->queryBuilder())->markConsumed(digest: 'abc');

		$this->assertSame(DatabaseRunTokenStore::TABLE, $this->callsTo('update')[0][0]);
		$set = $this->callsTo('set')[0];
		$this->assertSame('consumed', $set[0]);
		$this->assertSame(1, $this->params[$set[1]]);
		$this->assertSame([], $this->callsTo('delete'));
		$this->assertCount(1, $this->callsTo('executeStatement'));
	}//end testMarkConsumedUpdatesTheFlagAndDeletesNothing()

	/**
	 * purge() deletes only rows whose retention ended before now.
	 *
	 * @return void
	 */
	public function testPurgeDeletesRowsPastRetention(): void {
		$this->store($this->queryBuilder())->purge(now: 5000);

		$this->assertSame(DatabaseRunTokenStore::TABLE, $this->callsTo('delete')[0][0]);
		$where = $this->callsTo('where')[0][0];
		$this->assertStringStartsWith('retain_until < ', $where);
		$this->assertSame(5000, $this->params[substr($where, strlen('retain_until < '))]);
		$this->assertCount(1, $this->callsTo('executeStatement'));
	}//end testPurgeDeletesRowsPastRetention()
}//end class
