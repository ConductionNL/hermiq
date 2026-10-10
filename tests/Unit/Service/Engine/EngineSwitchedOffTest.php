<?php

/**
 * Unit tests for the engine seam of the agent switch.
 *
 * `Engine::processMessage()` serves chat, the stream, Talk, the ContextAgent
 * provider and the engine branch of scheduled runs, so a switched-off agent
 * refused here is refused on all of them. The second test lists every caller
 * of the two run entry points, so a new caller fails until it is reviewed.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Agent\AgentSwitchedOffException;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that the engine refuses a turn of a switched-off agent.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
 */
class EngineSwitchedOffTest extends TestCase {

	/**
	 * A switched-off agent: no message is stored and no model is called.
	 *
	 * @return void
	 */
	public function testSwitchedOffAgentIsRefusedBeforeAnythingHappens(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				if ($id === 'conv-1') {
					$entity->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
					return $entity;
				}

				$entity->setObject(['name' => 'Permit reminder', 'active' => false]);
				return $entity;
			}
		);

		$history = $this->createMock(MessageHistoryHandler::class);
		$history->expects($this->never())->method('storeMessage');
		$response = $this->createMock(ResponseGenerationHandler::class);
		$response->expects($this->never())->method('generateResponse');

		$engine = new Engine(
			$objectService,
			$this->createMock(ContextRetrievalHandler::class),
			$response,
			$this->createMock(ConversationManagementHandler::class),
			$history,
			$this->createMock(ContextAssembler::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->expectException(AgentSwitchedOffException::class);
		$this->expectExceptionCode(409);

		$engine->processMessage(conversationId: 'conv-1', userId: 'alice', userMessage: 'remind them');

	}//end testSwitchedOffAgentIsRefusedBeforeAnythingHappens()

	/**
	 * Every caller of the two run entry points. A new caller fails this test
	 * until someone confirms it goes through a seam that asks the switch
	 * (`Engine::processMessage()` itself, `ScheduleService::runAgentAsOwner()`,
	 * or `AssistantService::converse()`), then adds it here.
	 *
	 * @return void
	 */
	public function testEveryCallerOfTheRunEntryPointsIsKnown(): void {
		$root = dirname(__DIR__, 4) . '/lib';
		$found = [
			'processMessage' => [],
			'runAgentAsOwner' => [],
		];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			$relative = substr($file->getPathname(), strlen($root) + 1);
			if (preg_match('/->engine->processMessage\(/', $source) === 1) {
				$found['processMessage'][] = $relative;
			}

			if (preg_match('/->runAgentAsOwner\(/', $source) === 1) {
				$found['runAgentAsOwner'][] = $relative;
			}
		}

		sort($found['processMessage']);
		sort($found['runAgentAsOwner']);

		$this->assertSame(
			[
				'Controller/ChatController.php',
				'Controller/ChatStreamController.php',
				'Service/ContextAgentInteractionService.php',
				'Service/ScheduleService.php',
				'Service/Talk/TalkTurnService.php',
			],
			$found['processMessage'],
			'A new caller of Engine::processMessage(): confirm the agent switch covers it, then list it here.'
		);
		$this->assertSame(
			[
				'Flow/HermiqAgentNode.php',
				'Service/DelegationService.php',
				'Service/EvalRunService.php',
				'Service/FlowAgentRunService.php',
				'Service/GoalService.php',
				'Service/ScheduleService.php',
				'Service/WebhookAgentRunService.php',
			],
			$found['runAgentAsOwner'],
			'A new caller of ScheduleService::runAgentAsOwner(): confirm the agent switch covers it, then list it here.'
		);

	}//end testEveryCallerOfTheRunEntryPointsIsKnown()
}//end class
