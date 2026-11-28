<?php

/**
 * Golden tests for the SeedBudgets and SeedModelPolicies repair steps.
 *
 * Pins every message the two steps write to the repair output and the logger, in
 * order, for each branch: OpenRegister absent, no organisation, an agent missing or
 * without an organisation, a write that throws, and the final count. Both steps read
 * their first organisation the same way, so a mapper that throws must read as "no
 * organisation" for both.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Kernel\Contracts\OrganisationModelPolicyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\BudgetServiceInterface;
use OCA\Hermiq\Repair\SeedBudgets;
use OCA\Hermiq\Repair\SeedModelPolicies;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the organisation-scoped seed steps.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
class SeedOrganisationRowsGoldenTest extends TestCase {

	/**
	 * Every output and logger call, in order, as "channel: message".
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * An IOutput that records info and warning lines.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->log[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->log[] = 'warning: ' . $message;
			}
		);
		return $output;
	}//end recordingOutput()

	/**
	 * A logger that records warning and error lines.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->log[] = 'log.warning: ' . $message;
			}
		);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->log[] = 'log.error: ' . $message;
			}
		);
		return $logger;
	}//end logger()

	/**
	 * An organisation mapper answering the given uuids, or throwing when null.
	 *
	 * @param array<int, string>|null $orgUuids The uuids, or null to throw.
	 *
	 * @return OrganisationMapper
	 */
	private function organisationMapper(?array $orgUuids): OrganisationMapper {
		$mapper = $this->createMock(OrganisationMapper::class);
		if ($orgUuids === null) {
			$mapper->method('findAll')->willThrowException(new RuntimeException('db down'));
			return $mapper;
		}

		$orgs = array_map(
			function (string $uuid) {
				$org = new Organisation();
				$org->setUuid($uuid);
				return $org;
			},
			$orgUuids
		);
		$mapper->method('findAll')->willReturn($orgs);
		return $mapper;
	}//end organisationMapper()

	/**
	 * A container answering the given services by class name.
	 *
	 * @param array<string, object> $services Class name => service.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($services) {
				if (isset($services[$class]) === false) {
					throw new RuntimeException('no ' . $class);
				}

				return $services[$class];
			}
		);
		return $container;
	}//end container()

	/**
	 * An ObjectService answering fixed objects per schema slug.
	 *
	 * @param array<string, array<int, mixed>> $bySchema Schema slug => objects.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $bySchema): ObjectService {
		return new class($bySchema) extends ObjectService {
			private ?string $schema = null;

			/**
			 * @param array<string, array<int, mixed>> $bySchema Schema slug => objects.
			 */
			public function __construct(
				private array $bySchema,
			) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->bySchema[$this->schema] ?? []);
			}
		};
	}//end objectService()

	/**
	 * An ObjectEntity with the given uuid, organisation and data.
	 *
	 * @param string $uuid The uuid.
	 * @param string|null $organisation The organisation.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, ?string $organisation, array $data): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setOrganisation($organisation);
		$e->setObject($data);
		return $e;
	}//end entity()

	/**
	 * Without OpenRegister both steps warn, log and stop.
	 *
	 * @return void
	 */
	public function testBothStepsStopWhenOpenRegisterIsAbsent(): void {
		(new SeedBudgets(container: $this->container([]), logger: $this->logger()))->run(output: $this->recordingOutput());
		(new SeedModelPolicies(container: $this->container([]), logger: $this->logger()))->run(output: $this->recordingOutput());

		$this->assertSame(
			[
				'warning: OpenRegister not available — skipping budget seed.',
				'log.warning: [hermiq] Budget seed skipped: no ' . ObjectService::class,
				'warning: OpenRegister not available — skipping model-policy seed.',
				'log.warning: [hermiq] Model-policy seed skipped: no ' . OrganisationModelPolicyServiceInterface::class,
			],
			$this->log
		);
	}//end testBothStepsStopWhenOpenRegisterIsAbsent()

	/**
	 * A budget seed with every kind of row: a matching existing budget elsewhere, an
	 * agent without an organisation, a write that throws, and a write that succeeds.
	 *
	 * @return void
	 */
	public function testBudgetSeedReportsEachRowInOrder(): void {
		$objectService = $this->objectService(
			[
				'agentbudget' => [
					'not an entity',
					$this->entity('b-other', 'org-b', ['scope' => 'organisation', 'agentId' => '', 'period' => 'monthly']),
					$this->entity('b-weekly', 'org-a', ['scope' => 'organisation', 'agentId' => '', 'period' => 'weekly']),
				],
				'agent' => [
					'not an entity',
					$this->entity('agent-briefing', null, ['name' => 'Daily Briefing']),
					$this->entity('agent-heavy', 'org-h', ['name' => 'Heavy Tool Runner']),
				],
			]
		);

		$created = [];
		$budgetService = $this->createMock(BudgetService::class);
		$budgetService->method('create')->willReturnCallback(
			function (array $payload, string $organisation) use (&$created): array {
				$created[] = [$organisation, $payload];
				if ($payload['scope'] === 'agent') {
					throw new RuntimeException('quota');
				}

				return $payload;
			}
		);

		$step = new SeedBudgets(
			container: $this->container(
				[
					ObjectService::class => $objectService,
					OrganisationMapper::class => $this->organisationMapper(['org-a']),
					BudgetServiceInterface::class => $budgetService,
				]
			),
			logger: $this->logger(),
		);
		$step->run(output: $this->recordingOutput());

		$this->assertSame(
			[
				[
					'org-a',
					['scope' => 'organisation', 'period' => 'monthly', 'tokenLimit' => 2000000, 'softThresholdPercent' => 80],
				],
				[
					'org-h',
					[
						'scope' => 'agent',
						'agentId' => 'agent-heavy',
						'period' => 'daily',
						'tokenLimit' => 200000,
						'softThresholdPercent' => 90,
					],
				],
			],
			$created
		);
		$this->assertSame(
			[
				'info: Agent "Daily Briefing" has no organisation yet — its budget seed is deferred.',
				'warning: Could not seed a budget: quota',
				'log.error: [hermiq] Budget seed failed: quota',
				'info: Budget seed complete (1 new).',
			],
			$this->log
		);
	}//end testBudgetSeedReportsEachRowInOrder()

	/**
	 * A mapper that throws reads as no organisation; a missing agent is deferred.
	 *
	 * @return void
	 */
	public function testBudgetSeedTreatsAFailingOrganisationLookupAsNone(): void {
		$budgetService = $this->createMock(BudgetService::class);
		$budgetService->expects($this->never())->method('create');

		$step = new SeedBudgets(
			container: $this->container(
				[
					ObjectService::class => $this->objectService([]),
					OrganisationMapper::class => $this->organisationMapper(null),
					BudgetServiceInterface::class => $budgetService,
				]
			),
			logger: $this->logger(),
		);
		$step->run(output: $this->recordingOutput());

		$this->assertSame(
			[
				'info: No OpenRegister organisation exists yet — org-level budget seed deferred.',
				'info: No agent named "Daily Briefing" exists yet — its budget seed is deferred.',
				'info: No agent named "Heavy Tool Runner" exists yet — its budget seed is deferred.',
				'info: Budget seed complete (0 new).',
			],
			$this->log
		);
	}//end testBudgetSeedTreatsAFailingOrganisationLookupAsNone()

	/**
	 * A policy write that throws is reported and the next row still runs.
	 *
	 * @return void
	 */
	public function testModelPolicySeedReportsAFailedWriteAndContinues(): void {
		$policyService = $this->createMock(OrganisationModelPolicyService::class);
		$policyService->method('getForOrganisation')->willReturn(null);
		$written = [];
		$policyService->method('upsertForOrganisation')->willReturnCallback(
			function (string $organisation, array $payload) use (&$written): array {
				$written[] = $organisation;
				if ($organisation === '') {
					throw new RuntimeException('invalid');
				}

				return $payload;
			}
		);

		$step = new SeedModelPolicies(
			container: $this->container(
				[
					OrganisationModelPolicyServiceInterface::class => $policyService,
					OrganisationMapper::class => $this->organisationMapper(['org-a']),
				]
			),
			logger: $this->logger(),
		);
		$step->run(output: $this->recordingOutput());

		$this->assertSame(['', 'org-a'], $written);
		$this->assertSame(
			[
				'warning: Could not seed a model policy: invalid',
				'log.error: [hermiq] Model-policy seed failed: invalid',
				'info: Model-policy seed complete (1 new).',
			],
			$this->log
		);
	}//end testModelPolicySeedReportsAFailedWriteAndContinues()

	/**
	 * A mapper that throws reads as no organisation for the model-policy seed too.
	 *
	 * @return void
	 */
	public function testModelPolicySeedTreatsAFailingOrganisationLookupAsNone(): void {
		$policyService = $this->createMock(OrganisationModelPolicyService::class);
		$policyService->method('getForOrganisation')->willReturn(new ObjectEntity());
		$policyService->expects($this->never())->method('upsertForOrganisation');

		$step = new SeedModelPolicies(
			container: $this->container(
				[
					OrganisationModelPolicyServiceInterface::class => $policyService,
					OrganisationMapper::class => $this->organisationMapper(null),
				]
			),
			logger: $this->logger(),
		);
		$step->run(output: $this->recordingOutput());

		$this->assertSame(
			[
				'info: No OpenRegister organisation exists yet — sample org model-policy seed deferred.',
				'info: Model-policy seed complete (0 new).',
			],
			$this->log
		);
	}//end testModelPolicySeedTreatsAFailingOrganisationLookupAsNone()
}//end class
