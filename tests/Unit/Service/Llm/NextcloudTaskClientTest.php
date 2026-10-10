<?php

/**
 * Unit tests for NextcloudTaskClient (use-nextcloud-ai-settings).
 *
 * Covers: the task type, input and userId of a chat turn; the chatwithtools loop mapping
 * tools and tool calls both ways; the admin message when no provider serves a type; the
 * refusal to loop into hermiq's own provider; the bounded wait for an asynchronous
 * provider; and a failed task's error surfacing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-6-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message;
use OCA\Hermiq\Service\Llm\NextcloudTaskClient;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for NextcloudTaskClient.
 *
 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-6-1
 */
class NextcloudTaskClientTest extends TestCase {
	/**
	 * A task manager whose types are all served by one synchronous provider.
	 *
	 * @param list<string> $types The available task type ids.
	 * @param string $providerId The preferred provider's id.
	 *
	 * @return IManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function manager(array $types, string $providerId = 'integration_openai-text2text:chat'): IManager {
		$provider = $this->createMock(ISynchronousProvider::class);
		$provider->method('getId')->willReturn($providerId);
		$provider->method('getName')->willReturn('OpenAI and LocalAI integration');

		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn($types);
		$manager->method('getPreferredProvider')->willReturn($provider);
		return $manager;
	}//end manager()

	/**
	 * A finished task carrying the given output.
	 *
	 * @param Task $task The task that was run.
	 * @param array<string, mixed> $output The output.
	 *
	 * @return Task
	 */
	private function done(Task $task, array $output): Task {
		$task->setStatus(Task::STATUS_SUCCESSFUL);
		$task->setOutput($output);
		return $task;
	}//end done()

	/**
	 * A chat turn without tools runs core:text2text:chat as the given user, with the
	 * system prompt, the user's message as input and earlier turns as JSON history.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function testChatRunsTheChatTaskAsTheUser(): void {
		$manager = $this->manager(['core:text2text:chat']);
		$seen = null;
		$manager->expects($this->once())->method('runTask')->willReturnCallback(
			function (Task $task) use (&$seen): Task {
				$seen = $task;
				return $this->done($task, ['output' => 'Hi there']);
			}
		);

		$client = new NextcloudTaskClient($manager, new NullLogger());
		$reply = $client->chat(
			messageHistory: [
				Message::system('You are helpful.'),
				Message::user('Earlier question'),
				Message::assistant('Earlier answer'),
				Message::user('Hello'),
			],
			userId: 'alice'
		);

		$this->assertSame('Hi there', $reply);
		$this->assertSame('core:text2text:chat', $seen->getTaskTypeId());
		$this->assertSame('alice', $seen->getUserId());
		$this->assertSame('hermiq', $seen->getAppId());
		$this->assertSame(
			[
				'system_prompt' => 'You are helpful.',
				'input' => 'Hello',
				'history' => [
					'{"role":"user","content":"Earlier question"}',
					'{"role":"assistant","content":"Earlier answer"}',
				],
			],
			$seen->getInput()
		);
	}//end testChatRunsTheChatTaskAsTheUser()

	/**
	 * The engine's history already ends with the user's turn and the handler appends it
	 * again; the task carries it once, as input, not also as the last history entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function testTheUsersTurnIsSentOnce(): void {
		$split = (new \OCA\Hermiq\Service\Llm\NextcloudChatMapper())->splitHistory(
			messageHistory: [
				Message::system('Sys'),
				Message::user('Earlier'),
				Message::assistant('Answer'),
				Message::user('Which tools do you have?'),
				Message::user('Which tools do you have?'),
			]
		);

		$this->assertSame('Which tools do you have?', $split['input']);
		$this->assertSame(
			['{"role":"user","content":"Earlier"}', '{"role":"assistant","content":"Answer"}'],
			$split['history']
		);
	}//end testTheUsersTurnIsSentOnce()

	/**
	 * With tools, the turn runs core:text2text:chatwithtools: the tools go out as OpenAI
	 * tools JSON, a tool call comes back and is executed, and the follow-up task carries
	 * the assistant's tool call in history and the result in tool_message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function testToolsMapBothWays(): void {
		$manager = $this->manager(['core:text2text:chatwithtools', 'core:text2text:chat']);
		$tasks = [];
		$manager->expects($this->exactly(2))->method('runTask')->willReturnCallback(
			function (Task $task) use (&$tasks): Task {
				$tasks[] = $task;
				if (count($tasks) === 1) {
					return $this->done($task, ['output' => '', 'tool_calls' => '[{"name":"search","id":"call_1","args":{"q":"zaak 42"}}]']);
				}

				return $this->done($task, ['output' => 'Found zaak 42.', 'tool_calls' => '']);
			}
		);

		$executed = [];
		$executor = static function (string $name, array $args) use (&$executed): string {
			$executed[] = [$name, $args];
			return '{"hits":1}';
		};

		$client = new NextcloudTaskClient($manager, new NullLogger());
		$reply = $client->chat(
			messageHistory: [Message::system('Sys'), Message::user('Find zaak 42')],
			userId: 'bob',
			functions: [['name' => 'search', 'description' => 'Search', 'parameters' => ['type' => 'object', 'properties' => []]]],
			toolExecutor: $executor
		);

		$this->assertSame('Found zaak 42.', $reply);
		$this->assertSame([['search', ['q' => 'zaak 42']]], $executed);

		$first = $tasks[0]->getInput();
		$this->assertSame('core:text2text:chatwithtools', $tasks[0]->getTaskTypeId());
		$this->assertSame('bob', $tasks[0]->getUserId());
		$this->assertSame('Find zaak 42', $first['input']);
		$this->assertSame('', $first['tool_message']);
		$this->assertSame(
			'[{"type":"function","function":{"name":"search","description":"Search","parameters":{"type":"object","properties":{}}}}]',
			$first['tools']
		);

		$second = $tasks[1]->getInput();
		$this->assertSame('', $second['input']);
		$this->assertSame(
			[
				'{"role":"user","content":"Find zaak 42"}',
				'{"role":"assistant","content":"","tool_calls":[{"id":"call_1","name":"search","args":{"q":"zaak 42"}}]}',
			],
			$second['history']
		);
		$this->assertSame('[{"tool_call_id":"call_1","name":"search","content":"{\"hits\":1}"}]', $second['tool_message']);
	}//end testToolsMapBothWays()

	/**
	 * A turn with tools and no chatwithtools provider fails loudly; it does not run
	 * without its tools.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function testToolsWithoutAChatWithToolsProviderFailLoudly(): void {
		$manager = $this->manager(['core:text2text:chat']);
		$manager->expects($this->never())->method('runTask');

		$client = new NextcloudTaskClient($manager, new NullLogger());

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage(NextcloudTaskClient::ADMIN_MESSAGE);
		$client->chat(
			messageHistory: [Message::user('Hi')],
			userId: 'alice',
			functions: [['name' => 'search']],
			toolExecutor: static fn (): string => ''
		);
	}//end testToolsWithoutAChatWithToolsProviderFailLoudly()

	/**
	 * No provider for the needed type gives the admin message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-3-1
	 */
	public function testMissingProviderGivesTheAdminMessage(): void {
		$manager = $this->manager([]);
		$manager->expects($this->never())->method('runTask');

		$client = new NextcloudTaskClient($manager, new NullLogger());

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('Configure an AI provider under Administration settings > Artificial intelligence.');
		$client->generate(prompt: 'Summarise this', userId: 'alice');
	}//end testMissingProviderGivesTheAdminMessage()

