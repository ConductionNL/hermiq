<?php

/**
 * Golden test for how BudgetService::status() counts a budget's usage.
 *
 * Pins which objects are in a budget's scope (schedules, eval runs and skill drafts
 * of the organisation, or bound to the agent), that a failing scan of one schema
 * only drops that schema, and which run entries count (in scope, inside the period
 * window, with a usage record), so the scan and the sum can be split into named
 * steps without changing a budget's total.
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
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\AnalyticsService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the budget usage scope and sum.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
class BudgetUsageScopeGoldenTest extends TestCase {

	/**
	 * An object in the hermiq register.
	 *
	 * @param string $uuid The uuid.
	 * @param string $organisation The organisation.
	 * @param string $agentId The bound agent.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $organisation, string $agentId): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation($organisation);
		$entity->setObject(['agentId' => $agentId]);
		return $entity;
	}//end object()

	/**
	 * A run entry for an object.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param mixed $usage The recorded usage.
	 * @param DateTime|null $created When it was created.
	 *
	 * @return AuditTrail
	 */
	private function runEntry(string $objectUuid, mixed $usage, ?DateTime $created = null): AuditTrail {
		$entry = new AuditTrail();
		$entry->setAction('run');
		$entry->setObjectUuid($objectUuid);
		$entry->setCreated($created ?? new DateTime('now'));
		$entry->setChanged(['usage' => $usage]);
		return $entry;
	}//end runEntry()

	/**
	 * The service over objects per schema (a Throwable fails that schema's scan) and
	 * run entries.
	 *
	 * @param array<string, mixed> $bySchema Schema slug => objects, or a Throwable.
	 * @param array<int, AuditTrail> $runs The run entries.
	 * @param array<int, string> $warnings Captures the logged warnings.
	 *
	 * @return BudgetService
	 */
	private function service(array $bySchema, array $runs, array &$warnings): BudgetService {
		$current = '';
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$current, $objectService) {
				$current = $schema;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function () use (&$current, $bySchema): array {
				$objects = ($bySchema[$current] ?? []);
				if ($objects instanceof \Throwable) {
					throw $objects;
				}

				return $objects;
			}
		);
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('findAll')->willReturn($runs);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		return new BudgetService(
			objectService: $objectService,
			auditTrailMapper: $audit,
			appConfig: $this->createMock(IAppConfig::class),
			organisationMapper: $this->createMock(OrganisationMapper::class),
			deliveryService: $this->createMock(DeliveryService::class),
			analyticsService: $this->createMock(AnalyticsService::class),
			logger: $logger,
		);
	}//end service()

	/**
	 * A budget.
	 *
	 * @param array<string, mixed> $payload The budget body.
	 * @param string $organisation The owning organisation.
	 *
	 * @return ObjectEntity
	 */
	private function budget(array $payload, string $organisation = 'org-a'): ObjectEntity {
		$budget = new ObjectEntity();
		$budget->setUuid('b1');
		$budget->setOrganisation($organisation);
		$budget->setObject($payload + ['tokenLimit' => 1000, 'period' => 'monthly']);
		return $budget;
	}//end budget()

	/**
	 * The objects and run entries both scopes read.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, AuditTrail>}
	 */
	private function fixture(): array {
		$bySchema = [
			'schedule' => ['x', $this->object('s-org', 'org-a', 'agent-2'), $this->object('s-agent', 'org-b', 'agent-1'), $this->object('s-none', 'org-b', 'agent-3')],
			'evalrun' => [$this->object('e-org', 'org-a', ''), $this->object('e-agent', 'org-c', 'agent-1')],
			'agentskilldraft' => [$this->object('d-org', 'org-a', 'agent-1')],
		];
		$runs = [
			$this->runEntry('s-org', ['promptTokens' => 10, 'completionTokens' => 1]),
			$this->runEntry('s-agent', ['promptTokens' => 20, 'completionTokens' => 2]),
			$this->runEntry('e-org', ['promptTokens' => '30']),
			$this->runEntry('e-agent', ['completionTokens' => 4]),
			$this->runEntry('d-org', ['promptTokens' => 50, 'completionTokens' => 5]),
			$this->runEntry('s-none', ['promptTokens' => 600]),
			$this->runEntry('s-org', ['promptTokens' => 700], new DateTime('2000-01-01')),
			$this->runEntry('s-org', 'not-a-usage'),
		];
		return [$bySchema, $runs];
	}//end fixture()

	/**
	 * An organisation budget counts the organisation's schedules, eval runs and
	 * drafts; an agent budget counts the agent's; entries outside the window or
	 * without a usage record never count.
	 *
	 * @return void
	 */
	public function testUsageCountsTheBudgetsScopeInsideItsWindow(): void {
		[$bySchema, $runs] = $this->fixture();
		$warnings = [];
		$service = $this->service($bySchema, $runs, $warnings);

		$this->assertSame(96, $service->status(budget: $this->budget(['scope' => 'organisation']))['tokens']['used']);
		$this->assertSame(81, $service->status(budget: $this->budget(['scope' => 'agent', 'agentId' => 'agent-1']))['tokens']['used']);
		$this->assertSame(81, $service->status(budget: $this->budget(['scope' => 'other', 'agentId' => 'agent-1']))['tokens']['used']);
		$this->assertSame(0, $service->status(budget: $this->budget(['scope' => 'organisation'], ''))['tokens']['used']);
		$this->assertSame(0, $service->status(budget: $this->budget(['scope' => 'agent', 'agentId' => '']))['tokens']['used']);
		$this->assertSame([], $warnings);

	}//end testUsageCountsTheBudgetsScopeInsideItsWindow()

	/**
	 * A schema whose scan fails drops out of the scope with a warning; the others
	 * still count.
	 *
	 * @return void
	 */
	public function testAFailingSchemaScanOnlyDropsThatSchema(): void {
		[$bySchema, $runs] = $this->fixture();
		$bySchema['evalrun'] = new RuntimeException('schema missing');
		$warnings = [];
		$service = $this->service($bySchema, $runs, $warnings);

		$status = $service->status(budget: $this->budget(['scope' => 'organisation']));

		$this->assertSame(66, $status['tokens']['used']);
		$this->assertSame(["Hermiq could not load 'evalrun' objects for the budget scope scan: schema missing"], $warnings);
		$this->assertSame(['scope', 'agentId', 'period', 'periodKey', 'tokens', 'eur', 'softThresholdReached', 'hardCapReached'], array_keys($status));
		$this->assertNull($status['agentId']);

	}//end testAFailingSchemaScanOnlyDropsThatSchema()
}//end class
