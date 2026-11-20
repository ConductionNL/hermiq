<?php

/**
 * Unit tests for the RefreshAgentTalkBots repair step.
 *
 * The step re-installs the bot of every Talk-enabled, not trashed agent, skips
 * the others, and turns every failure into a warning instead of failing the
 * upgrade.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Kernel\Contracts\TalkBotInstallerInterface;
use OCA\Hermiq\Repair\RefreshAgentTalkBots;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for RefreshAgentTalkBots.
 *
 * @covers \OCA\Hermiq\Repair\RefreshAgentTalkBots
 *
 * @spec openspec/changes/instant-talk/tasks.md#4-multi-agent-rooms
 */
class RefreshAgentTalkBotsTest extends TestCase {

	/**
	 * The installer.
	 *
	 * @var TalkBotInstallerInterface&MockObject
	 */
	private TalkBotInstallerInterface $installer;

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The migration output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput $output;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->installer     = $this->createMock(TalkBotInstallerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->output        = $this->createMock(IOutput::class);
	}//end setUp()

	/**
	 * The step under test, with OpenRegister present or not.
	 *
	 * @param bool $withOpenRegister Whether the container holds the object service.
	 *
	 * @return RefreshAgentTalkBots
	 */
	private function step(bool $withOpenRegister=true): RefreshAgentTalkBots {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($withOpenRegister);
		$container->method('get')->willReturn($this->objectService);

		return new RefreshAgentTalkBots($this->installer, $container, $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * An agent object.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $data    The agent's data.
	 * @param array|null           $deleted The trash marker.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $uuid, array $data, ?array $deleted=null): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid($uuid);
		$agent->setObject($data);
		$agent->setDeleted($deleted);
		return $agent;
	}//end agent()

	/**
	 * The step has a human-readable name.
	 *
	 * @return void
	 */
	public function testName(): void {
		self::assertSame('Refresh the Talk bots of Talk-enabled Hermiq agents', $this->step()->getName());
	}//end testName()

	/**
	 * Every Talk-enabled agent's bot is installed under its name; a disabled or
	 * trashed agent's is not, and the read is a system read over the agents.
	 *
	 * @return void
	 */
	public function testRefreshesOnlyTalkEnabledAgentsThatAreNotTrashed(): void {
		$this->objectService->expects(self::once())->method('findAll')
			->with(
				[
					'filters' => ['register' => 'hermiq', 'schema' => 'agent', 'talkEnabled' => true],
					'limit' => RefreshAgentTalkBots::MAX_AGENTS,
				],
				false,
				false
			)
			->willReturn(
				[
					$this->agent('a-1', ['name' => 'Helper', 'talkEnabled' => true]),
					$this->agent('a-2', ['name' => 'Off', 'talkEnabled' => false]),
					$this->agent('a-3', ['name' => 'Trashed', 'talkEnabled' => true], ['deleted' => '2026-11-01']),
					$this->agent('a-4', ['name' => 'Writer', 'talkEnabled' => true]),
				]
			);

		$installed = [];
		$this->installer->expects(self::exactly(2))->method('installForAgent')
			->willReturnCallback(
				static function (string $agentId, string $agentName) use (&$installed): bool {
					$installed[$agentId] = $agentName;
					return true;
				}
			);
		$this->output->expects(self::once())->method('info')
			->with('Refreshed the Talk bot of 2 of 2 Talk-enabled agents.');
		$this->output->expects(self::never())->method('warning');

		$this->step()->run($this->output);

		self::assertSame(['a-1' => 'Helper', 'a-4' => 'Writer'], $installed);
	}//end testRefreshesOnlyTalkEnabledAgentsThatAreNotTrashed()

	/**
	 * One failing agent does not stop the others and does not fail the step.
	 *
	 * @return void
	 */
	public function testOneFailingAgentDoesNotStopTheOthers(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->agent('a-1', ['name' => 'Broken', 'talkEnabled' => true]),
				$this->agent('a-2', ['name' => 'Fine', 'talkEnabled' => true]),
			]
		);
		$this->installer->expects(self::exactly(2))->method('installForAgent')
			->willReturnCallback(
				static function (string $agentId): bool {
					if ($agentId === 'a-1') {
						throw new RuntimeException('spreed refused');
					}

					return true;
				}
			);
		$this->output->expects(self::once())->method('info')
			->with('Refreshed the Talk bot of 1 of 2 Talk-enabled agents.');

		$this->step()->run($this->output);
	}//end testOneFailingAgentDoesNotStopTheOthers()

	/**
	 * With Talk absent the installer refreshes nothing and the step says so.
	 *
	 * @return void
	 */
	public function testReportsTalkBeingAbsent(): void {
		$this->objectService->method('findAll')->willReturn([$this->agent('a-1', ['name' => 'Helper', 'talkEnabled' => true])]);
		$this->installer->method('installForAgent')->willReturn(false);
		$this->output->expects(self::once())->method('info')
			->with('Talk is not available — skipping the agent Talk bot refresh.');

		$this->step()->run($this->output);
	}//end testReportsTalkBeingAbsent()

	/**
	 * Without OpenRegister or without agents nothing is installed.
	 *
	 * @return void
	 */
	public function testNoAgentsMeansNothingToRefresh(): void {
		$this->objectService->expects(self::never())->method('findAll');
		$this->installer->expects(self::never())->method('installForAgent');
		$this->output->expects(self::once())->method('info')
			->with('No Talk-enabled agents — no agent Talk bot to refresh.');

		$this->step(withOpenRegister: false)->run($this->output);
	}//end testNoAgentsMeansNothingToRefresh()

	/**
	 * A failing read becomes a warning, not a failed upgrade.
	 *
	 * @return void
	 */
	public function testAFailingReadBecomesAWarning(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->installer->expects(self::never())->method('installForAgent');
		$this->output->expects(self::once())->method('warning')
			->with('Could not refresh the agent Talk bots: db down');

		$this->step()->run($this->output);
	}//end testAFailingReadBecomesAWarning()
}//end class
