<?php

/**
 * Unit tests for the feedback counts and low ratings in AnalyticsService
 * (observability-feedback-per-agent).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-observability-feedback-per-agent/tasks.md#task-1-feedback-counts-in-the-analytics
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\AnalyticsService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Feedback aggregation on the analytics tenant boundary.
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
 */
class AnalyticsFeedbackTest extends TestCase {

	private const PERMIT = 'a1a1a1a1-0000-4000-8000-000000000001';
	private const OTHER = 'b2b2b2b2-0000-4000-8000-000000000002';
	private const FOREIGN = 'c3c3c3c3-0000-4000-8000-000000000003';

	/**
	 * An ObjectEntity with a payload and a created date.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 * @param string $created The created timestamp.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $data, string $created = '2026-09-01T10:00:00+00:00'): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setObject($data);
		$e->setCreated(new DateTime($created));
		return $e;
	}//end object()

	/**
	 * A rating.
	 *
	 * @param string $agentId The rated agent.
	 * @param string $type positive or negative.
	 * @param string $comment The comment.
	 * @param string $created The created timestamp.
	 *
	 * @return ObjectEntity
	 */
	private function rating(string $agentId, string $type, string $comment = '', string $created = '2026-09-01T10:00:00+00:00'): ObjectEntity {
		return $this->object(
			uniqid('fb-'),
			['agentId' => $agentId, 'type' => $type, 'comment' => $comment, 'userId' => 'rater', 'conversationId' => 'conv-1', 'messageId' => 'msg-1'],
			$created
		);
	}//end rating()

	/**
	 * A service whose visible agents are PERMIT and OTHER, and whose feedback
	 * read returns the given rows (filtered by agentId when the query asks).
	 *
	 * @param array<int, ObjectEntity> $feedback All ratings on the instance.
	 * @param array<int, array<string, mixed>>|null $queries Out-param: the feedback queries.
	 *
	 * @return AnalyticsService
	 */
	private function service(array $feedback, ?array &$queries = null): AnalyticsService {
		$agents = [
			$this->object(self::PERMIT, ['name' => 'Permit helper']),
			$this->object(self::OTHER, ['name' => 'Tax helper']),
		];

		$schema = null;
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string $slug) use (&$schema, $objects): ObjectService {
				$schema = $slug;
				return $objects;
			}
		);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$schema, &$queries, $agents, $feedback): array {
				if ($schema !== 'feedback') {
					return ['results' => $agents, 'total' => count($agents)];
				}

				$queries[] = $query;
				$rows = array_values(
					array_filter(
						$feedback,
						static fn (ObjectEntity $f): bool => isset($query['agentId']) === false || $f->getObject()['agentId'] === $query['agentId']
					)
				);
				return ['results' => $rows, 'total' => count($rows)];
			}
		);

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findAll')->willReturn([]);

		return new AnalyticsService($objects, $mapper);
	}//end service()

	/**
	 * Three up and one down on the agent: 3, 1 and 0.75; another organisation's
	 * rating is not counted.
	 *
	 * @return void
	 */
	public function testAnAgentsRatingsAreCounted(): void {
		$queries = [];
		$result = $this->service(
			[
				$this->rating(self::PERMIT, 'positive'),
				$this->rating(self::PERMIT, 'positive'),
				$this->rating(self::PERMIT, 'positive'),
				$this->rating(self::PERMIT, 'negative', 'Gave the old opening hours'),
			],
			$queries
		)->computeAnalytics(agentId: self::PERMIT);

		$this->assertSame(['positive' => 3, 'negative' => 1, 'helpfulRate' => 0.75], $result['feedback']);
		$this->assertSame(self::PERMIT, $queries[0]['agentId']);
	}//end testAnAgentsRatingsAreCounted()

	/**
	 * The organisation view counts per agent and leaves out a rating on an agent
	 * the caller cannot see.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
	 */
	public function testThePerAgentRowsCarryTheCountsOnTheTenantBoundary(): void {
		$result = $this->service(
			[
				$this->rating(self::PERMIT, 'positive'),
				$this->rating(self::OTHER, 'negative'),
				$this->rating(self::OTHER, 'negative'),
				$this->rating(self::FOREIGN, 'positive'),
			]
		)->computeAnalytics();

		$this->assertSame(['positive' => 1, 'negative' => 2, 'helpfulRate' => 0.3333], $result['feedback']);
		$byAgent = array_column($result['perAgent'], null, 'agentId');
		$this->assertSame([1, 0], [$byAgent[self::PERMIT]['positive'], $byAgent[self::PERMIT]['negative']]);
		$this->assertSame([0, 2], [$byAgent[self::OTHER]['positive'], $byAgent[self::OTHER]['negative']]);
		$this->assertArrayNotHasKey(self::FOREIGN, $byAgent);
	}//end testThePerAgentRowsCarryTheCountsOnTheTenantBoundary()

	/**
	 * No ratings: zero counts and no rate.
	 *
	 * @return void
	 */
	public function testNoRatingsHaveNoRate(): void {
		$result = $this->service([])->computeAnalytics(agentId: self::PERMIT);

		$this->assertSame(['positive' => 0, 'negative' => 0, 'helpfulRate' => null], $result['feedback']);
	}//end testNoRatingsHaveNoRate()

	/**
	 * Low ratings: only thumbs down with a comment, newest first, at most ten,
	 * and no rater name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-reads-the-latest-low-ratings-req-fbstat-002
	 */
	public function testLatestLowRatingsAreNewestFirstWithoutTheRater(): void {
		$feedback = [
			$this->rating(self::PERMIT, 'positive', 'Great'),
			$this->rating(self::PERMIT, 'negative', ''),
			$this->rating(self::PERMIT, 'negative', 'Gave the old opening hours', '2026-09-20T10:00:00+00:00'),
			$this->rating(self::OTHER, 'negative', 'Not this agent', '2026-09-25T10:00:00+00:00'),
		];
		for ($i = 1; $i <= 11; $i++) {
			$feedback[] = $this->rating(self::PERMIT, 'negative', "Older $i", sprintf('2026-08-%02dT10:00:00+00:00', $i));
		}

		$rows = $this->service($feedback)->latestLowRatings(agentId: self::PERMIT);

		$this->assertCount(10, $rows);
		$this->assertSame('Gave the old opening hours', $rows[0]['comment']);
		$this->assertSame('2026-09-20T10:00:00+00:00', $rows[0]['date']);
		$this->assertSame('Older 11', $rows[1]['comment']);
		$this->assertArrayNotHasKey('userId', $rows[0]);
		$this->assertNotContains('Not this agent', array_column($rows, 'comment'));
	}//end testLatestLowRatingsAreNewestFirstWithoutTheRater()
}//end class
