<?php

/**
 * ProviderFactory's `cli` turn and warm-up, end to end against doubles: what the
 * runner is sent, which run token is minted and for how long, and when it is consumed.
 *
 * AppAPI is absent here, so its `PublicFunctions` class is declared below as an
 * empty stand-in (only when nothing else declared it): the factory checks that it
 * exists and resolves it through the container, which is a double.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
 */

declare(strict_types=1);

namespace OCA\AppAPI {

	if (class_exists(PublicFunctions::class) === false) {
		/**
		 * Stand-in so the factory sees AppAPI's public interface.
		 */
		class PublicFunctions {
		}
	}
}

namespace OCA\Hermiq\Tests\Unit\Service\Llm {

	use LLPhant\Chat\Message as LLPhantMessage;
	use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
	use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
	use OCA\Hermiq\Service\Llm\ProviderFactory;
	use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
	use OCA\Hermiq\Service\Llm\RunTokenService;
	use OCA\Hermiq\Tests\Unit\Support\InMemoryRunTokenStore;
	use OCP\App\IAppManager;
	use OCP\AppFramework\Utility\ITimeFactory;
	use OCP\Http\Client\IResponse;
	use OCP\IURLGenerator;
	use OCP\IUser;
	use OCP\IUserSession;
	use OCP\Security\ISecureRandom;
	use OCP\TaskProcessing\IManager;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\NullLogger;

	/**
	 * The runner request, the run token and its lifetime, for turns and warm-ups.
	 */
	final class ProviderFactoryCliTurnTest extends TestCase {

		/** @var array<int, array<int, mixed>> */
		public array $requests = [];

		/** @var mixed What the runner answers. */
		public mixed $answer = null;

		private InMemoryRunTokenStore $store;

		private RunTokenService $tokens;

		/**
		 * The factory over doubles of AppAPI, the broker, the scope resolver and the URL generator.
		 *
		 * @return ProviderFactory
		 */
		private function factory(): ProviderFactory {
			$this->store = new InMemoryRunTokenStore();
			$random = $this->createMock(ISecureRandom::class);
			$counter = 0;
			$random->method('generate')->willReturnCallback(
				static function () use (&$counter): string {
					$counter++;
					return str_pad('token' . $counter, 43, 'x');
				}
			);
			$this->tokens = new RunTokenService($this->store, $random, $this->createMock(ITimeFactory::class));

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			$apps = $this->createMock(IAppManager::class);
			$apps->method('isInstalled')->willReturn(true);
			$scopes = $this->createMock(CredentialScopeResolver::class);
			$scopes->method('scopeOfCredential')->willReturn('personal');
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/hermiq/mcp/run');

			return new ProviderFactory(
				settingsHandler: $this->createMock(LlmSettingsHandler::class),
				taskManager: $this->createMock(IManager::class),
				userSession: $session,
				logger: new NullLogger(),
				appManager: $apps,
				credentialResolver: $scopes,
				runTokenService: $this->tokens,
				urlGenerator: $urls,
				container: $this->container()
			);
		}//end factory()

		/**
		 * A container serving AppAPI's public functions and the credential broker.
		 *
		 * @return ContainerInterface
		 */
		private function container(): ContainerInterface {
			$test = $this;
			$appApi = new class ($test) {
				/**
				 * Keep the test.
				 *
				 * @param ProviderFactoryCliTurnTest $test The test.
				 */
				public function __construct(private ProviderFactoryCliTurnTest $test) {
				}

				/**
				 * The runner ExApp, installed and enabled.
				 *
				 * @param string $appId The ExApp id.
				 *
				 * @return array<string, mixed>
				 */
				public function getExApp(string $appId): array {
					return ['appid' => $appId, 'enabled' => true];
				}

				/**
				 * Record a request and answer it.
				 *
				 * @param mixed ...$args The request.
				 *
				 * @return mixed
				 */
				public function exAppRequest(mixed ...$args): mixed {
					$this->test->requests[] = $args;
					return $this->test->answer;
				}
			};
			$broker = new class {
				/**
				 * The subscription token.
				 *
				 * @param string      $credentialId The credential.
				 * @param string      $appId        The app.
				 * @param string|null $uid          The user.
				 *
				 * @return string
				 */
				public function resolveInjectable(string $credentialId, string $appId, ?string $uid): string {
					return 'sub-token-for-' . $credentialId . '-' . $appId . '-' . $uid;
				}
			};
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				static fn (string $id): object => (str_contains($id, 'AppAPI') === true ? $appApi : $broker)
			);
			return $container;
		}//end container()

		/**
		 * A runner response.
		 *
		 * @param int    $status The status.
		 * @param string $text   The answer text.
		 *
		 * @return IResponse
		 */
		private function response(int $status, string $text = 'Done.'): IResponse {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn((string)json_encode(['text' => $text, 'toolCalls' => []]));
			return $response;
		}//end response()

		/**
		 * Run one `cli` turn through the private entry the chat path uses.
		 *
		 * @param ProviderFactory $factory        The factory.
		 * @param array<int, mixed> $functions    The offered functions.
		 * @param string          $conversationId The conversation, or ''.
		 *
		 * @return string The answer.
		 */
		private function turn(ProviderFactory $factory, array $functions, string $conversationId): string {
			$method = new \ReflectionMethod(ProviderFactory::class, 'callAnthropicCli');
			$method->setAccessible(true);
			return $method->invokeArgs($factory, ['cred-1', 'claude-sonnet', [LLPhantMessage::user('Hello')], $functions, 'agent-1', $conversationId]);
		}//end turn()

