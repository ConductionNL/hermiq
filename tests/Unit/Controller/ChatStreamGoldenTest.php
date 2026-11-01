<?php

/**
 * Golden test for ChatStreamController::stream().
 *
 * Drives the SSE endpoint over generated sessions, request bodies, conversations,
 * agents and engine outcomes (guardrail pass, redaction, output block, input
 * block, refusals, failures) and compares, in order, every collaborator call,
 * every frame and every log line with a recorded fixture. A forged `userId`
 * rides in the body and on the request parameters; the engine and every access
 * check must still see the session user. Set GOLDEN_RECORD=1 to (re)write the
 * fixture; a run in record mode compares nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use Exception;
use OCA\Hermiq\Controller\ChatStreamController;
use OCA\Hermiq\Service\Agent\AgentSwitchedOffException;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\AiFeature\DataUseViolationException;
use OCA\Hermiq\Service\AppAssistantResolver;
use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Credential\PinnedCredentialRefusedException;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\Literacy\LiteracyRequiredException;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolutionException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Raised instead of exit; so the golden can read what the stream wrote.
 */
class ChatStreamGoldenStop extends Exception {
}//end class

/**
 * The stream with its wire, clock and exit replaced by recorders.
 */
class GoldenChatStreamController extends ChatStreamController {

	/**
	 * The shared, ordered event list.
	 *
	 * @var array<int, mixed>
	 */
	public array $events = [];

	/**
	 * The injected request body.
	 *
	 * @var string
	 */
	public string $requestBody = '';

	/**
	 * The scripted clock: seconds per now() call.
	 *
	 * @var array<int, float>
	 */
	public array $ticks = [];

	/**
	 * The scripted clock's current reading.
	 *
	 * @var float
	 */
	private float $clock = 1000.0;

	/**
	 * Whether production would have exited already.
	 *
	 * @var bool
	 */
	private bool $terminated = false;

	/**
	 * Record a frame unless production has exited.
	 *
	 * @param string $eventType Event type.
	 * @param array  $payload   Frame payload.
	 *
	 * @return void
	 */
	protected function emitSseEvent(string $eventType, array $payload): void {
		if ($this->terminated === true) {
			return;
		}

		if (isset($payload['ts']) === true) {
			$payload['ts'] = '<ts>';
		}

		$this->events[] = ['frame' => $eventType, 'payload' => $payload];
	}//end emitSseEvent()

	/**
	 * Record the terminal frame once, then stop.
	 *
	 * @param string $eventType Event type.
	 * @param array  $payload   Frame payload.
	 *
	 * @return never
	 */
	protected function emitAndExit(string $eventType, array $payload): never {
		if ($this->terminated === false) {
			$this->events[] = ['exit' => $eventType, 'payload' => $payload];
			$this->terminated = true;
		}

		throw new ChatStreamGoldenStop($eventType);
	}//end emitAndExit()

	/**
	 * The scripted clock.
	 *
	 * @return float
	 */
	protected function now(): float {
		$this->clock += (array_shift($this->ticks) ?? 1.0);
		return $this->clock;
	}//end now()

	/**
	 * The injected body.
	 *
	 * @return string
	 */
	protected function readRequestBody(): string {
		return $this->requestBody;
	}//end readRequestBody()

	/**
	 * Skipped under PHPUnit.
	 *
	 * @return void
	 */
	protected function clearOutputBuffers(): void {
	}//end clearOutputBuffers()

	/**
	 * Skipped under PHPUnit.
	 *
	 * @return void
	 */
	protected function emitSseHeaders(): void {
	}//end emitSseHeaders()
}//end class

