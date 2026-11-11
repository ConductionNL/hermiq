<?php

/**
 * Golden tests for SeedHydraTriageFlow::run and its organisation resolution.
 *
 * Pins the allowed and refused outcomes of the seed: the exact fields written, in
 * order, onto the inserted flow; each way the default organisation is resolved
 * (configured, ensured after a throw or an empty answer) and each way it is not
 * (service unavailable, ensure throws), in which case nothing is written; the flow
 * store being unavailable; and an insert that throws. Every output line, log line
 * and recorded outcome is asserted in order.
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
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedHydraTriageFlow;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the triage flow seed run.
 *
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
 */
class SeedHydraTriageFlowRunGoldenTest extends TestCase {

	/**
	 * Every output line, log line and recorded outcome, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * The flow inserted, or null.
	 *
	 * @var Flow|null
	 */
	private ?Flow $inserted = null;

	/**
	 * An organisation service whose two reads answer or throw as given.
	 *
	 * @param string|RuntimeException|null $configured What getDefaultOrganisationUuid answers or throws.
	 * @param string|RuntimeException $ensured The uuid ensureDefaultOrganisation's organisation carries, or what it throws.
	 *
	 * @return OrganisationService
	 */
	private function organisations(string|RuntimeException|null $configured, string|RuntimeException $ensured): OrganisationService {
		$service = $this->createMock(OrganisationService::class);
		if ($configured instanceof RuntimeException) {
			$service->method('getDefaultOrganisationUuid')->willThrowException($configured);
		} else {
			$service->method('getDefaultOrganisationUuid')->willReturn($configured);
		}

		if ($ensured instanceof RuntimeException) {
			$service->method('ensureDefaultOrganisation')->willThrowException($ensured);
		} else {
			$organisation = new Organisation();
			$organisation->setUuid($ensured);
			$service->method('ensureDefaultOrganisation')->willReturn($organisation);
		}

		return $service;
	}//end organisations()

