<?php

/**
 * Unit tests for the report of agents with no tools (tool-scope-security-default).
 *
 * The report names every agent whose `tools` is unset or empty, by name and id,
 * and writes nothing: granting the tools those agents used to receive would
 * bring back the unscoped default the change removes.
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
 * @spec openspec/changes/tool-scope-security-default/specs/agent-tool-governance/spec.md#requirement-affected-agents-must-be-reportable
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\ReportToolessAgents;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The report lists unconfigured agents and changes none.
 */
class ReportToolessAgentsTest extends TestCase {

	/**
	 * An agent with the given data.
	 *
	 * @param string               $uuid The agent's uuid.
	 * @param array<string, mixed> $data The agent's data.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $uuid, array $data): ObjectEntity {
		$agent = $this->createMock(ObjectEntity::class);
		$agent->method('getUuid')->willReturn($uuid);
		$agent->method('getObject')->willReturn($data);
		return $agent;
	}//end agent()

	/**
	 * The agents of the scenario: unset, null and empty tools, plus two configured ones.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function agents(): array {
		return [
			$this->agent('a-1', ['name' => 'Notulist']),
			$this->agent('a-2', ['name' => 'Samenvatter', 'tools' => null]),
			$this->agent('a-3', ['name' => 'Vertaler', 'tools' => []]),
			$this->agent('a-4', ['name' => 'Zoeker', 'tools' => ['openregister.objects.search']]),
			$this->agent('a-5', ['name' => 'Kapot', 'tools' => ['app.that.is.gone']]),
		];
	}//end agents()

	/**
	 * Unset, null and empty tools are reported; any configured list is not, even one that resolves to nothing.
	 *
	 * @return void
	 */
	public function testItNamesEveryAgentWithoutTools(): void {
		self::assertSame(
			['a-1' => 'Notulist', 'a-2' => 'Samenvatter', 'a-3' => 'Vertaler'],
			ReportToolessAgents::toolless(agents: $this->agents())
		);
	}//end testItNamesEveryAgentWithoutTools()

	/**
	 * A run prints each agent by name and id, reads with RBAC off, and never writes.
	 *
	 * @return void
	 */
	public function testARunListsTheAgentsAndWritesNothing(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->with('hermiq')->willReturnSelf();
		$objectService->method('setSchema')->with('agent')->willReturnSelf();
		$objectService->expects($this->once())->method('findAll')
			->with(['limit' => 10000], false, false)
			->willReturn($this->agents());
		$objectService->expects($this->never())->method('saveObject');
		$objectService->expects($this->never())->method('patchObject');
		$objectService->expects($this->never())->method('deleteObject');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($objectService);

		$lines  = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			}
		);

		(new ReportToolessAgents($container, $this->createMock(LoggerInterface::class)))->run($output);

		self::assertSame(
			[
				'3 agent(s) have no tools configured and run text-only. Grant tools on the agent to give it any.',
				'No tools: "Notulist" (a-1)',
				'No tools: "Samenvatter" (a-2)',
				'No tools: "Vertaler" (a-3)',
			],
			$lines
		);
	}//end testARunListsTheAgentsAndWritesNothing()

	/**
	 * Without OpenRegister the report warns and does not fail the upgrade.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItWarnsAndStops(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');
		$output->expects($this->never())->method('info');

		(new ReportToolessAgents($container, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWithoutOpenRegisterItWarnsAndStops()
}//end class