		/**
		 * A governed, pooled turn sends the MCP config, run token and pool key; its
		 * token lives for the pooled process and is not consumed after the turn.
		 *
		 * @return void
		 */
		public function testAGovernedPooledTurnKeepsItsTokenForTheProcess(): void {
			$factory = $this->factory();
			$this->answer = $this->response(status: 200, text: 'Answered.');

			self::assertSame('Answered.', $this->turn(factory: $factory, functions: [['name' => 'x_list']], conversationId: 'conv-1'));

			[$appId, $route, $uid, $verb, $params, $options] = $this->requests[0];
			self::assertSame(['hermiq-llm-runner', '/run', 'alice', 'POST'], [$appId, $route, $uid, $verb]);
			self::assertSame(['provider', 'model', 'messages', 'credentialEnv', 'mcpConfig', 'runToken', 'poolKey', 'poolLifetimeSeconds'], array_keys($params));
			self::assertSame('claude-sonnet', $params['model']);
			self::assertSame(['CLAUDE_CODE_OAUTH_TOKEN' => 'sub-token-for-cred-1-hermiq-alice'], $params['credentialEnv']);
			self::assertSame(hash('sha256', 'conv-1|agent-1|claude-sonnet'), $params['poolKey']);
			self::assertSame(600, $params['poolLifetimeSeconds']);
			self::assertSame(
				['type' => 'http', 'url' => 'https://cloud.example/apps/hermiq/mcp/run', 'headers' => ['Authorization' => 'Bearer ' . $params['runToken'], 'OCS-APIRequest' => 'true']],
				$params['mcpConfig']['mcpServers']['hermiq']
			);
			self::assertArrayHasKey('timeout', $options);
			self::assertSame(630, $this->store->rows[hash('sha256', $params['runToken'])]['expiresAt']);
			self::assertNotNull($this->tokens->verify(token: $params['runToken']), 'A pooled token outlives the turn.');
		}//end testAGovernedPooledTurnKeepsItsTokenForTheProcess()

		/**
		 * A text-only turn outside a conversation carries only an egress token, which dies with the turn.
		 *
		 * @return void
		 */
		public function testAColdTextOnlyTurnConsumesItsEgressToken(): void {
			$factory = $this->factory();
			$this->answer = $this->response(status: 200);

			$this->turn(factory: $factory, functions: [], conversationId: '');

			$params = $this->requests[0][4];
			self::assertSame(['provider', 'model', 'messages', 'credentialEnv', 'runToken'], array_keys($params));
			self::assertNull($this->tokens->verify(token: $params['runToken']), 'A cold turn consumes its token.');
			self::assertTrue($this->tokens->isKnown(token: $params['runToken']));
		}//end testAColdTextOnlyTurnConsumesItsEgressToken()

		/**
		 * A runner that is not reached, or that fails, is a 503 naming which.
		 *
		 * @return void
		 */
		public function testAnUnreachedOrFailingRunnerIsRefused(): void {
			foreach ([[['error' => 'down'], 'could not reach the "hermiq-llm-runner" ExApp'], [$this->response(status: 502), 'the runner returned an error']] as [$answer, $message]) {
				$factory = $this->factory();
				$this->answer = $answer;
				try {
					$this->turn(factory: $factory, functions: [], conversationId: '');
					self::fail('A failed dispatch was accepted.');
				} catch (ProviderUnavailableException $e) {
					self::assertSame(503, $e->getCode());
					self::assertStringContainsString($message, $e->getMessage());
				}
			}
		}//end testAnUnreachedOrFailingRunnerIsRefused()

		/**
		 * A governed warm-up starts the process the first turn will use: no messages,
		 * the same MCP config, a pooled token and the warm-only flag.
		 *
		 * @return void
		 */
		public function testAGovernedWarmUpStartsTheTurnsProcess(): void {
			$factory = $this->factory();

			self::assertTrue($factory->warmAnthropicCli(credentialId: 'cred-1', model: 'claude-sonnet', agentId: 'agent-1', conversationId: 'conv-1'));

			[$appId, $route, $uid, $verb, $params, $options] = $this->requests[0];
			self::assertSame(['hermiq-llm-runner', '/run', 'alice', 'POST'], [$appId, $route, $uid, $verb]);
			self::assertSame(['provider', 'model', 'messages', 'credentialEnv', 'mcpConfig', 'runToken', 'poolKey', 'poolLifetimeSeconds', 'warmOnly'], array_keys($params));
			self::assertSame([[], hash('sha256', 'conv-1|agent-1|claude-sonnet'), 600, true], [$params['messages'], $params['poolKey'], $params['poolLifetimeSeconds'], $params['warmOnly']]);
			self::assertSame('Bearer ' . $params['runToken'], $params['mcpConfig']['mcpServers']['hermiq']['headers']['Authorization']);
			self::assertSame(630, $this->store->rows[hash('sha256', $params['runToken'])]['expiresAt']);
			self::assertSame(['timeout' => 10], $options);
		}//end testAGovernedWarmUpStartsTheTurnsProcess()

		/**
		 * An ungoverned warm-up carries no MCP config and an egress token.
		 *
		 * @return void
		 */
		public function testAnUngovernedWarmUpCarriesOnlyAnEgressToken(): void {
			$factory = $this->factory();

			self::assertTrue($factory->warmAnthropicCli(credentialId: 'cred-1', model: 'claude-sonnet', agentId: 'agent-1', conversationId: 'conv-1', governed: false));

			$params = $this->requests[0][4];
			self::assertNull($params['mcpConfig']);
			self::assertNotSame('', $params['runToken']);
			self::assertNotSame(630, $this->store->rows[hash('sha256', $params['runToken'])]['expiresAt']);
			self::assertCount(1, $this->store->rows, 'Exactly one token per warm-up.');
		}//end testAnUngovernedWarmUpCarriesOnlyAnEgressToken()
	}//end class
}
