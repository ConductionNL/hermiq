<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Service\RemoteAgent
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\RemoteAgent;

use InvalidArgumentException;
use OCA\Hermiq\Service\Handoff\FlowTurn;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCA\Hermiq\Service\Handoff\HandoffSweep;
use OCA\Hermiq\Service\RemoteAgent\A2aClient;
use OCA\Hermiq\Service\RemoteAgent\RemoteAgentService;
use OCA\Hermiq\Service\RemoteAgent\RemoteAgentUnavailableException;
use OCA\Hermiq\Service\RemoteAgent\RemoteTasks;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Remote agents over A2A: every request through integriq's CallService on the agent's source.
 *
 * @covers \OCA\Hermiq\Service\RemoteAgent\A2aClient
 * @covers \OCA\Hermiq\Service\RemoteAgent\RemoteAgentService
 * @covers \OCA\Hermiq\Service\RemoteAgent\RemoteTasks
 * @covers \OCA\Hermiq\Service\Handoff\HandoffSweep
 */
class RemoteAgentTest extends TestCase {

	/**
	 * The calls integriq's CallService received: [endpoint, method, decoded body].
	 *
	 * @var array<int, array{0: string, 1: string, 2: mixed}>
	 */
	public array $calls = [];

	/**
	 * The answers CallService gives, by endpoint, as [status, body]; a closure answers by request body.
	 *
	 * @var array<string, mixed>
	 */
	public array $answers = [];

	/**
	 * Stored remote agents by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $agents = [];

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The A2A client under test.
	 *
	 * @var A2aClient
	 */
	private A2aClient $a2a;

	/**
	 * A source "kvk-a2a", a CallService fake that records every call, and an in-memory remote agent store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$test = $this;
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('find')->willReturnCallback(function (string $id, mixed $extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
			if ($schema === 'source') {
				return ($id === 'src-kvk') ? $this->entity(uuid: 'src-kvk', data: ['name' => 'kvk-a2a']) : null;
			}

			return isset($this->agents[$id]) ? $this->entity(uuid: $id, data: $this->agents[$id]) : null;
		});
		$this->objects->method('findAll')->willReturnCallback(function (array $config): array {
			$found = [];
			foreach ($this->agents as $uuid => $data) {
				$slug = ($config['filters']['slug'] ?? null);
				if ($slug === null || $data['slug'] === $slug) {
					$found[] = $this->entity(uuid: $uuid, data: $data);
				}
			}

			return $found;
		});
		$this->objects->method('saveObject')->willReturnCallback(function (array $object, mixed $extend = null, mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
			$uuid = ($uuid ?? 'ra-' . (count($this->agents) + 1));
			$this->agents[$uuid] = $object;
			return $this->entity(uuid: $uuid, data: $object);
		});

		$callService = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param RemoteAgentTest $test The test recording the calls.
			 */
			public function __construct(private readonly RemoteAgentTest $test) {
			}//end __construct()

			/**
			 * The CallService call() shape.
			 *
			 * @param ObjectEntity         $source   The source.
			 * @param string               $endpoint The endpoint.
			 * @param string               $method   The method.
			 * @param array<string, mixed> $config   The config.
			 *
			 * @return ObjectEntity The call log.
			 */
			public function call(ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []): ObjectEntity {
				$body = json_decode((string) ($config['body'] ?? 'null'), true);
				$this->test->calls[] = [$endpoint, $method, $body];
				$answer = ($this->test->answers[$endpoint] ?? [404, '']);
				if (is_callable($answer) === true) {
					$answer = $answer($body);
				}

				return $this->test->entity(uuid: 'log', data: ['response' => ['statusCode' => $answer[0], 'body' => $answer[1], 'encoding' => 'UTF-8']]);
			}//end call()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($callService): object {
			if (str_ends_with($id, '\\Service\\CallService') === true) {
				return $callService;
			}