	/**
	 * Run the step over an empty flow store.
	 *
	 * @param OrganisationService|null $organisations The organisation service, or null when unavailable.
	 * @param bool $insertThrows Whether the insert throws.
	 * @param bool $mapperAvailable Whether the flow store resolves.
	 *
	 * @return array<int, string>
	 */
	private function runSeed(?OrganisationService $organisations, bool $insertThrows = false, bool $mapperAvailable = true): array {
		$this->log = [];
		$this->inserted = null;

		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturn([]);
		$mapper->method('insert')->willReturnCallback(
			function (Flow $flow) use ($insertThrows): Flow {
				if ($insertThrows === true) {
					throw new RuntimeException('duplicate key');
				}

				$this->inserted = $flow;
				return $flow;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($mapper, $organisations, $mapperAvailable) {
				if ($id === FlowMapper::class && $mapperAvailable === true) {
					return $mapper;
				}

				if ($id === OrganisationService::class && $organisations !== null) {
					return $organisations;
				}

				throw new RuntimeException('no ' . $id);
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(fn (string $m) => $this->log[] = 'info: ' . $m);
		$output->method('warning')->willReturnCallback(fn (string $m) => $this->log[] = 'warning: ' . $m);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(fn (string $m) => $this->log[] = 'log.' . $level . ': ' . $m);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->log[] = 'set ' . $key . '=' . $value;
				return true;
			}
		);

		(new SeedHydraTriageFlow(container: $container, logger: $logger, appConfig: $appConfig))->run(output: $output);
		return $this->log;
	}//end runSeed()

	/**
	 * A configured default organisation: the full flow is written with it.
	 *
	 * @return void
	 */
	public function testAConfiguredOrganisationSeedsTheFullFlow(): void {
		$lines = $this->runSeed($this->organisations('org-1', new RuntimeException('not reached')));

		$this->assertSame(
			[
				'log.warning: [hermiq] Could not resolve the Hydra Triage agent uuid for the flow seed: no OCA\\OpenRegister\\Service\\ObjectService',
				'info: Hydra Triage flow seeded (disabled — enable it to supply its owner).',
				'set hydra_triage_flow_seed=seeded',
				'set hydra_triage_flow_seed_detail=',
			],
			$lines
		);

		$flow = $this->inserted;
		$this->assertNotNull($flow);
		$data = (new SeedHydraTriageFlow(container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class)))->flowObject();
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $flow->getUuid());
		$this->assertSame('hermiq', $flow->getApp());
		$this->assertSame($data['name'], $flow->getName());
		$this->assertSame($data['description'], $flow->getDescription());
		$this->assertSame($data['trigger'], $flow->getTrigger());
		$this->assertSame($data['triggerRegister'], $flow->getTriggerRegister());
		$this->assertSame($data['triggerSchema'], $flow->getTriggerSchema());
		$this->assertSame($data['cron'] ?? null, $flow->getCron());
		$this->assertEquals($data['nodes'], $flow->getNodes());
		$this->assertSame($data['edges'], $flow->getEdges());
		$this->assertSame($data['limits'], $flow->getLimits());
		$this->assertSame('hydra-console', $flow->getApplicationSlug());
		$this->assertNotNull($flow->getCreated());
		$this->assertNotNull($flow->getUpdated());
		$this->assertFalse($flow->getEnabled());
		$this->assertNull($flow->getOwner());
		$this->assertSame('org-1', $flow->getOrganisation());
	}//end testAConfiguredOrganisationSeedsTheFullFlow()

	/**
	 * Each way the organisation is ensured after the configured read gives nothing.
	 *
	 * @return void
	 */
	public function testAnEnsuredOrganisationIsUsedWhenNoneIsConfigured(): void {
		$lines = $this->runSeed($this->organisations(new RuntimeException('unset'), 'org-2'));
		$this->assertSame('org-2', $this->inserted?->getOrganisation());
		$this->assertSame('log.debug: [hermiq] Default organisation not yet configured: unset', $lines[1]);

		$this->runSeed($this->organisations('', 'org-3'));
		$this->assertSame('org-3', $this->inserted?->getOrganisation());
	}//end testAnEnsuredOrganisationIsUsedWhenNoneIsConfigured()

	/**
	 * Each way no organisation is resolved: nothing is written and the refusal is recorded.
	 *
	 * @return void
	 */
	public function testNoResolvableOrganisationWritesNothing(): void {
		$refusal = [
			'warning: No default organisation is resolvable — skipping the Hydra Triage flow seed rather than writing a row no tenant could ever see.',
			'set hydra_triage_flow_seed=unavailable',
			'set hydra_triage_flow_seed_detail=no default organisation resolvable; refused to write an org-less orphan',
		];

		$lines = $this->runSeed(null);
		$this->assertNull($this->inserted);
		$this->assertSame(
			array_merge(
				[
					'log.warning: [hermiq] Could not resolve the Hydra Triage agent uuid for the flow seed: no OCA\\OpenRegister\\Service\\ObjectService',
					'log.warning: [hermiq] OrganisationService unavailable for the flow seed: no OCA\\OpenRegister\\Service\\OrganisationService',
				],
				$refusal
			),
			$lines
		);

		$lines = $this->runSeed($this->organisations(null, new RuntimeException('no table')));
		$this->assertNull($this->inserted);
		$this->assertSame(
			array_merge(
				[
					'log.warning: [hermiq] Could not resolve the Hydra Triage agent uuid for the flow seed: no OCA\\OpenRegister\\Service\\ObjectService',
					'log.warning: [hermiq] Could not ensure a default organisation: no table',
				],
				$refusal
			),
			$lines
		);

		$this->runSeed($this->organisations('', ''));
		$this->assertNull($this->inserted);
	}//end testNoResolvableOrganisationWritesNothing()

	/**
	 * The flow store unavailable, and an insert that throws.
	 *
	 * @return void
	 */
	public function testAnUnavailableStoreAndAFailedInsertAreReported(): void {
		$this->assertSame(
			[
				'warning: OpenRegister not available — skipping Hydra Triage flow seed.',
				'log.warning: [hermiq] Hydra Triage flow seed skipped: no OCA\\OpenRegister\\Db\\FlowMapper',
				'set hydra_triage_flow_seed=unavailable',
				'set hydra_triage_flow_seed_detail=RuntimeException: no OCA\\OpenRegister\\Db\\FlowMapper',
			],
			$this->runSeed($this->organisations('org-1', 'org-1'), false, false)
		);

		$lines = $this->runSeed($this->organisations('org-1', 'org-1'), true);
		$this->assertSame(
			[
				'warning: Could not seed the Hydra Triage flow: duplicate key',
				'log.error: [hermiq] Hydra Triage flow seed failed: RuntimeException: duplicate key',
				'set hydra_triage_flow_seed=failed',
				'set hydra_triage_flow_seed_detail=RuntimeException: duplicate key',
			],
			array_slice($lines, 1)
		);
	}//end testAnUnavailableStoreAndAFailedInsertAreReported()
}//end class
