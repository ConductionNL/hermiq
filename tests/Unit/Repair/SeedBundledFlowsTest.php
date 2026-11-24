<?php

/**
 * Unit tests for SeedBundledFlows and BundledFlowDefinitions (hermiq-seed-hydra-flows).
 *
 * The shipped definitions are checked as data (named, node-form, none the triage flow
 * hermiq seeds elsewhere); the step is checked for each way a flow can end: seeded
 * disabled and ownerless, already present, refused by preflight, failed (which must
 * fail the step after every flow was attempted), and the whole seed skipped when
 * OpenRegister or a default organisation is missing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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
 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\BundledFlowDefinitions;
use OCA\Hermiq\Repair\SeedBundledFlows;
use OCA\Hermiq\Repair\SeedHydraTriageFlow;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the bundled flow seed.
 *
 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
 */
class SeedBundledFlowsTest extends TestCase {

	/**
	 * Temporary definition directories made by a test, removed afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $tempDirs = [];

	/**
	 * Remove the temporary directories.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempDirs as $dir) {
			array_map('unlink', (glob($dir . '/*') ?: []));
			rmdir($dir);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A definition directory holding the given files.
	 *
	 * @param array<string, string> $files Contents by file name.
	 *
	 * @return BundledFlowDefinitions
	 */
	private function definitions(array $files): BundledFlowDefinitions {
		$dir = sys_get_temp_dir() . '/hermiq-flows-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$this->tempDirs[] = $dir;
		foreach ($files as $name => $content) {
			file_put_contents($dir . '/' . $name, $content);
		}

		return new BundledFlowDefinitions(directory: $dir);
	}//end definitions()

	/**
	 * A minimal node-form flow definition.
	 *
	 * @param string $name    The flow's name.
	 * @param bool   $enabled What the definition claims.
	 *
	 * @return string JSON.
	 */
	private static function flowJson(string $name, bool $enabled=true): string {
		return (string)json_encode(
			[
				'name'    => $name,
				'trigger' => 'manual',
				'enabled' => $enabled,
				'nodes'   => [['id' => 'start', 'type' => 'openregister.trigger-manual'], ['id' => 'done', 'type' => 'openregister.end']],
				'edges'   => [['id' => 'e1', 'from' => 'start', 'to' => 'done']],
			]
		);
	}//end flowJson()