	/**
	 * When the preferred provider for a consumed type is hermiq's own, the client refuses
	 * instead of looping.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-4-2
	 */
	public function testHermiqAsPreferredProviderIsRefused(): void {
		$manager = $this->manager(['core:text2text'], 'hermiq:text2text');
		$manager->expects($this->never())->method('runTask');

		$client = new NextcloudTaskClient($manager, new NullLogger());

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage('would loop');
		$client->generate(prompt: 'x', userId: null);
	}//end testHermiqAsPreferredProviderIsRefused()

	/**
	 * An asynchronous provider that never finishes: the wait stops at the bound, the task
	 * is cancelled and the error says the provider did not answer in time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-1
	 */
	public function testAsyncProviderTimesOutAndCancels(): void {
		$provider = $this->createMock(IProvider::class);
		$provider->method('getId')->willReturn('some_exapp:chat');

		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn(['core:text2text:chat']);
		$manager->method('getPreferredProvider')->willReturn($provider);
		$manager->expects($this->never())->method('runTask');
		$manager->expects($this->once())->method('scheduleTask')->willReturnCallback(
			static function (Task $task): void {
				$task->setId(7);
			}
		);
		$manager->method('getTask')->willReturnCallback(
			static function (int $id): Task {
				$task = new Task('core:text2text:chat', [], 'hermiq', 'alice');
				$task->setId($id);
				$task->setStatus(Task::STATUS_SCHEDULED);
				return $task;
			}
		);
		$manager->expects($this->once())->method('cancelTask')->with(7);

		$clock = $this->createMock(ITimeFactory::class);
		$time = 1000;
		$clock->method('getTime')->willReturnCallback(
			static function () use (&$time): int {
				$time += 30;
				return $time;
			}
		);

		$client = new class($manager, new NullLogger(), $clock) extends NextcloudTaskClient {
			/**
			 * No real sleeping in a unit test.
			 *
			 * @return void
			 */
			protected function pause(): void {
			}//end pause()
		};

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionCode(504);
		$this->expectExceptionMessage('did not answer within 120 seconds');
		$client->chat(messageHistory: [Message::user('Hi')], userId: 'alice');
	}//end testAsyncProviderTimesOutAndCancels()

	/**
	 * A failed task surfaces its error instead of an empty reply.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-1
	 */
	public function testFailedTaskSurfacesItsError(): void {
		$manager = $this->manager(['core:text2text']);
		$manager->method('runTask')->willReturnCallback(
			static function (Task $task): Task {
				$task->setStatus(Task::STATUS_FAILED);
				$task->setErrorMessage('OpenAI/LocalAI request failed: invalid x-api-key');
				return $task;
			}
		);

		$client = new NextcloudTaskClient($manager, new NullLogger());

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage('Nextcloud AI task failed: OpenAI/LocalAI request failed: invalid x-api-key');
		$client->generate(prompt: 'x', userId: 'alice');
	}//end testFailedTaskSurfacesItsError()
}//end class
