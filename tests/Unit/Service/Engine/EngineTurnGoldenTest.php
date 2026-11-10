<?php

/**
 * Golden test for Engine::processMessage().
 *
 * Runs turns over generated sessions (owner, listed participant, stranger, a
 * stranger naming the owner as author), agents (on, switched off, none),
 * attachments, guardrail verdicts (input and output: pass, redact, block) and
 * response outcomes, and compares every collaborator call in order, every log
 * line and the envelope or the exception with a recorded fixture. Set
 * GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode compares nothing.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use Exception;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\Chat\TurnAttachmentResolver;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\PromptVariableResolver;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Tests\Unit\Support\FindCallShape;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Golden comparison of one engine turn.
 *
 * @covers \OCA\Hermiq\Service\Engine\Engine
 * @covers \OCA\Hermiq\Service\Engine\TurnGuardrail
 *
 * @uses \OCA\Hermiq\Service\Agent\AgentAvailability
 * @uses \OCA\Hermiq\Service\Agent\AgentSwitchedOffException
 * @uses \OCA\Hermiq\Service\Talk\ConversationParticipation
 * @uses \OCA\Hermiq\Service\GuardrailBlockedException
 * @uses \OCA\Hermiq\Service\Chat\AttachmentRefusedException
 * @uses \OCA\Hermiq\Service\AiFeature\RedactionRequiredException
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#1-port-the-chat-engine
 */
class EngineTurnGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../../Fixtures/Golden/engine-turn.json';

	private const CASES = 500;

	private const CALLERS = ['alice', 'alice', 'alice', 'alice', 'bob', 'bob', 'mallory', ''];

	private const AUTHORS = [null, null, null, 'alice', 'carol'];

	private const VERDICTS = ['pass', 'pass', 'redact', 'block'];

	/**
	 * The seeded generator every draw of the current case comes from, so mock
	 * creation (which calls mt_rand) cannot shift the generated values.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * The ordered events of the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $events = [];

	/**
	 * Every generated turn runs exactly as recorded.
	 *
	 * @return void
	 */
	public function testTurnMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
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
	}//end testTurnMatchesTheRecordedFixture()

	/**
	 * Runs one seeded turn; returns its inputs, ordered events and outcome through JSON.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(): array {
		$this->events = [];
		$conversationData = ['userId' => 'alice', 'title' => 't'];
		$roster = $this->pick([[], ['bob'], ['bob'], ['bob', '', 7], 'bad']);
		if ($roster !== []) {
			$conversationData['participants'] = $roster;
		}

		$agentId = $this->pick(['agent-on', 'agent-on', 'agent-on', 'agent-off', '', 12, null, 'agent-missing']);
		if ($agentId !== null) {
			$conversationData['agentId'] = $agentId;
		}

		$conversationData = array_merge($conversationData, $this->maybe(['startValues' => ['k' => 'v']]));
		$input = [
			'conversationId' => $this->pick(['conv-1', 'conv-1', 'conv-1', 'conv-1', 'conv-1', 'conv-1', 'conv-1', 'conv-missing']),
			'userId' => $this->pick(self::CALLERS),
			'message' => $this->pick(['hello there', 'my secret is 1234']),
			'authorId' => $this->pick(self::AUTHORS),
			'attachments' => $this->pick([[], [], [], [['fileId' => 1]], [['fileId' => 1]], [['path' => '/secret.txt']]]),
			'skillSetOverride' => $this->pick([null, ['s1']]),
			'dryRun' => ($this->random->getInt(0, 3) === 0),
			'context' => $this->pick([[], ['appId' => 'dossiq']]),
			'withTrace' => ($this->random->getInt(0, 1) === 1),
			'conversation' => $conversationData,
			'inputVerdict' => $this->pick(self::VERDICTS),
			'outputVerdict' => $this->pick(self::VERDICTS),
			'response' => $this->pick(['ok', 'ok', 'ok', 'fails', 'redaction-refused']),
			'optional' => [
				'guardrails' => ($this->random->getInt(0, 4) > 0),
				'jobList' => ($this->random->getInt(0, 2) > 0),
				'userManager' => ($this->random->getInt(0, 2) > 0),
				'promptVariables' => $this->pick(['none', 'ok', 'throws']),
				'attachments' => ($this->random->getInt(0, 3) > 0),
				'images' => ($this->random->getInt(0, 1) === 1),
			],
		];

		$engine = $this->engine(input: $input);
		$trace = null;
		if ($input['withTrace'] === true) {
			$trace = $this->trace();
		}

		$record = ['input' => $input, 'result' => null, 'error' => null];
		try {
			$result = $engine->processMessage(
				conversationId: $input['conversationId'],
				userId: $input['userId'],
				userMessage: $input['message'],
				selectedViews: ['view-1'],
				selectedTools: ['tool-1'],
				ragSettings: ['includeFiles' => false],
				context: $input['context'],
				channel: null,
				trace: $trace,
				dryRun: $input['dryRun'],
				skillSetOverride: $input['skillSetOverride'],
				authorId: $input['authorId'],
				authorDisplayName: ($input['authorId'] === null) ? null : 'Shown ' . $input['authorId'],
				attachments: $input['attachments']
			);
			$result['timings'] = array_map(static fn (): string => '<time>', $result['timings']);
			$record['result'] = $result;
		} catch (Throwable $e) {
			$record['error'] = ['class' => get_class($e), 'message' => $e->getMessage()];
		}

		$record['events'] = $this->events;

		return json_decode(json_encode($record, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}//end runCase()

	/**
	 * The engine wired to recording doubles for this case.
	 *
	 * @param array<string, mixed> $input The case.
	 *
	 * @return Engine
	 */
	private function engine(array $input): Engine {
		$optional = $input['optional'];
		return new Engine(
			$this->objectService(conversationData: $input['conversation']),
			$this->contextHandler(),
			$this->responseHandler(outcome: $input['response']),
			$this->conversationHandler(),
			$this->historyHandler(),
			$this->contextAssembler(),
			$this->logger(),
			($optional['guardrails'] === true) ? $this->guardrails(input: $input['inputVerdict'], output: $input['outputVerdict']) : null,
			($optional['jobList'] === true) ? $this->jobList() : null,
			new ConversationParticipation(),
			($optional['userManager'] === true) ? $this->userManager() : null,
			$this->promptVariables(mode: $optional['promptVariables']),
			($optional['attachments'] === true) ? $this->attachmentResolver() : null,
			($optional['images'] === true) ? $this->images() : null
		);
	}//end engine()

	/**
	 * Arguments that differ from their default, by parameter name.
	 *
	 * @param string            $class  The mocked class.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The positional arguments received.
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $args): array {
		$named = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $index => $param) {
			if (array_key_exists($index, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$index]) {
				continue;
			}

			$named[$param->getName()] = $this->plain(value: $args[$index]);
		}

		return $named;
	}//end named()

	/**
	 * A JSON-safe view of a value: entities by uuid, objects by class.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function plain(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return ['entity' => $value->getUuid()];
		}

		if (is_object($value) === true) {
			return ['object' => ($value instanceof StreamYieldChannel || $value instanceof RunTraceCollector) ? 'collaborator' : get_class($value)];
		}

		return $value;
	}//end plain()

	/**
	 * An ObjectService holding the conversation and two agents.
	 *
	 * @param array<string, mixed> $conversationData The conversation's data.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $conversationData): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('find')->willReturnCallback(
			function (...$args) use ($conversationData): ?ObjectEntity {
				$named = FindCallShape::named(args: $args);
				$this->events[] = ['find' => $named];
				$id = (string)($named['id'] ?? '');
				$objects = ['conv-1' => $conversationData, 'agent-on' => ['name' => 'On'], 'agent-off' => ['name' => 'Off', 'active' => false]];
				if (isset($objects[$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($objects[$id]);
				$entity->setOrganisation($id === 'conv-1' ? 'org-a' : null);
				return $entity;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * A RAG handler answering one source.
	 *
	 * @return ContextRetrievalHandler
	 */
	private function contextHandler(): ContextRetrievalHandler {
		$handler = $this->createMock(ContextRetrievalHandler::class);
		$handler->method('retrieveContext')->willReturnCallback(
			function (...$args): array {
				$this->events[] = ['retrieveContext' => $this->named(class: ContextRetrievalHandler::class, method: 'retrieveContext', args: $args)];
				return ['text' => 'rag text', 'sources' => [['id' => 'src-1']]];
			}
		);

		return $handler;
	}//end contextHandler()

	/**
	 * The response handler: answers, fails, or fails on a redaction refusal.
	 *
	 * @param string $outcome The scripted outcome.
	 *
	 * @return ResponseGenerationHandler
	 */
	private function responseHandler(string $outcome): ResponseGenerationHandler {
		$handler = $this->createMock(ResponseGenerationHandler::class);
		$handler->lastUsage = ['promptTokens' => 3];
		$handler->attachmentNotices = ['notice-1'];
		$handler->method('generateResponse')->willReturnCallback(
			function (...$args) use ($outcome): string {
				$this->events[] = ['generateResponse' => $this->named(class: ResponseGenerationHandler::class, method: 'generateResponse', args: $args)];
				if ($outcome === 'fails') {
					throw new RuntimeException('provider down');
				}

				if ($outcome === 'redaction-refused') {
					throw new Exception('wrapped', 0, new RedactionRequiredException(featureSlug: 'chat', documentReference: 'doc-1', reason: 'unredacted'));
				}

				return 'The model says 1234 to you.';
			}
		);

		return $handler;
	}//end responseHandler()

	/**
	 * The conversation handler; only its summary check is reached.
	 *
	 * @return ConversationManagementHandler
	 */
	private function conversationHandler(): ConversationManagementHandler {
		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->method('checkAndSummarize')->willReturnCallback(
			function (ObjectEntity $conversation): void {
				$this->events[] = 'checkAndSummarize:' . $conversation->getUuid();
			}
		);

		return $handler;
	}//end conversationHandler()

	/**
	 * The message store and history builder.
	 *
	 * @return MessageHistoryHandler
	 */
	private function historyHandler(): MessageHistoryHandler {
		$handler = $this->createMock(MessageHistoryHandler::class);
		$handler->method('storeMessage')->willReturnCallback(
			function (...$args): ObjectEntity {
				$named = $this->named(class: MessageHistoryHandler::class, method: 'storeMessage', args: $args);
				$this->events[] = ['storeMessage' => $named];
				$entity = new ObjectEntity();
				$entity->setUuid('msg-' . (string)($named['role'] ?? ''));
				return $entity;
			}
		);
		$handler->method('buildTurnHistory')->willReturnCallback(
			function (...$args): array {
				$named = $this->named(class: MessageHistoryHandler::class, method: 'buildTurnHistory', args: $args);
				$this->events[] = ['buildTurnHistory' => $named];
				return ['history' => [['role' => 'user', 'content' => 'earlier']], 'turnText' => 'Shown: ' . (string)($named['turnText'] ?? '')];
			}
		);

		return $handler;
	}//end historyHandler()

	/**
	 * The context assembler: a preamble, and a skill bundle of varying shape.
	 *
	 * @return ContextAssembler
	 */
	private function contextAssembler(): ContextAssembler {
		$preamble = $this->pick(['', 'PREAMBLE']);
		$bundle = $this->pick([[], ['text' => 'SKILLS', 'skillsUsed' => ['s1']], ['text' => '', 'skillsUsed' => []], ['text' => 5]]);
		$assembler = $this->createMock(ContextAssembler::class);
		$assembler->method('assembleForAgent')->willReturnCallback(
			function (?ObjectEntity $agent, string $actingUserId) use ($preamble): string {
				$this->events[] = 'assembleForAgent:' . (string)$agent?->getUuid() . ':' . $actingUserId;
				return $preamble;
			}
		);
		$assembler->method('assembleSkillsForRun')->willReturnCallback(
			function (?ObjectEntity $agent, ?array $skillSetOverride = null) use ($bundle): array {
				$this->events[] = ['assembleSkillsForRun' => [(string)$agent?->getUuid(), $skillSetOverride]];
				return $bundle;
			}
		);

		return $assembler;
	}//end contextAssembler()

	/**
	 * The guardrail service with scripted input and output verdicts.
	 *
	 * @param string $input  The input verdict.
	 * @param string $output The output verdict.
	 *
	 * @return GuardrailPolicyService
	 */
	private function guardrails(string $input, string $output): GuardrailPolicyService {
		$service = $this->createMock(GuardrailPolicyService::class);
		$service->method('effectivePolicyFor')->willReturnCallback(
			function (string $organisation): array {
				$this->events[] = 'effectivePolicyFor:' . $organisation;
				return ['organisation' => $organisation];
			}
		);
		$verdict = static function (string $kind, string $text): array {
			return match ($kind) {
				'redact' => ['text' => str_replace('1234', '[REDACTED]', $text), 'blocked' => false, 'reason' => null],
				'block' => ['text' => 'The response was withheld.', 'blocked' => true, 'reason' => 'pii'],
				default => ['text' => $text, 'blocked' => false, 'reason' => null],
			};
		};
		$service->method('filterInput')->willReturnCallback(
			function (array $policy, string $text) use ($input, $verdict): array {
				$this->events[] = ['filterInput' => [$policy, $text]];
				return $verdict($input, $text);
			}
		);
		$service->method('filterOutput')->willReturnCallback(
			function (array $policy, string $text) use ($output, $verdict): array {
				$this->events[] = ['filterOutput' => [$policy, $text]];
				return $verdict($output, $text);
			}
		);

		return $service;
	}//end guardrails()

	/**
	 * A job list recording what is queued.
	 *
	 * @return IJobList
	 */
	private function jobList(): IJobList {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument = null): void {
				$this->events[] = ['jobList.add' => [$job, $argument]];
			}
		);

		return $jobList;
	}//end jobList()

	/**
	 * A user manager that knows alice and bob.
	 *
	 * @return IUserManager
	 */
	private function userManager(): IUserManager {
		$manager = $this->createMock(IUserManager::class);
		$manager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				$this->events[] = 'userManager.get:' . $uid;
				if (in_array($uid, ['alice', 'bob'], true) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn(ucfirst($uid) . ' Display');
				return $user;
			}
		);

		return $manager;
	}//end userManager()

	/**
	 * The placeholder resolver, absent, answering, or failing.
	 *
	 * @param string $mode `none`, `ok` or `throws`.
	 *
	 * @return PromptVariableResolver|null
	 */
	private function promptVariables(string $mode): ?PromptVariableResolver {
		if ($mode === 'none') {
			return null;
		}

		$resolver = $this->createMock(PromptVariableResolver::class);
		$resolver->method('variablesFor')->willReturnCallback(
			function (...$args) use ($mode): array {
				$this->events[] = ['variablesFor' => $this->named(class: PromptVariableResolver::class, method: 'variablesFor', args: $args)];
				if ($mode === 'throws') {
					throw new RuntimeException('directory down');
				}

				return ['user.name' => 'Someone'];
			}
		);

		return $resolver;
	}//end promptVariables()

	/**
	 * The attachment resolver: refuses a path under /secret, names refusals by file.
	 *
	 * @return TurnAttachmentResolver
	 */
	private function attachmentResolver(): TurnAttachmentResolver {
		$resolver = $this->createMock(TurnAttachmentResolver::class);
		$resolver->method('resolve')->willReturnCallback(
			function (array $requested, string $speaker): array {
				$this->events[] = ['attachments.resolve' => [$requested, $speaker]];
				if (str_starts_with((string)($requested[0]['path'] ?? ''), '/secret') === true) {
					throw new AttachmentRefusedException(message: 'This file is not available to you', code: 400);
				}

				return [['fileId' => 1, 'name' => 'a.txt', 'mimeType' => 'text/plain', 'size' => 3, 'origin' => 'files']];
			}
		);
		$resolver->method('redactionRefusal')->willReturnCallback(
			function (RedactionRequiredException $refusal, array $attachments): ?AttachmentRefusedException {
				$this->events[] = ['attachments.redactionRefusal' => count($attachments)];
				return new AttachmentRefusedException(message: 'a.txt must be redacted first', code: 422);
			}
		);

		return $resolver;
	}//end attachmentResolver()

	/**
	 * The image service, handing over one created image.
	 *
	 * @return ImageGenerationService
	 */
	private function images(): ImageGenerationService {
		$images = $this->createMock(ImageGenerationService::class);
		$images->method('takeCreated')->willReturnCallback(
			function (): array {
				$this->events[] = 'images.takeCreated';
				return [['fileId' => 50, 'name' => 'image.png']];
			}
		);

		return $images;
	}//end images()

	/**
	 * A run trace recording its steps in order.
	 *
	 * @return RunTraceCollector
	 */
	private function trace(): RunTraceCollector {
		$steps = [];
		$trace = $this->createMock(RunTraceCollector::class);
		$trace->method('startStep')->willReturnCallback(
			function (string $type, string $name) use (&$steps): int {
				$this->events[] = 'trace.start:' . $type . ':' . $name;
				$steps[] = [$type, $name];
				return count($steps);
			}
		);
		$trace->method('endStep')->willReturnCallback(
			function (int $token, string $outcome, array $extra = []): void {
				$this->events[] = 'trace.end:' . $token . ':' . $outcome;
			}
		);
		$trace->method('toArray')->willReturnCallback(
			static function () use (&$steps): array {
				return $steps;
			}
		);

		return $trace;
	}//end trace()

	/**
	 * A logger recording level, message and context; file and line dropped.
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
				$this->events[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};
	}//end logger()

	/**
	 * The given fields, each kept with even chance.
	 *
	 * @param array<string, mixed> $fields The candidate fields.
	 *
	 * @return array<string, mixed>
	 */
	private function maybe(array $fields): array {
		return array_filter($fields, fn (): bool => $this->random->getInt(0, 1) === 1);
	}//end maybe()

	/**
	 * One value from the list.
	 *
	 * @param array<int, mixed> $values The values.
	 *
	 * @return mixed
	 */
	private function pick(array $values): mixed {
		return $values[$this->random->getInt(0, count($values) - 1)];
	}//end pick()
}//end class