	/**
	 * A container serving the flow store, an organisation service and optionally a preflight.
	 *
	 * @param FlowMapper|null        $mapper    The flow store, or null when OpenRegister is absent.
	 * @param string|null            $orgUuid   The default organisation, or null when none resolves.
	 * @param FlowNodePreflight|null $preflight The preflight, or null when unavailable.
	 *
	 * @return ContainerInterface
	 */
	private function container(?FlowMapper $mapper, ?string $orgUuid='org-1', ?FlowNodePreflight $preflight=null): ContainerInterface {
		$organisations = $this->createMock(originalClassName: OrganisationService::class);
		$organisations->method('getDefaultOrganisationUuid')->willReturn($orgUuid);
		$organisations->method('ensureDefaultOrganisation')->willThrowException(new RuntimeException('no organisation'));

		$services  = [FlowMapper::class => $mapper, OrganisationService::class => $organisations, FlowNodePreflight::class => $preflight];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services) {
				if (($services[$id] ?? null) !== null) {
					return $services[$id];
				}

				throw new RuntimeException('not available in this test: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * A flow store holding flows with the given names, recording what is inserted.
	 *
	 * @param array<int, string> $names    Names already present.
	 * @param \ArrayObject       $inserted Receives each inserted Flow.
	 * @param string|null        $failFor  A name whose insert throws.
	 *
	 * @return FlowMapper
	 */
	private function mapper(array $names, \ArrayObject $inserted, ?string $failFor=null): FlowMapper {
		$existing = [];
		foreach ($names as $name) {
			$flow = new Flow();
			$flow->setName($name);
			$existing[] = $flow;
		}

		$mapper = $this->createMock(originalClassName: FlowMapper::class);
		$mapper->method('findAllFlows')->willReturn($existing);
		$mapper->method('insert')->willReturnCallback(
			static function (Flow $flow) use ($inserted, $failFor): Flow {
				if ($flow->getName() === $failFor) {
					throw new RuntimeException('constraint violated');
				}

				$inserted->append($flow);
				return $flow;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * An app config that records what the step writes.
	 *
	 * @param \ArrayObject $recorded Receives value by key.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(\ArrayObject $recorded): IAppConfig {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($recorded): bool {
				$recorded[$key] = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * The shipped definitions are ten named, node-form flows, and not the triage flow.
	 *
	 * @return void
	 */
	public function testTheShippedDefinitionsAreNamedNodeFormFlows(): void {
		$definitions = (new BundledFlowDefinitions())->all();

		$this->assertCount(expectedCount: 10, haystack: $definitions);
		foreach ($definitions as $file => $definition) {
			$this->assertNull(actual: $definition['error'], message: $file);
			$data = (array)$definition['data'];
			$this->assertNotSame(expected: SeedHydraTriageFlow::FLOW_NAME, actual: $data['name'], message: $file . ' is seeded by SeedHydraTriageFlow');
			$this->assertNotEmpty(actual: $data['nodes'] ?? [], message: $file);
			foreach (($data['edges'] ?? []) as $edge) {
				$this->assertArrayNotHasKey(key: 'type', array: $edge, message: $file . ': a step on an edge is never run');
			}
		}
	}//end testTheShippedDefinitionsAreNamedNodeFormFlows()

	/**
	 * An empty store gets every flow, disabled, ownerless, on the default organisation.
	 *
	 * @return void
	 */
	public function testAnEmptyStoreGetsEveryFlowDisabledAndOwnerless(): void {
		$inserted = new \ArrayObject();
		$recorded = new \ArrayObject();
		$step     = new SeedBundledFlows(
			container: $this->container(mapper: $this->mapper(names: [], inserted: $inserted)),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			appConfig: $this->appConfig(recorded: $recorded),
			definitions: $this->definitions(files: ['a.flow.json' => self::flowJson(name: 'Flow A'), 'b.flow.json' => self::flowJson(name: 'Flow B')])
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 2, haystack: $inserted);
		foreach ($inserted as $flow) {
			$this->assertFalse(condition: $flow->getEnabled(), message: 'A definition saying enabled is seeded disabled.');
			$this->assertNull(actual: $flow->getOwner());
			$this->assertSame(expected: 'org-1', actual: $flow->getOrganisation());
			$this->assertSame(expected: 'hermiq', actual: $flow->getApp());
		}

		$this->assertSame(
			expected: ['Flow A' => 'seeded', 'Flow B' => 'seeded'],
			actual: json_decode((string)$recorded[SeedBundledFlows::OUTCOME_KEY], true)
		);
	}//end testAnEmptyStoreGetsEveryFlowDisabledAndOwnerless()

	/**
	 * A flow already present under its name, from any app, is left alone.
	 *
	 * @return void
	 */
	public function testAFlowAlreadyPresentIsNotWrittenAgain(): void {
		$inserted = new \ArrayObject();
		$step     = new SeedBundledFlows(
			container: $this->container(mapper: $this->mapper(names: ['Flow A'], inserted: $inserted)),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			definitions: $this->definitions(files: ['a.flow.json' => self::flowJson(name: 'Flow A'), 'b.flow.json' => self::flowJson(name: 'Flow B')])
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: ['Flow B'], actual: array_map(static fn (Flow $flow): ?string => $flow->getName(), $inserted->getArrayCopy()));
	}//end testAFlowAlreadyPresentIsNotWrittenAgain()

	/**
	 * A flow the instance cannot run is reported and not seeded, and the step does not fail.
	 *
	 * @return void
	 */
	public function testAFlowThePreflightRefusesIsReportedNotSeeded(): void {
		$preflight = $this->createMock(originalClassName: FlowNodePreflight::class);
		$preflight->method('inspect')->willReturnCallback(
			static fn (array $flow): array => [
				'blocking' => ($flow['name'] === 'Flow A') ? [['step' => 'x', 'type' => 'openconnector.source-call', 'reason' => 'owner-not-installed']] : [],
				'warnings' => [],
			]
		);
		$preflight->method('describe')->willReturn('openconnector is not installed');

		$inserted = new \ArrayObject();
		$recorded = new \ArrayObject();
		$output   = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('openconnector is not installed'));

		$step = new SeedBundledFlows(
			container: $this->container(mapper: $this->mapper(names: [], inserted: $inserted), preflight: $preflight),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			appConfig: $this->appConfig(recorded: $recorded),
			definitions: $this->definitions(files: ['a.flow.json' => self::flowJson(name: 'Flow A'), 'b.flow.json' => self::flowJson(name: 'Flow B')])
		);
		$step->run($output);

		$this->assertCount(expectedCount: 1, haystack: $inserted);
		$outcomes = json_decode((string)$recorded[SeedBundledFlows::OUTCOME_KEY], true);
		$this->assertSame(expected: 'refused: openconnector is not installed', actual: $outcomes['Flow A']);
	}//end testAFlowThePreflightRefusesIsReportedNotSeeded()

	/**
	 * A failed write fails the step, after every other flow was attempted and the outcome kept.
	 *
	 * @return void
	 */
	public function testAFailedWriteFailsTheStepAfterTheRest(): void {
		$inserted = new \ArrayObject();
		$recorded = new \ArrayObject();
		$step     = new SeedBundledFlows(
			container: $this->container(mapper: $this->mapper(names: [], inserted: $inserted, failFor: 'Flow A')),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			appConfig: $this->appConfig(recorded: $recorded),
			definitions: $this->definitions(files: ['a.flow.json' => self::flowJson(name: 'Flow A'), 'b.flow.json' => self::flowJson(name: 'Flow B')])
		);

		try {
			$step->run($this->createMock(originalClassName: IOutput::class));
			$this->fail(message: 'A failed seed must not report a clean install.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(needle: 'Flow A', haystack: $e->getMessage());
		}

		$this->assertCount(expectedCount: 1, haystack: $inserted, message: 'Flow B is still seeded.');
		$outcomes = json_decode((string)$recorded[SeedBundledFlows::OUTCOME_KEY], true);
		$this->assertStringStartsWith(prefix: 'failed: ', string: $outcomes['Flow A']);
	}//end testAFailedWriteFailsTheStepAfterTheRest()

	/**
	 * A shipped file that is not a definition is a failure, not a silent skip.
	 *
	 * @return void
	 */
	public function testABrokenDefinitionFileFailsTheStep(): void {
		$step = new SeedBundledFlows(
			container: $this->container(mapper: $this->mapper(names: [], inserted: new \ArrayObject())),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			definitions: $this->definitions(files: ['broken.flow.json' => '{not json'])
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('broken.flow.json');
		$step->run($this->createMock(originalClassName: IOutput::class));
	}//end testABrokenDefinitionFileFailsTheStep()

	/**
	 * Without OpenRegister, or without a default organisation, nothing is written and nothing fails.
	 *
	 * @return void
	 */
	public function testNoStoreOrNoOrganisationSkipsTheSeed(): void {
		$inserted    = new \ArrayObject();
		$definitions = $this->definitions(files: ['a.flow.json' => self::flowJson(name: 'Flow A')]);
		$logger      = $this->createMock(originalClassName: LoggerInterface::class);

		foreach ([$this->container(mapper: null), $this->container(mapper: $this->mapper(names: [], inserted: $inserted), orgUuid: null)] as $container) {
			$recorded = new \ArrayObject();
			$output   = $this->createMock(originalClassName: IOutput::class);
			$output->expects($this->once())->method('warning');
			(new SeedBundledFlows(container: $container, logger: $logger, appConfig: $this->appConfig(recorded: $recorded), definitions: $definitions))->run($output);
			$this->assertStringContainsString(needle: 'unavailable', haystack: (string)$recorded[SeedBundledFlows::OUTCOME_KEY]);
		}

		$this->assertCount(expectedCount: 0, haystack: $inserted);
	}//end testNoStoreOrNoOrganisationSkipsTheSeed()
}//end class
