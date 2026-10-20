<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\ProvisionAiOversightGroup;
use OCA\Hermiq\Service\AiOversightAccess;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the AI oversight group provisioning: created once, never touched again.
 *
 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
 */
class ProvisionAiOversightGroupTest extends TestCase {

	/**
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * @var IOutput&MockObject
	 */
	private $output;

	/**
	 * @var ProvisionAiOversightGroup
	 */
	private ProvisionAiOversightGroup $step;


	/**
	 * Set up the subject under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->output       = $this->createMock(IOutput::class);
		$this->step         = new ProvisionAiOversightGroup($this->groupManager, $this->createMock(LoggerInterface::class));

	}//end setUp()


	/**
	 * A missing group is created, with no members.
	 *
	 * @return void
	 */
	public function testMissingGroupIsCreated(): void {
		$this->groupManager->method('groupExists')->with(AiOversightAccess::OVERSIGHT_GROUP)->willReturn(false);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->never())->method('addUser');
		$this->groupManager->expects($this->once())
			->method('createGroup')
			->with('ai-oversight')
			->willReturn($group);
		$this->output->expects($this->once())->method('info');

		$this->step->run($this->output);

	}//end testMissingGroupIsCreated()


	/**
	 * Running again over an existing group changes nothing.
	 *
	 * @return void
	 */
	public function testExistingGroupIsLeftAlone(): void {
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->expects($this->never())->method('createGroup');
		$this->output->expects($this->never())->method('info');
		$this->output->expects($this->never())->method('warning');

		$this->step->run($this->output);

	}//end testExistingGroupIsLeftAlone()


	/**
	 * A backend that refuses the group is reported, not fatal.
	 *
	 * @return void
	 */
	public function testRefusedCreationWarns(): void {
		$this->groupManager->method('groupExists')->willReturn(false);
		$this->groupManager->method('createGroup')->willReturn(null);
		$this->output->expects($this->once())->method('warning');

		$this->step->run($this->output);

	}//end testRefusedCreationWarns()


	/**
	 * The step has a name for the repair log.
	 *
	 * @return void
	 */
	public function testHasAName(): void {
		$this->assertSame('Create the AI oversight group', $this->step->getName());

	}//end testHasAName()


}//end class
