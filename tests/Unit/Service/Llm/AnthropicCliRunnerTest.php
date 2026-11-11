<?php

/**
 * Hermiq Anthropic cli runner: the runner checks, the subscription credential and the runner's answer.
 *
 * Golden for the allowed AND refused outcomes of a `cli` turn before and after the
 * dispatch: the AppAPI and runner ExApp availability checks (each refusal names what is
 * missing), the personal-scope rule and broker resolution of the subscription
 * credential, and the runner's answer (transport failure, error status, unreadable
 * body, no text, usage). A refused turn never reaches the runner.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-turn-is-dispatched-over-appapi-with-an-explicit-timeout-and-every-failure-is-surfaced
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
	use OCP\App\IAppManager;
	use OCP\Http\Client\IResponse;
	use OCP\IUser;
	use OCP\IUserSession;
	use OCP\TaskProcessing\IManager;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;
	use RuntimeException;

	/**
	 * The runner checks, the credential and the runner's answer of a `cli` turn.
	 */
	final class AnthropicCliRunnerTest extends TestCase {

		/** @var array<int, array<int, mixed>> */
		public array $requests = [];

		/** @var mixed What the runner answers. */
		public mixed $answer = null;

		/** @var array<string, mixed>|null What AppAPI reports for the runner ExApp. */
		public ?array $exApp = ['appid' => 'hermiq-llm-runner', 'enabled' => true];

		/** @var string|null|false The broker's token; false makes the broker throw. */
		public string|null|false $brokerToken = 'sub-token';

		/** @var array<int, array<int, mixed>> The broker calls. */
		public array $brokerCalls = [];

		/**
		 * The factory over doubles of AppAPI, the broker and the scope resolver.
		 *
		 * @param array<string, mixed> $with Overrides: appManager, scope (string|null|false for no
		 *                                   resolver), appApiInstalled, logger.
		 *
		 * @return ProviderFactory
		 */
		private function factory(array $with = []): ProviderFactory {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);

			$apps = $this->createMock(IAppManager::class);
			$apps->method('isInstalled')->with('app_api')->willReturn($with['appApiInstalled'] ?? true);
			if (array_key_exists('appManager', $with) === true) {
				$apps = $with['appManager'];
			}

			$scopes = null;
			$scope = array_key_exists('scope', $with) ? $with['scope'] : 'personal';
			if ($scope !== false) {
				$scopes = $this->createMock(CredentialScopeResolver::class);
				$scopes->method('scopeOfCredential')->with('cred-1')->willReturn($scope);
			}

			return new ProviderFactory(
				settingsHandler: $this->createMock(LlmSettingsHandler::class),
				taskManager: $this->createMock(IManager::class),
				userSession: $session,
				logger: ($with['logger'] ?? $this->createMock(LoggerInterface::class)),
				appManager: $apps,
				credentialResolver: $scopes,
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
				 * @param AnthropicCliRunnerTest $test The test.
				 */
				public function __construct(private AnthropicCliRunnerTest $test) {
				}

				/**
				 * The runner ExApp as the test sets it.
				 *
				 * @param string $appId The ExApp id.
				 *
				 * @return array<string, mixed>|null
				 */
				public function getExApp(string $appId): ?array {
					return $this->test->exApp;
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
			$broker = new class ($test) {
				/**
				 * Keep the test.
				 *
				 * @param AnthropicCliRunnerTest $test The test.
				 */
				public function __construct(private AnthropicCliRunnerTest $test) {
				}

				/**
				 * The subscription token as the test sets it.
				 *
				 * @param string      $credentialId The credential.
				 * @param string      $appId        The app.
				 * @param string|null $uid          The user.
				 *
				 * @return string|null
				 */
				public function resolveInjectable(string $credentialId, string $appId, ?string $uid): ?string {
					$this->test->brokerCalls[] = [$credentialId, $appId, $uid];
					if ($this->test->brokerToken === false) {
						throw new RuntimeException('vault sealed');
					}

					return $this->test->brokerToken;
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
		 * @param string $body   The body.
		 *
		 * @return IResponse
		 */
		private function response(int $status, string $body): IResponse {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn($body);
			return $response;
		}//end response()

		/**
		 * Run one text-only `cli` turn.
		 *
		 * @param ProviderFactory $factory The factory.
		 *
		 * @return string The answer.
		 */
		private function turn(ProviderFactory $factory): string {
			return $factory->callAnthropicChat(
				credentialId: 'cred-1',
				model: 'claude-sonnet',
				baseUrl: 'https://api.anthropic.com/v1',
				messageHistory: [LLPhantMessage::system('Be brief.'), LLPhantMessage::user(''), LLPhantMessage::user('Hello')],
				executionMode: 'cli'
			);
		}//end turn()

		/**
		 * The refusal a turn raises; the runner is never reached.
		 *
		 * @param ProviderFactory $factory The factory.
		 *
		 * @return string The refusal message (code asserted 503).
		 */
		private function refusal(ProviderFactory $factory): string {
			try {
				$this->turn(factory: $factory);
			} catch (ProviderUnavailableException $e) {
				self::assertSame(503, $e->getCode());
				return $e->getMessage();
			}

			self::fail('The turn must be refused.');
		}//end refusal()

		/**
		 * An available runner and a personal credential: the turn is dispatched and answered.
		 *
		 * @return void
		 */
		public function testAnAllowedTurnIsDispatchedAndAnswered(): void {
			$this->answer = $this->response(200, (string)json_encode(['text' => 'Hi.', 'usage' => ['input_tokens' => 7, 'output_tokens' => 2]]));
			$factory = $this->factory();

			$factory->assertCliRunnerAvailable();
			self::assertSame('Hi.', $this->turn(factory: $factory));

			[$appId, $route, $uid, $verb, $params, $options] = $this->requests[0];
			self::assertSame(['hermiq-llm-runner', '/run', 'alice', 'POST'], [$appId, $route, $uid, $verb]);
			self::assertSame([['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hello']], $params['messages']);
			self::assertSame(['CLAUDE_CODE_OAUTH_TOKEN' => 'sub-token'], $params['credentialEnv']);
			self::assertSame(['timeout' => 330], $options);
			self::assertSame([['cred-1', 'hermiq', 'alice']], $this->brokerCalls);
			self::assertSame(['promptTokens' => 7, 'completionTokens' => 2], $factory->lastCallUsage());
		}//end testAnAllowedTurnIsDispatchedAndAnswered()

		/**
		 * Each missing component is refused by name, before the credential is touched.
		 *
		 * @return void
		 */
		public function testAMissingComponentIsRefusedByName(): void {
			$cases = [
				[['appManager' => null], 'the Nextcloud app manager could not be resolved', null],
				[['appApiInstalled' => false], 'the AppAPI app ("app_api") is not enabled', null],
				[[], 'the "hermiq-llm-runner" ExApp is not installed', null],
				[[], 'the "hermiq-llm-runner" ExApp is installed but disabled', ['appid' => 'hermiq-llm-runner']],
			];
			foreach ($cases as $index => [$with, $message, $exApp]) {
				$this->exApp = $exApp;
				if ($index < 2) {
					$this->exApp = ['enabled' => true];
				}

				$factory = $this->factory(with: $with);
				self::assertStringContainsString($message, $this->refusal(factory: $factory));

				try {
					$factory->assertCliRunnerAvailable();
					self::fail('The availability check must refuse.');
				} catch (ProviderUnavailableException $e) {
					self::assertSame(503, $e->getCode());
					self::assertStringContainsString($message, $e->getMessage());
				}
			}

			self::assertSame([], $this->brokerCalls);
			self::assertSame([], $this->requests);
		}//end testAMissingComponentIsRefusedByName()

		/**
		 * A credential that is not personal-scope, or whose scope cannot be checked, is refused
		 * before the broker is asked.
		 *
		 * @return void
		 */
		public function testOnlyAPersonalCredentialIsUsed(): void {
			$cases = [
				[false, 'the credential scope could not be verified'],
				[null, 'it could not be found in the credential broker'],
				['organisation', 'it is organisation-scope'],
			];
			foreach ($cases as [$scope, $message]) {
				self::assertStringContainsString($message, $this->refusal(factory: $this->factory(with: ['scope' => $scope])));
			}

			self::assertSame([], $this->brokerCalls);
			self::assertSame([], $this->requests);
		}//end testOnlyAPersonalCredentialIsUsed()

		/**
		 * A broker that refuses, or yields no injectable token, refuses the turn; the refusal
		 * is logged without the token.
		 *
		 * @return void
		 */
		public function testABrokerRefusalOrNoTokenIsRefused(): void {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects(self::once())->method('warning')->with(
				'[ProviderFactory] Anthropic cli credential could not be resolved through the broker',
				['credentialId' => 'cred-1', 'reason' => 'vault sealed']
			);
			$this->brokerToken = false;
			self::assertStringContainsString('the credential broker refused to resolve it', $this->refusal(factory: $this->factory(with: ['logger' => $logger])));

			foreach ([null, ''] as $token) {
				$this->brokerToken = $token;
				self::assertStringContainsString('it is not an inject-only credential', $this->refusal(factory: $this->factory()));
			}

			self::assertSame([], $this->requests);
		}//end testABrokerRefusalOrNoTokenIsRefused()

		/**
		 * The runner's failures are refused by kind, and logged.
		 *
		 * @return void
		 */
		public function testTheRunnersFailuresAreRefused(): void {
			$cases = [
				[['error' => 'down'], 'could not reach the "hermiq-llm-runner" ExApp', '[ProviderFactory] Anthropic cli dispatch failed at the AppAPI transport', ['reason' => 'down']],
				[$this->response(404, '{}'), 'the runner returned an error while executing the turn', '[ProviderFactory] Anthropic cli runner returned a non-success status', ['status' => 404]],
				[$this->response(200, 'not json'), 'the runner returned a response Hermiq could not read', null, null],
				[$this->response(299, '{"text":""}'), 'the runner returned no completion text', null, null],
			];
			foreach ($cases as [$answer, $message, $log, $context]) {
				$logger = $this->createMock(LoggerInterface::class);
				if ($log === null) {
					$logger->expects(self::never())->method('warning');
				} else {
					$logger->expects(self::once())->method('warning')->with($log, $context);
				}

				$this->answer = $answer;
				self::assertStringContainsString($message, $this->refusal(factory: $this->factory(with: ['logger' => $logger])));
			}
		}//end testTheRunnersFailuresAreRefused()

		/**
		 * A warm-up whose runner is missing fails silently: false, debug log, no request.
		 *
		 * @return void
		 */
		public function testAWarmUpWithoutTheRunnerFailsSilently(): void {
			$this->exApp = null;
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects(self::once())->method('debug')->with(
				'[ProviderFactory] CLI pool warm-up did not start',
				self::callback(static fn (array $context): bool => str_contains($context['reason'], 'ExApp is not installed'))
			);

			self::assertFalse(
				$this->factory(with: ['logger' => $logger])->warmAnthropicCli(credentialId: 'cred-1', model: 'claude-sonnet', agentId: 'agent-1', conversationId: 'conv-1', governed: false)
			);
			self::assertSame([], $this->requests);
		}//end testAWarmUpWithoutTheRunnerFailsSilently()

		/**
		 * Without a container neither AppAPI nor the broker can be reached.
		 *
		 * @return void
		 */
		public function testWithoutAContainerTheRunnerIsUnavailable(): void {
			$apps = $this->createMock(IAppManager::class);
			$apps->method('isInstalled')->willReturn(true);
			$factory = new ProviderFactory(
				settingsHandler: $this->createMock(LlmSettingsHandler::class),
				taskManager: $this->createMock(IManager::class),
				userSession: $this->createMock(IUserSession::class),
				logger: $this->createMock(LoggerInterface::class),
				appManager: $apps
			);

			$this->expectException(ProviderUnavailableException::class);
			$this->expectExceptionCode(503);
			$this->expectExceptionMessage('the factory was constructed without a container');

			$factory->assertCliRunnerAvailable();
		}//end testWithoutAContainerTheRunnerIsUnavailable()
	}//end class
}