/**
 * Golden comparison of the SSE stream endpoint.
 *
 * @covers \OCA\Hermiq\Controller\ChatStreamController
 * @covers \OCA\Hermiq\Service\Chat\ChatRefusalFrame
 *
 * @uses \OCA\Hermiq\Service\Engine\StreamYieldChannel
 * @uses \OCA\Hermiq\Service\GuardrailBlockedException
 * @uses \OCA\Hermiq\Service\Agent\AgentSwitchedOffException
 * @uses \OCA\Hermiq\Service\AiFeature\DataUseViolationException
 * @uses \OCA\Hermiq\Service\Chat\AttachmentRefusedException
 * @uses \OCA\Hermiq\Service\Credential\PinnedCredentialRefusedException
 * @uses \OCA\Hermiq\Service\Literacy\LiteracyRequiredException
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 */
class ChatStreamGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/chat-stream.json';

	private const CASES = 700;

	private const USERS = ['alice', 'alice', 'alice', 'bob', 'bob', null];

	private const MESSAGES = ['hi there', 'hi there', 'hi there', '  answer me ', '  answer me ', '', '   ', null, 42];

	private const AGENTS = ['agent-1', 'agent-private', 'agent-missing', '', null];

	private const CONVERSATIONS = ['conv-alice', 'conv-alice', 'conv-bob', 'conv-missing', '', '', null, null];

	private const OUTCOMES = [
		'pass', 'pass', 'redacted', 'output-blocked', 'response-key', 'empty', 'input-blocked',
		'tool-grants', 'attachment', 'attachment-wrapped', 'pinned', 'switched-off', 'literacy',
		'data-use', 'internal', 'wrapped-internal',
	];

	/**
	 * The ordered events of the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $events = [];

	/**
	 * Every generated case streams exactly as recorded.
	 *
	 * @return void
	 */
	public function testStreamMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			mt_srand($case + 1);
			$actual[] = $this->runCase();
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped('Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(count($expected), $actual);
		foreach ($expected as $case => $expectedCase) {
			// A per-case identity check: a full assertSame diff over the whole fixture takes minutes.
			$this->assertTrue($expectedCase === $actual[$case], 'Case ' . $case . ' differs from the fixture.');
		}
	}//end testStreamMatchesTheRecordedFixture()

	/**
	 * Runs one seeded case; returns its inputs and ordered events through JSON.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(): array {
		$this->events = [];
		$user = $this->pick(self::USERS);
		$body = $this->body();
		$outcome = $this->pick(self::OUTCOMES);
		$withAssistants = (mt_rand(0, 3) > 0);
		$withLiteracy = (mt_rand(0, 2) > 0);

		$controller = new GoldenChatStreamController(
			$this->request(),
			$this->engine(outcome: $outcome),
			$this->objectService(),
			$this->session(uid: $user),
			$this->createMock(IDBConnection::class),
			$this->logger(),
			$this->l10n(),
			$this->runStepBus(),
			$this->accessRequests(),
			$this->agentAccess(),
			($withLiteracy === true) ? $this->literacy() : null,
			($withAssistants === true) ? $this->assistants() : $this->deadAssistants()
		);
		$controller->requestBody = $body;
		$controller->ticks = [16.0, 2.0, 20.0, 1.0, 30.0, 0.5, 15.0, 3.0];
		$controller->events = &$this->events;

		$escaped = null;
		try {
			$controller->stream();
			$escaped = 'returned';
		} catch (ChatStreamGoldenStop $stop) {
			$escaped = 'stopped:' . $stop->getMessage();
		} catch (Throwable $e) {
			$escaped = get_class($e);
		}

		$record = [
			'input' => ['user' => $user, 'body' => $body, 'outcome' => $outcome, 'assistants' => $withAssistants, 'literacy' => $withLiteracy],
			'events' => $this->events,
			'escaped' => $escaped,
		];

		return json_decode(json_encode($record, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}//end runCase()

	/**
	 * A request body: empty, broken, or JSON with any mix of fields and a forged identity.
	 *
	 * @return string
	 */
	private function body(): string {
		$kind = mt_rand(0, 23);
		if ($kind === 0) {
			return '';
		}

		if ($kind === 1) {
			return '{not json';
		}

		$body = [];
		$message = $this->pick(self::MESSAGES);
		if ($message !== null) {
			$body['message'] = $message;
		}

		foreach (['agentUuid' => self::AGENTS, 'conversationUuid' => self::CONVERSATIONS] as $field => $values) {
			$value = $this->pick($values);
			if ($value !== null) {
				$body[$field] = $value;
			}
		}

		$context = $this->pick(['absent', null, 'text', ['appId' => 'dossiq', 'page' => 'p1'], ['appId' => '  pipelinq '], []]);
		if ($context !== 'absent') {
			$body['context'] = $context;
		}

		$attachments = $this->pick(['absent', 'one.pdf', [['fileId' => 7]], ['a' => ['path' => '/x.txt'], 'b' => ['fileId' => 9]]]);
		if ($attachments !== 'absent') {
			$body['attachments'] = $attachments;
		}

		if (mt_rand(0, 1) === 1) {
			$body['userId'] = 'mallory';
		}

		return (string)json_encode($body);
	}//end body()

	/**
	 * A request whose parameters carry a forged identity; every read is recorded.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				$this->events[] = 'request.getParam:' . $key;
				return ['userId' => 'mallory', 'agentUuid' => 'agent-private', 'conversationUuid' => 'conv-bob'][$key] ?? $default;
			}
		);
		$request->method('getParams')->willReturnCallback(
			function (): array {
				$this->events[] = 'request.getParams';
				return ['userId' => 'mallory', 'agentUuid' => 'agent-private', 'conversationUuid' => 'conv-bob'];
			}
		);

		return $request;
	}//end request()

	/**
	 * The session, signed in as the given user or anonymous.
	 *
	 * @param string|null $uid The user, or null.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($uid): ?IUser {
				$this->events[] = 'session.getUser';
				if ($uid === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);

		return $session;
	}//end session()

	/**
	 * An ObjectService serving two conversations and two agents; reads and saves recorded by name.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$objects = [
			'conv-alice' => ['userId' => 'alice', 'agentId' => 'agent-1'],
			'conv-bob' => ['userId' => 'bob', 'agentId' => 'agent-private'],
			'agent-1' => ['name' => 'Open agent'],
			'agent-private' => ['name' => 'Private agent', 'owner' => 'alice'],
		];
		$service = $this->createMock(ObjectService::class);
		$service->method('find')->willReturnCallback(
			function (...$args) use ($objects): ?ObjectEntity {
				$named = $this->named(method: 'find', args: $args);
				$this->events[] = ['find' => $named];
				$id = (string)($named['id'] ?? '');
				if (isset($objects[$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($objects[$id]);
				return $entity;
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$named = $this->named(method: 'saveObject', args: $args);
				$this->events[] = ['saveObject' => $named];
				$entity = new ObjectEntity();
				$entity->setUuid('conv-new');
				$entity->setObject((array)($named['object'] ?? []));
				return $entity;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * The arguments of an ObjectService call that differ from their default, by name.
	 *
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The positional arguments the mock received.
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $method, array $args): array {
		$named = [];
		$params = (new ReflectionMethod(ObjectService::class, $method))->getParameters();
		foreach ($params as $index => $param) {
			if (array_key_exists($index, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$index]) {
				continue;
			}

			$value = $args[$index];
			if ($value instanceof ObjectEntity) {
				$value = ['entity' => $value->getUuid()];
			}

			$named[$param->getName()] = $value;
		}

		return $named;
	}//end named()

	/**
	 * The access predicate: agent-1 for everyone, agent-private for alice only.
	 *
	 * @return AgentAccessService
	 */
	private function agentAccess(): AgentAccessService {
		$access = $this->createMock(AgentAccessService::class);
		$access->method('canUserAccessAgent')->willReturnCallback(
			function (ObjectEntity $agent, string $userId): bool {
				$this->events[] = 'access:' . $agent->getUuid() . ':' . $userId;
				return $agent->getUuid() === 'agent-1' || ($agent->getUuid() === 'agent-private' && $userId === 'alice');
			}
		);

		return $access;
	}//end agentAccess()

	/**
	 * The app assistant: alice's app gets the private agent, dossiq gets agent-1, else none.
	 *
	 * @return AppAssistantResolver
	 */
	private function assistants(): AppAssistantResolver {
		$assistants = $this->createMock(AppAssistantResolver::class);
		$assistants->method('resolve')->willReturnCallback(
			function (string $userId, string $appId): string {
				$this->events[] = 'assistant:' . $userId . ':' . $appId;
				if ($appId === 'dossiq') {
					return 'agent-1';
				}

				return ($userId === 'alice' && $appId === 'pipelinq') ? 'agent-private' : '';
			}
		);

		return $assistants;
	}//end assistants()

	/**
	 * An app assistant that finds no agent.
	 *
	 * @return AppAssistantResolver
	 */
	private function deadAssistants(): AppAssistantResolver {
		$assistants = $this->createMock(AppAssistantResolver::class);
		$assistants->method('resolve')->willReturnCallback(
			function (string $userId, string $appId): string {
				$this->events[] = 'assistant-none:' . $userId . ':' . $appId;
				return '';
			}
		);

		return $assistants;
	}//end deadAssistants()

	/**
	 * The literacy requirement: bob has not done the course.
	 *
	 * @return LiteracyRequirement
	 */
	private function literacy(): LiteracyRequirement {
		$literacy = $this->createMock(LiteracyRequirement::class);
		$literacy->method('assertMayUseAgents')->willReturnCallback(
			function (string $uid): void {
				$this->events[] = 'literacy:' . $uid;
				if ($uid === 'bob') {
					throw new LiteracyRequiredException();
				}
			}
		);

		return $literacy;
	}//end literacy()

	/**
	 * A run step bus holding zero to two MCP steps.
	 *
	 * @return RunStepBus
	 */
	private function runStepBus(): RunStepBus {
		$steps = [];
		for ($i = mt_rand(0, 2); $i > 0; $i--) {
			$steps[] = $this->pick([
				['toolId' => 't' . $i, 'name' => 'search', 'arguments' => ['q' => 'x'], 'result' => 'r', 'outcome' => 'ok', 'durationMs' => '12'],
				['name' => 'bare'],
				['toolId' => 5, 'result' => 7, 'outcome' => 'refused'],
			]);
		}

		$bus = $this->createMock(RunStepBus::class);
		$bus->method('clear')->willReturnCallback(
			function (string $conversationId): void {
				$this->events[] = 'bus.clear:' . $conversationId;
			}
		);
		$bus->method('drain')->willReturnCallback(
			function (string $conversationId) use ($steps): array {
				$this->events[] = 'bus.drain:' . $conversationId;
				return $steps;
			}
		);

		return $bus;
	}//end runStepBus()

	/**
	 * Pending approvals named after the agent asked about.
	 *
	 * @return ToolAccessRequestService
	 */
	private function accessRequests(): ToolAccessRequestService {
		$requests = $this->createMock(ToolAccessRequestService::class);
		$requests->method('pendingApprovals')->willReturnCallback(
			function (?string $agentId): array {
				$this->events[] = 'pending:' . (string)$agentId;
				return [['agentId' => $agentId, 'toolId' => 'tool-x']];
			}
		);

		return $requests;
	}//end accessRequests()

	/**
	 * The engine: records its arguments by name, streams on the channel, then ends as the outcome says.
	 *
	 * @param string $outcome The scripted outcome.
	 *
	 * @return Engine
	 */
	private function engine(string $outcome): Engine {
		$streams = mt_rand(0, 1);
		$engine = $this->createMock(Engine::class);
		$engine->method('processMessage')->willReturnCallback(
			function (...$args) use ($outcome, $streams): array {
				$named = [];
				foreach ((new ReflectionMethod(Engine::class, 'processMessage'))->getParameters() as $index => $param) {
					if (array_key_exists($index, $args) === false) {
						continue;
					}

					if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$index]) {
						continue;
					}

					$named[$param->getName()] = ($args[$index] instanceof StreamYieldChannel) ? 'channel' : $args[$index];
				}

				$this->events[] = ['engine' => $named];
				if ($streams === 1 && ($args[7] ?? null) instanceof StreamYieldChannel) {
					$args[7]->emitToken('tok');
					$args[7]->emitToolCall(['toolId' => 'call-1', 'name' => 'lookup', 'arguments' => []]);
					$args[7]->emitToolResult(['toolId' => 'call-1', 'result' => 'done']);
				}

				return $this->outcome(outcome: $outcome);
			}
		);

		return $engine;
	}//end engine()

	/**
	 * The engine's answer for an outcome, or the exception it throws.
	 *
	 * @param string $outcome The scripted outcome.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $outcome): array {
		$answer = ['messageId' => 'msg-1', 'attachmentNotices' => ['n1', 2], 'attachments' => ['k' => ['fileId' => 3]]];
		return match ($outcome) {
			'pass' => $answer + ['message' => 'The answer.'],
			'redacted' => ['messageId' => 'msg-2', 'message' => 'Mail [REDACTED] now.'],
			'output-blocked' => ['messageId' => 'msg-3', 'message' => 'The response was withheld by the guardrail policy.'],
			'response-key' => ['response' => 'From response', 'message' => 'ignored', 'attachmentNotices' => 'one'],
			'empty' => [],
			'input-blocked' => throw new GuardrailBlockedException(reason: 'pii'),
			'tool-grants' => throw new ToolGrantResolutionException(['openregister.schemas', 'x.y']),
			'attachment' => throw new AttachmentRefusedException(message: 'This file is not available to you', code: 400),
			'attachment-wrapped' => throw new RuntimeException('outer', 0, new AttachmentRefusedException(message: 'Redact first', code: 422)),
			'pinned' => throw new PinnedCredentialRefusedException(provider: 'claude'),
			'switched-off' => throw new AgentSwitchedOffException(),
			'literacy' => throw new LiteracyRequiredException(),
			'data-use' => throw new DataUseViolationException(organisation: 'org', provider: 'p', dataUse: 'train'),
			'internal' => throw new RuntimeException('db password=secret'),
			default => throw new Exception('outer', 0, new RuntimeException('inner secret')),
		};
	}//end outcome()

	/**
	 * A logger recording level, message and context; file and line dropped, exceptions by class.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$events = &$this->events;
		return new class($events) extends AbstractLogger {
			/**
			 * The shared event list.
			 *
			 * @var array<int, mixed>
			 */
			private array $events;

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $events The shared event list.
			 */
			public function __construct(array &$events) {
				$this->events = &$events;
			}

			/**
			 * Record one line.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				if (($context['exception'] ?? null) instanceof Throwable) {
					$context['exception'] = get_class($context['exception']);
				}

				$this->events[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};
	}//end logger()

	/**
	 * A translator that interpolates like an untranslated locale.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return $l10n;
	}//end l10n()

	/**
	 * One value from the list.
	 *
	 * @param array<int, mixed> $values The values.
	 *
	 * @return mixed
	 */
	private function pick(array $values): mixed {
		return $values[mt_rand(0, count($values) - 1)];
	}//end pick()
}//end class
