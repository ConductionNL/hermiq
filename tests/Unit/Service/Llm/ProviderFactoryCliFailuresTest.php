<?php

/**
 * ProviderFactory's `cli` turn when a run token cannot be minted or the credential
 * broker refuses, and the Nextcloud Assistant path when its task fails.
 *
 * AppAPI is absent here, so its `PublicFunctions` class is declared below as an
 * empty stand-in (only when nothing else declared it), as in ProviderFactoryCliTurnTest.
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
	use OCP\App\IAppManager;
	use OCP\Http\Client\IResponse;
	use OCP\IURLGenerator;
	use OCP\IUser;
	use OCP\IUserSession;
	use OCP\TaskProcessing\IManager;
	use OCP\TaskProcessing\Task;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;
	use RuntimeException;

	/**
	 * What a `cli` turn does when it cannot be governed or its credential cannot be resolved.
	 *
	 * @covers \OCA\Hermiq\Service\Llm\ProviderFactory
	 */
	final class ProviderFactoryCliFailuresTest extends TestCase {

		/** @var array<int, array<int, mixed>> The runner requests received. */
		public array $requests = [];

		/** @var bool Whether the broker refuses to resolve the credential. */
		public bool $brokerRefuses = false;

		/** @var LoggerInterface&MockObject */
		private LoggerInterface $logger;

		/**
		 * Set up.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			$this->logger = $this->createMock(LoggerInterface::class);
		}//end setUp()

		/**
		 * The factory over doubles of AppAPI, the broker, the scope resolver and the URL generator.
		 *
		 * @param RunTokenService|null $tokens The run-token service.
		 *
		 * @return ProviderFactory
		 */
		private function factory(?RunTokenService $tokens): ProviderFactory {
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
				logger: $this->logger,
				appManager: $apps,
				credentialResolver: $scopes,
				runTokenService: $tokens,
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
				 * @param ProviderFactoryCliFailuresTest $test The test.
				 */
				public function __construct(private ProviderFactoryCliFailuresTest $test) {
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
				 * Record a request and answer with a successful runner response.
				 *
				 * @param mixed ...$args The request.
				 *
				 * @return mixed
				 */
				public function exAppRequest(mixed ...$args): mixed {
					$this->test->requests[] = $args;
					return $this->test->okResponse();
				}
			};
			$broker = new class ($test) {
				/**
				 * Keep the test.
				 *
				 * @param ProviderFactoryCliFailuresTest $test The test.
				 */
				public function __construct(private ProviderFactoryCliFailuresTest $test) {
				}

				/**
				 * The subscription token, unless the broker refuses.
				 *
				 * @param string      $credentialId The credential.
				 * @param string      $appId        The app.
				 * @param string|null $uid          The user.
				 *
				 * @return string
				 */
				public function resolveInjectable(string $credentialId, string $appId, ?string $uid): string {
					if ($this->test->brokerRefuses === true) {
						throw new RuntimeException('not your credential');
					}

					return 'sub-token';
				}
			};
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				static fn (string $id): object => (str_contains($id, 'AppAPI') === true ? $appApi : $broker)
			);
			return $container;
		}//end container()

		/**
		 * A successful runner response.
		 *
		 * @return IResponse
		 */
		public function okResponse(): IResponse {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn(200);
			$response->method('getBody')->willReturn((string)json_encode(['text' => 'Done.', 'toolCalls' => []]));
			return $response;
		}//end okResponse()

		/**
		 * Run one `cli` turn through the private entry the chat path uses.
		 *
		 * @param ProviderFactory   $factory   The factory.
		 * @param array<int, mixed> $functions The offered functions.
		 *
		 * @return string The answer.
		 */
		private function turn(ProviderFactory $factory, array $functions): string {
			$method = new \ReflectionMethod(ProviderFactory::class, 'callAnthropicCli');
			return $method->invokeArgs($factory, ['cred-1', 'claude-sonnet', [LLPhantMessage::user('Hello')], $functions, 'agent-1', '']);
		}//end turn()

		/**
		 * A run-token service whose minting fails.
		 *
		 * @return RunTokenService
		 */
		private function failingTokens(): RunTokenService {
			$tokens = $this->createMock(RunTokenService::class);
			$tokens->method('mint')->willThrowException(new RuntimeException('store locked'));
			return $tokens;
		}//end failingTokens()

		/**
		 * A tool-requiring turn whose token cannot be minted is refused, and nothing is sent.
		 *
		 * @return void
		 */
		public function testAGovernedTurnWithoutATokenIsRefused(): void {
			$this->logger->expects(self::once())->method('error')
				->with('[ProviderFactory] Anthropic cli run token could not be minted', ['reason' => 'store locked']);

			try {
				$this->turn(factory: $this->factory(tokens: $this->failingTokens()), functions: [['name' => 'x_list']]);
				self::fail('An ungovernable turn was run.');
			} catch (ProviderUnavailableException $e) {
				self::assertSame(503, $e->getCode());
				self::assertStringContainsString('the per-run token could not be minted', $e->getMessage());
			}

			self::assertSame([], $this->requests);
		}//end testAGovernedTurnWithoutATokenIsRefused()

		/**
		 * A text-only turn whose egress token cannot be minted still runs, without a run token.
		 *
		 * @return void
		 */
		public function testATextOnlyTurnRunsWithoutAnEgressToken(): void {
			$this->logger->expects(self::once())->method('warning')
				->with('[ProviderFactory] Anthropic cli egress run token could not be minted', ['reason' => 'store locked']);

			self::assertSame('Done.', $this->turn(factory: $this->factory(tokens: $this->failingTokens()), functions: []));
			self::assertSame(['provider', 'model', 'messages', 'credentialEnv'], array_keys($this->requests[0][4]));
		}//end testATextOnlyTurnRunsWithoutAnEgressToken()

		/**
		 * Without a run-token service a text-only turn runs without a run token.
		 *
		 * @return void
		 */
		public function testATextOnlyTurnWithoutATokenServiceRuns(): void {
			self::assertSame('Done.', $this->turn(factory: $this->factory(tokens: null), functions: []));
			self::assertSame(['provider', 'model', 'messages', 'credentialEnv'], array_keys($this->requests[0][4]));
		}//end testATextOnlyTurnWithoutATokenServiceRuns()

		/**
		 * A credential the broker refuses to resolve is a 503, logged with the credential.
		 *
		 * @return void
		 */
		public function testARefusedCredentialIsRefused(): void {
			$this->brokerRefuses = true;
			$this->logger->expects(self::once())->method('warning')->with(
				'[ProviderFactory] Anthropic cli credential could not be resolved through the broker',
				['credentialId' => 'cred-1', 'reason' => 'not your credential']
			);

			try {
				$this->turn(factory: $this->factory(tokens: null), functions: []);
				self::fail('A refused credential was used.');
			} catch (ProviderUnavailableException $e) {
				self::assertSame(503, $e->getCode());
				self::assertStringContainsString('the credential broker refused to resolve it', $e->getMessage());
			}
		}//end testARefusedCredentialIsRefused()

		/**
		 * A failing Nextcloud Assistant task is logged and surfaced as unavailable with its cause.
		 *
		 * @return void
		 */
		public function testAFailingAssistantTaskIsUnavailable(): void {
			$manager = $this->createMock(IManager::class);
			$manager->method('hasProviders')->willReturn(true);
			$cause = new RuntimeException('provider crashed');
			$manager->method('runTask')->willReturnCallback(
				static function (Task $task) use ($cause): Task {
					throw $cause;
				}
			);
			$this->logger->expects(self::once())->method('warning')->with(
				'[ProviderFactory] Nextcloud Assistant task failed',
				self::callback(static fn (array $context): bool => $context['error'] === 'provider crashed')
			);
			$factory = new ProviderFactory($this->createMock(LlmSettingsHandler::class), $manager, $this->createMock(IUserSession::class), $this->logger);

			try {
				$factory->generateViaNextcloud(prompt: 'Summarise this.', userId: 'alice');
				self::fail('A failed task was answered.');
			} catch (ProviderUnavailableException $e) {
				self::assertSame('Nextcloud Assistant task failed: provider crashed', $e->getMessage());
				self::assertSame($cause, $e->getPrevious());
			}
		}//end testAFailingAssistantTaskIsUnavailable()
	}//end class
}
