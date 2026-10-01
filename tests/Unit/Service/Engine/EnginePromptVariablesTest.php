<?php

/**
 * The engine fills an agent's placeholders for the person taking the turn
 * (agents-instruction-variables): it hands the response handler the values for
 * that person, the session's start field answers and the companion's app, and
 * the handler fills them into Agent.prompt only, never into retrieved text.
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use DateTime;
use DateTimeZone;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\PromptVariableResolver;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Tests the placeholder hand-off from the engine to the response handler.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
class EnginePromptVariablesTest extends TestCase {

	/**
	 * A resolver for Fatima el Amrani on 27 September 2026, Amsterdam.
	 *
	 * @return PromptVariableResolver
	 */
	private function resolver(): PromptVariableResolver {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Fatima el Amrani');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, mixed $default = ''): string => ($key === 'timezone') ? 'Europe/Amsterdam' : 'nl'
		);
		$config->method('getSystemValueString')->willReturnArgument(1);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static function (string $when = 'now', ?DateTimeZone $zone = null): DateTime {
				$at = new DateTime('2026-09-27 10:15:00', new DateTimeZone('Europe/Amsterdam'));
				if ($zone !== null) {
					$at->setTimezone($zone);
				}

				return $at;
			}
		);

		return new PromptVariableResolver(userManager: $users, config: $config, time: $time);
	}//end resolver()

	/**
	 * The values the engine handed the response handler for one turn.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function testTheTurnCarriesThePersonsValuesAndTheSessionsAnswers(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				if ($id === 'sess-1') {
					$entity->setObject(['userId' => 'fatima', 'agentId' => 'agent-1', 'startValues' => ['department' => 'Permits']]);
					return $entity;
				}

				$entity->setObject(
					[
						'name' => 'Vergunningen helper',
						'prompt' => 'Address {{user.displayName}}. Department: {{field.department}}.',
						'startFields' => [['key' => 'department', 'label' => 'Department', 'type' => 'select', 'options' => ['Permits', 'Taxes'], 'required' => true]],
					]
				);
				return $entity;
			}
		);

		$captured = null;
		$response = $this->createMock(ResponseGenerationHandler::class);
		$response->method('generateResponse')->willReturnCallback(
			static function (...$args) use (&$captured): string {
				$captured = $args;
				throw new RuntimeException('stop after the model call is prepared');
			}
		);
		$retrieval = $this->createMock(ContextRetrievalHandler::class);
		$retrieval->method('retrieveContext')->willReturn(['text' => '', 'sources' => []]);
		$history = $this->createMock(MessageHistoryHandler::class);
		$history->method('buildMessageHistory')->willReturn([]);

		$engine = new Engine(
			$objectService,
			$retrieval,
			$response,
			$this->createMock(ConversationManagementHandler::class),
			$history,
			$this->createMock(ContextAssembler::class),
			new NullLogger(),
			null,
			null,
			new ConversationParticipation(),
			null,
			$this->resolver()
		);

		try {
			$engine->processMessage(conversationId: 'sess-1', userId: 'fatima', userMessage: 'Kan ik een dakkapel bouwen?', context: ['appId' => 'dossiq']);
		} catch (Throwable) {
			// The sentinel stops the run once the model call is prepared.
		}

		$this->assertNotNull($captured, 'The turn never reached the response handler.');
		// PHPUnit hands the callback the arguments by position, in the order of
		// ResponseGenerationHandler::generateResponse(): promptVariables is the 12th.
		$variables = ($captured[11] ?? []);
		$this->assertSame('Fatima el Amrani', ($variables['user.displayName'] ?? null));
		$this->assertSame('Permits', ($variables['field.department'] ?? null));
		$this->assertSame('dossiq', ($variables['app.id'] ?? null));
		$this->assertSame('2026-09-27', ($variables['today'] ?? null));

	}//end testTheTurnCarriesThePersonsValuesAndTheSessionsAnswers()

	/**
	 * The handler fills the instructions and leaves retrieved text alone, even
	 * when a document contains a placeholder.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function testOnlyTheInstructionsAreFilledIn(): void {
		$captured = null;
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'fireworks']);
		$factory->method('createChatDriver')->willReturn(
			new ChatDriver(provider: 'fireworks', chat: null, model: 'm', credentialId: 'c', baseUrl: 'https://example.invalid')
		);
		$factory->method('callFireworksChat')->willReturnCallback(
			static function (string $credentialId, string $model, string $baseUrl, array $messageHistory) use (&$captured): string {
				$captured = $messageHistory;
				return 'ok';
			}
		);
		$loop = $this->createMock(ToolLoop::class);
		$loop->method('listAgentFunctions')->willReturn([]);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['prompt' => 'Address {{user.displayName}}. Today is {{today}}.']);

		(new ResponseGenerationHandler($factory, $loop, new NullLogger()))->generateResponse(
			userMessage: 'Show me {{user.id}}',
			context: ['text' => 'A memo for {{user.id}}.', 'sources' => []],
			messageHistory: [],
			agent: $agent,
			promptVariables: $this->resolver()->variablesFor(userId: 'fatima', agentData: [])
		);

		$this->assertNotNull($captured);
		$system = (string)$captured[0]->content;
		$this->assertStringStartsWith('Address Fatima el Amrani. Today is 2026-09-27.', $system);
		$this->assertStringContainsString('A memo for {{user.id}}.', $system, 'Retrieved text is never a template.');
		$this->assertSame('Show me {{user.id}}', (string)$captured[count($captured) - 1]->content, 'The person\'s message is never a template.');

	}//end testOnlyTheInstructionsAreFilledIn()
}//end class