			throw new RuntimeException('not registered');
		});

		$this->a2a = new A2aClient(container: $container, objectService: $this->objects);
	}//end setUp()

	/**
	 * An ObjectEntity mock.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	public function entity(string $uuid, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * The KvK agent card.
	 *
	 * @return string
	 */
	private static function card(): string {
		return (string) json_encode([
			'name' => 'KvK-assistent',
			'description' => 'Zoekt bedrijven op in het Handelsregister.',
			'url' => 'https://agents.example-kvk.nl/a2a',
			'skills' => [['id' => 'lookup', 'name' => 'Bedrijfsgegevens opzoeken op KvK-nummer', 'description' => 'Op KvK-nummer.', 'tags' => ['kvk']]],
			'securitySchemes' => ['apiKey' => ['type' => 'apiKey']],
		]);
	}//end card()

	/**
	 * A JSON-RPC answer whose result is this.
	 *
	 * @param array<string, mixed> $result The result.
	 *
	 * @return array{0: int, 1: string}
	 */
	private static function rpcAnswer(array $result): array {
		return [200, (string) json_encode(['jsonrpc' => '2.0', 'id' => '1', 'result' => $result])];
	}//end rpcAnswer()

	/**
	 * The remote agent service and remote tasks over the in-memory store.
	 *
	 * @param HandoffService|null $handoffs The handoff record, a mock by default.
	 *
	 * @return array{0: RemoteAgentService, 1: RemoteTasks}
	 */
	private function services(?HandoffService $handoffs = null): array {
		$remoteAgents = new RemoteAgentService(objectService: $this->objects, a2a: $this->a2a);
		$tasks = new RemoteTasks(
			remoteAgents: $remoteAgents,
			a2a: $this->a2a,
			handoffs: ($handoffs ?? $this->createMock(HandoffService::class)),
			logger: $this->createMock(LoggerInterface::class)
		);

		return [$remoteAgents, $tasks];
	}//end services()

	/**
	 * Registering fetches the card through the source, keeps a snapshot without credentials, and starts switched off.
	 *
	 * @return void
	 */
	public function testRegisteringFetchesTheCardThroughTheSource(): void {
		$this->answers[A2aClient::CARD_PATH] = [200, self::card()];
		[$remoteAgents] = $this->services();

		$saved = $remoteAgents->register(input: ['name' => 'KvK-assistent', 'source' => 'src-kvk']);

		$this->assertSame([[A2aClient::CARD_PATH, 'GET', null]], $this->calls);
		$this->assertSame('kvk-assistent', $saved['slug']);
		$this->assertFalse($saved['enabled']);
		$this->assertSame('src-kvk', $saved['source']);
		$this->assertSame('Bedrijfsgegevens opzoeken op KvK-nummer', $saved['agentCard']['skills'][0]['name']);
		$this->assertArrayNotHasKey('securitySchemes', $saved['agentCard']);
		$this->assertSame('Zoekt bedrijven op in het Handelsregister.', $saved['description']);
	}//end testRegisteringFetchesTheCardThroughTheSource()

	/**
	 * A missing source, an unreachable card or a taken handle refuses the registration.
	 *
	 * @return void
	 */
	public function testABadRegistrationIsRefused(): void {
		[$remoteAgents] = $this->services();

		try {
			$remoteAgents->register(input: ['name' => 'KvK-assistent', 'source' => 'src-gone']);
			$this->fail('an unknown source must be refused');
		} catch (RemoteAgentUnavailableException $e) {
			$this->assertSame([], $this->calls, 'nothing is sent without a source');
		}

		$this->answers[A2aClient::CARD_PATH] = [200, self::card()];
		$remoteAgents->register(input: ['name' => 'KvK-assistent', 'source' => 'src-kvk']);

		$this->expectException(InvalidArgumentException::class);
		$remoteAgents->register(input: ['name' => 'KvK assistent', 'source' => 'src-kvk']);
	}//end testABadRegistrationIsRefused()

	/**
	 * A completed answer comes back as text from a message/send through the source.
	 *
	 * @return void
	 */
	public function testACompletedAnswerReturnsItsText(): void {
		$this->agents['ra-1'] = ['name' => 'KvK-assistent', 'slug' => 'kvk-assistent', 'source' => 'src-kvk', 'enabled' => true];
		$this->answers[''] = self::rpcAnswer(
			['kind' => 'task', 'id' => 't-1', 'status' => ['state' => 'completed'], 'artifacts' => [['parts' => [['kind' => 'text', 'text' => 'Conduction B.V., Amsterdam']]]]]
		);
		[, $tasks] = $this->services();

		$answer = $tasks->ask(remoteAgent: 'kvk-assistent', task: 'Geef de gegevens van KvK-nummer 12345678');

		$this->assertSame(['status' => 'completed', 'remoteAgent' => 'kvk-assistent', 'answer' => 'Conduction B.V., Amsterdam'], $answer);
		$this->assertSame('', $this->calls[0][0]);
		$this->assertSame('POST', $this->calls[0][1]);
		$this->assertSame('message/send', $this->calls[0][2]['method']);
		$this->assertSame('Geef de gegevens van KvK-nummer 12345678', $this->calls[0][2]['params']['message']['parts'][0]['text']);
	}//end testACompletedAnswerReturnsItsText()

	/**
	 * A switched-off or unknown remote agent is refused before anything leaves the instance.
	 *
	 * @return void
	 */
	public function testASwitchedOffAgentSendsNothing(): void {
		$this->agents['ra-1'] = ['name' => 'KvK-assistent', 'slug' => 'kvk-assistent', 'source' => 'src-kvk', 'enabled' => false];
		[, $tasks] = $this->services();

		$this->assertSame('remote_agent_unavailable', $tasks->ask(remoteAgent: 'kvk-assistent', task: 'x')['error']);
		$this->assertSame('remote_agent_unavailable', $tasks->ask(remoteAgent: 'other', task: 'x')['error']);
		$this->assertSame([], $this->calls);
	}//end testASwitchedOffAgentSendsNothing()

	/**
	 * A working task opens an a2a handoff in the turn; the sweep asks tasks/get and closes it when it completes.
	 *
	 * @return void
	 */
	public function testAWorkingTaskBecomesAHandoffThatThePollCloses(): void {
		$this->agents['ra-1'] = ['name' => 'KvK-assistent', 'slug' => 'kvk-assistent', 'source' => 'src-kvk', 'enabled' => true];
		$state = 'working';
		$this->answers[''] = function (array $body) use (&$state): array {
			$text = ($state === 'completed') ? [['parts' => [['kind' => 'text', 'text' => 'Klaar']]]] : [];
			return self::rpcAnswer(['kind' => 'task', 'id' => 't-9', 'status' => ['state' => $state], 'artifacts' => $text]);
		};
		$handoffs = $this->createMock(HandoffService::class);
		$handoffs->expects($this->once())->method('open')
			->with([
				'kind' => 'a2a', 'agentId' => 'agent-1', 'conversationId' => 'conv-1', 'requestedBy' => 'anne',
				'label' => 'KvK-assistent', 'target' => 'kvk-assistent', 'externalRef' => 't-9',
			])
			->willReturn(['id' => 'h-1']);
		$handoff = ['kind' => 'a2a', 'target' => 'kvk-assistent', 'externalRef' => 't-9', 'status' => 'waiting'];
		$handoffs->method('allWaiting')->willReturn(['h-1' => $handoff]);
		$handoffs->method('expireIfDue')->willReturn(false);
		$handoffs->expects($this->once())->method('close')->with('h-1', 'done', ['answer' => 'Klaar'])->willReturn(true);
		[, $tasks] = $this->services(handoffs: $handoffs);
		$sweep = new HandoffSweep(handoffs: $handoffs, remoteTasks: $tasks, logger: $this->createMock(LoggerInterface::class));

		$answer = $tasks->afterAsk(
			result: $tasks->ask(remoteAgent: 'kvk-assistent', task: 'Zoek op'),
			turn: new FlowTurn(agentId: 'agent-1', conversationId: 'conv-1', owner: 'anne')
		);
		$this->assertSame('h-1', $answer['handoff']);
		$this->assertSame(RemoteTasks::WORKING_NOTE, $answer['note']);

		$this->assertSame(['expired' => 0, 'done' => 0, 'failed' => 0, 'waiting' => 1], $sweep->run());
		$state = 'completed';
		$this->assertSame(['expired' => 0, 'done' => 1, 'failed' => 0, 'waiting' => 0], $sweep->run());
		$this->assertSame(['tasks/get', ['id' => 't-9']], [$this->calls[2][2]['method'], $this->calls[2][2]['params']]);
	}//end testAWorkingTaskBecomesAHandoffThatThePollCloses()

	/**
	 * The sweep expires a handoff past its deadline without asking anything.
	 *
	 * @return void
	 */
	public function testTheSweepExpiresOverdueHandoffs(): void {
		$handoffs = $this->createMock(HandoffService::class);
		$handoffs->method('allWaiting')->willReturn(['h-1' => ['kind' => 'webhook'], 'h-2' => ['kind' => 'a2a', 'target' => 'gone']]);
		$handoffs->method('expireIfDue')->willReturnCallback(static fn (string $id): bool => $id === 'h-1');
		$handoffs->expects($this->once())->method('close')->with('h-2', 'failed', ['message' => 'The remote agent was removed.']);
		[, $tasks] = $this->services(handoffs: $handoffs);

		$counts = (new HandoffSweep(handoffs: $handoffs, remoteTasks: $tasks, logger: $this->createMock(LoggerInterface::class)))->run();

		$this->assertSame(['expired' => 1, 'done' => 0, 'failed' => 1, 'waiting' => 0], $counts);
		$this->assertSame([], $this->calls);
	}//end testTheSweepExpiresOverdueHandoffs()

	/**
	 * A direct message is a completed answer; an error, a non-2xx status or a non-JSON body refuses.
	 *
	 * @return void
	 */
	public function testTheClientReadsAnswersAndRefusesErrors(): void {
		$this->assertSame(
			['state' => 'completed', 'text' => 'Hallo', 'taskId' => ''],
			A2aClient::readResult(result: ['kind' => 'message', 'parts' => [['kind' => 'text', 'text' => 'Hallo']]])
		);
		$this->assertSame(
			['state' => 'input-required', 'text' => 'Welk nummer?', 'taskId' => 't-2'],
			A2aClient::readResult(result: ['kind' => 'task', 'id' => 't-2', 'status' => ['state' => 'input-required', 'message' => ['parts' => [['text' => 'Welk nummer?']]]]])
		);

		foreach ([[500, '{}'], [200, 'not json'], [200, '{"jsonrpc":"2.0","error":{"code":-32601,"message":"Method not found"}}']] as $answer) {
			$this->answers[''] = $answer;
			try {
				$this->a2a->getTask(sourceId: 'src-kvk', taskId: 't-1');
				$this->fail('a bad answer must be refused');
			} catch (RemoteAgentUnavailableException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}//end testTheClientReadsAnswersAndRefusesErrors()
}//end class
