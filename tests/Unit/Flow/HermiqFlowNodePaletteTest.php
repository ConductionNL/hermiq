<?php

/**
 * The palette entries and run-log links of hermiq's flow nodes.
 *
 * A node without a name, description or icon is an unnamed box in the flow
 * editor, and a node offered in the wrong scope is either unusable or shown
 * where it should not be. The agent step's run-log link must point at the
 * session the step recorded, and must be absent when there is none.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqAgentNode;
use OCA\Hermiq\Flow\HermiqWorkloadCollectNode;
use OCA\Hermiq\Flow\HermiqWorkloadNode;
use OCA\Hermiq\Service\AsyncStageDispatchService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\StageDispatchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Flow\HermiqAgentNode
 * @covers \OCA\Hermiq\Flow\HermiqWorkloadNode
 * @covers \OCA\Hermiq\Flow\HermiqWorkloadCollectNode
 */
class HermiqFlowNodePaletteTest extends TestCase {

	/**
	 * An l10n that answers the source string, marked so a test can see the
	 * string went through translation.
	 *
	 * @return IL10N The l10n.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[' . $text . ']');
		return $l10n;
	}//end l10n()

	/**
	 * A URL generator that serves hermiq's dark icon and its dashboard root.
	 *
	 * @param string $root The dashboard root the generator answers.
	 *
	 * @return IURLGenerator The generator.
	 */
	private function urls(string $root = '/apps/hermiq/'): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(
			static fn (string $app, string $image): string => '/apps/' . $app . '/img/' . $image
		);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route): string => ($route === 'hermiq.dashboard.page' ? $root : '/wrong-route')
		);
		return $urls;
	}//end urls()

	/**
	 * The agent step under test.
	 *
	 * @param string $root The dashboard root.
	 *
	 * @return HermiqAgentNode The node.
	 */
	private function agentNode(string $root = '/apps/hermiq/'): HermiqAgentNode {
		return new HermiqAgentNode(
			scheduleService: $this->createMock(ScheduleService::class),
			l10n: $this->l10n(),
			urls: $this->urls(root: $root)
		);
	}//end agentNode()

	/**
	 * The agent step's palette entry is translated and carries hermiq's icon.
	 *
	 * @return void
	 */
	public function testTheAgentStepPaletteEntry(): void {
		$node = $this->agentNode();

		$this->assertSame('hermiq.agent-step', $node->getId());
		$this->assertSame('[Agent step]', $node->getDisplayName());
		$this->assertSame('[Run an agent turn and put its answer on the item.]', $node->getDescription());
		$this->assertSame('/apps/hermiq/img/app-dark.svg', $node->getIcon());
	}//end testTheAgentStepPaletteEntry()

	/**
	 * The workload step's palette entry is translated and carries hermiq's icon.
	 *
	 * @return void
	 */
	public function testTheWorkloadStepPaletteEntry(): void {
		$node = new HermiqWorkloadNode(
			$this->createMock(StageDispatchService::class),
			$this->l10n(),
			$this->urls()
		);

		$this->assertSame('[Workload step]', $node->getDisplayName());
		$this->assertSame(
			'[Check out a ref and run a command over it, then put the result on the item.]',
			$node->getDescription()
		);
		$this->assertSame('/apps/hermiq/img/app-dark.svg', $node->getIcon());
	}//end testTheWorkloadStepPaletteEntry()

	/**
	 * The collect step's palette entry is translated and carries hermiq's icon.
	 *
	 * @return void
	 */
	public function testTheCollectStepPaletteEntry(): void {
		$node = new HermiqWorkloadCollectNode(
			$this->createMock(AsyncStageDispatchService::class),
			$this->l10n(),
			$this->urls()
		);

		$this->assertSame('[Collect workload]', $node->getDisplayName());
		$this->assertSame(
			'[Ask what became of a workload that was started asynchronously.]',
			$node->getDescription()
		);
		$this->assertSame('/apps/hermiq/img/app-dark.svg', $node->getIcon());
	}//end testTheCollectStepPaletteEntry()

	/**
	 * The agent and workload steps are offered in the admin and user scopes
	 * and in no other.
	 *
	 * @return void
	 */
	public function testTheStepsAreOfferedInTheAdminAndUserScopesOnly(): void {
		$nodes = [
			$this->agentNode(),
			new HermiqWorkloadNode($this->createMock(StageDispatchService::class), $this->l10n(), $this->urls()),
		];

		foreach ($nodes as $node) {
			$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN), $node::class);
			$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_USER), $node::class);
			$this->assertFalse($node->isAvailableForScope(42), $node::class);
		}
	}//end testTheStepsAreOfferedInTheAdminAndUserScopesOnly()

	/**
	 * A recorded session becomes one link to that chat, as a hash fragment on
	 * hermiq's root with the session id encoded.
	 *
	 * @return void
	 */
	public function testARecordedSessionLinksToItsChat(): void {
		$entry = ['output' => ['items' => [['json' => ['sessionId' => ' s/1 ']], ['json' => ['sessionId' => 'other']]]]];

		$this->assertSame(
			[['label' => '[Open the agent session]', 'href' => '/apps/hermiq#/chat/s%2F1']],
			$this->agentNode(root: '/apps/hermiq/')->logActions($entry)
		);
		$this->assertSame(
			[['label' => '[Open the agent session]', 'href' => '/index.php/apps/hermiq#/chat/s%2F1']],
			$this->agentNode(root: '/index.php/apps/hermiq')->logActions($entry)
		);
	}//end testARecordedSessionLinksToItsChat()

	/**
	 * No recorded session, a blank one or no output at all gives no link.
	 *
	 * @return void
	 */
	public function testNoSessionGivesNoLink(): void {
		$node = $this->agentNode();

		$this->assertSame([], $node->logActions([]));
		$this->assertSame([], $node->logActions(['output' => ['items' => []]]));
		$this->assertSame([], $node->logActions(['output' => ['items' => [['json' => ['sessionId' => '  ']]]]]));
		$this->assertSame([], $node->logActions(['output' => ['items' => [['json' => ['answer' => 'x']]]]]));
	}//end testNoSessionGivesNoLink()
}//end class
