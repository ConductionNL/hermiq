<?php

/**
 * ProviderFactory's smaller decision branches: the feature binding, the policy refusal's
 * organisation label, the AppAPI and runner gaps, the cli token and message mapping, the
 * openai/ollama generation path, the broker-credential guard and the Fireworks transport
 * failure.
 *
 * AppAPI is absent here, so its `PublicFunctions` class is declared below as an empty
 * stand-in (only when nothing else declared it), as in ProviderFactoryCliTurnTest.
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

	use Exception;
	use GuzzleHttp\Psr7\Request;
	use LLPhant\Chat\Message as LLPhantMessage;
	use LLPhant\Chat\OpenAIChat;
	use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
	use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
	use OCA\Hermiq\Service\Llm\ChatDriver;
	use OCA\Hermiq\Service\Llm\ChatDriverRequest;
	use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
	use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
	use OCA\Hermiq\Service\Llm\ProviderFactory;
	use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
	use OCA\Hermiq\Service\Llm\RunTokenService;
	use OCA\Hermiq\Service\OrganisationModelPolicyService;
	use OCP\App\IAppManager;
	use OCP\IUserSession;
	use OCP\TaskProcessing\IManager;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;
	use ReflectionMethod;
	use RuntimeException;

	/**
	 * The factory's guard and fallback branches, one decision per test.
	 *
	 * @covers \OCA\Hermiq\Service\Llm\ProviderFactory
	 * @uses   \OCA\Hermiq\Service\Llm\BrokerHttpClient
	 * @uses   \OCA\Hermiq\Service\Llm\ChatDriver
	 * @uses   \OCA\Hermiq\Service\Llm\ChatDriverRequest
	 * @uses   \OCA\Hermiq\Service\Llm\ModelPolicyViolationException
	 */
	final class ProviderFactoryBranchesTest extends TestCase {

		/**
		 * Build a factory with only the named collaborators.
		 *
		 * @param array<string, mixed> $args Named constructor arguments beyond the first four.
		 *
		 * @return ProviderFactory
		 */
		private function factory(array $args = []): ProviderFactory {
			return new ProviderFactory(
				settingsHandler: $this->createMock(LlmSettingsHandler::class),
				taskManager: $this->createMock(IManager::class),
				userSession: ($args['userSession'] ?? $this->createMock(IUserSession::class)),
				logger: ($args['logger'] ?? $this->createMock(LoggerInterface::class)),
				modelPolicyService: ($args['modelPolicyService'] ?? null),
				appManager: ($args['appManager'] ?? null),
				credentialResolver: ($args['credentialResolver'] ?? null),
				runTokenService: ($args['runTokenService'] ?? null),
				container: ($args['container'] ?? null),
				featureResolver: ($args['featureResolver'] ?? null)
			);
		}//end factory()

		/**
		 * Call a private method of the factory.
		 *
		 * @param ProviderFactory      $factory The factory.
		 * @param string               $method  The method name.
		 * @param array<string, mixed> $args    Its named arguments.
		 *
		 * @return mixed
		 */
		private function call(ProviderFactory $factory, string $method, array $args = []): mixed {
			return (new ReflectionMethod(ProviderFactory::class, $method))->invokeArgs($factory, $args);
		}//end call()

		/**
		 * A container that answers every id with the given object.
		 *
		 * @param object $service The object to serve.
		 *
		 * @return ContainerInterface
		 */
		private function containerServing(object $service): ContainerInterface {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($service);
			return $container;
		}//end containerServing()

		/**
		 * An AppAPI stand-in whose runner ExApp is the given record.
		 *
		 * @param array<string, mixed>|null $exApp The ExApp record, or null when not installed.
		 *
		 * @return object
		 */
		private function appApi(?array $exApp): object {
			return new class ($exApp) {
				/**
				 * Keep the record.
				 *
				 * @param array<string, mixed>|null $exApp The record.
				 */
				public function __construct(private ?array $exApp) {
				}

				/**
				 * The runner ExApp record.
				 *
				 * @param string $appId The ExApp id.
				 *
				 * @return array<string, mixed>|null
				 */
				public function getExApp(string $appId): ?array {
					return $this->exApp;
				}
			};
		}//end appApi()

		/**
		 * A feature binding with a provider and model replaces the resolved pair and marks it bound.
		 *
		 * @return void
		 */
		public function testAFeatureBindingReplacesTheProviderAndModel(): void {
			$resolver = $this->createMock(FeatureProviderResolver::class);
			$resolver->expects(self::once())->method('bindingFor')
				->with('summarise', 'org-1')
				->willReturn(['provider' => 'ollama', 'model' => 'llama3']);
			$request = new ChatDriverRequest(llmConfig: ['chatProvider' => 'openai'], organisation: 'org-1');
			$request->aiFeature = 'summarise';
			$request->agentModel = 'gpt-4o';

			$this->call($this->factory(['featureResolver' => $resolver]), 'resolveFeatureBinding', ['request' => $request]);

			self::assertTrue($request->featureBound);
			self::assertSame('ollama', $request->chatProvider);
			self::assertSame('llama3', $request->agentModel);
		}//end testAFeatureBindingReplacesTheProviderAndModel()

		/**
		 * A binding without a model leaves the resolved pair alone, but still marks it bound.
		 *
		 * @return void
		 */
		public function testAnIncompleteBindingKeepsThePair(): void {
			$resolver = $this->createMock(FeatureProviderResolver::class);
			$resolver->method('bindingFor')->willReturn(['provider' => 'ollama', 'model' => null]);
			$request = new ChatDriverRequest(llmConfig: ['chatProvider' => 'openai'], organisation: 'org-1');
			$request->aiFeature = 'summarise';
			$request->agentModel = 'gpt-4o';

			$this->call($this->factory(['featureResolver' => $resolver]), 'resolveFeatureBinding', ['request' => $request]);

			self::assertTrue($request->featureBound);
			self::assertSame('openai', $request->chatProvider);
			self::assertSame('gpt-4o', $request->agentModel);
		}//end testAnIncompleteBindingKeepsThePair()

		/**
		 * The instance-wide policy (empty organisation) is named as such in the refusal.
		 *
		 * @return void
		 */
		public function testAnInstanceWideRefusalNamesTheInstance(): void {
			$policy = $this->createMock(OrganisationModelPolicyService::class);
			$policy->expects(self::once())->method('isAllowed')
				->with('', 'openai', 'gpt-4o')->willReturn(false);

			try {
				$this->call(
					$this->factory(['modelPolicyService' => $policy]),
					'enforceModelPolicy',
					['organisation' => '', 'provider' => 'openai', 'model' => 'gpt-4o']
				);
				self::fail('A refused pair was allowed.');
			} catch (ModelPolicyViolationException $e) {
				self::assertSame(422, $e->getCode());
				self::assertSame(
					"Refused by the model-policy check: organisation '(instance-wide)' does not permit provider 'openai' model 'gpt-4o'.",
					$e->getMessage()
				);
			}
		}//end testAnInstanceWideRefusalNamesTheInstance()

		/**
		 * Without an app manager the AppAPI gap is the app manager.
		 *
		 * @return void
		 */
		public function testTheAppApiGapWithoutAnAppManager(): void {
			self::assertSame('no_app_manager', $this->call($this->factory(), 'appApiGap'));
		}//end testTheAppApiGapWithoutAnAppManager()

		/**
		 * With AppAPI not installed the gap is AppAPI itself, and its id is the one asked about.
		 *
		 * @return void
		 */
		public function testTheAppApiGapWhenAppApiIsNotInstalled(): void {
			$apps = $this->createMock(IAppManager::class);
			$apps->expects(self::once())->method('isInstalled')->with('app_api')->willReturn(false);

			self::assertSame('no_app_api', $this->call($this->factory(['appManager' => $apps]), 'appApiGap'));
		}//end testTheAppApiGapWhenAppApiIsNotInstalled()

		/**
		 * With AppAPI installed and its interface loadable there is no gap.
		 *
		 * @return void
		 */
		public function testNoAppApiGapWhenItIsThere(): void {
			$apps = $this->createMock(IAppManager::class);
			$apps->method('isInstalled')->willReturn(true);

			self::assertNull($this->call($this->factory(['appManager' => $apps]), 'appApiGap'));
		}//end testNoAppApiGapWhenItIsThere()

		/**
		 * A runner ExApp that AppAPI does not know is the `no_runner` gap.
		 *
		 * @return void
		 */
		public function testTheRunnerGapWhenItIsNotInstalled(): void {
			$factory = $this->factory(['container' => $this->containerServing($this->appApi(null))]);

			self::assertSame('no_runner', $this->call($factory, 'runnerGap'));
		}//end testTheRunnerGapWhenItIsNotInstalled()

		/**
		 * A runner ExApp that is installed but not enabled (or says nothing) is disabled.
		 *
		 * @return void
		 */
		public function testTheRunnerGapWhenItIsDisabled(): void {
			$disabled = $this->factory(['container' => $this->containerServing($this->appApi(['enabled' => false]))]);
			$silent = $this->factory(['container' => $this->containerServing($this->appApi(['appid' => 'x']))]);
			$enabled = $this->factory(['container' => $this->containerServing($this->appApi(['enabled' => true]))]);

			self::assertSame('runner_disabled', $this->call($disabled, 'runnerGap'));
			self::assertSame('runner_disabled', $this->call($silent, 'runnerGap'));
			self::assertNull($this->call($enabled, 'runnerGap'));
		}//end testTheRunnerGapWhenItIsDisabled()

		/**
		 * A personal credential the broker resolves to nothing is not injectable: a 503.
		 *
		 * @return void
		 */
		public function testAnEmptyBrokerTokenIsRefused(): void {
			$scopes = $this->createMock(CredentialScopeResolver::class);
			$scopes->method('scopeOfCredential')->willReturn('personal');
			$broker = new class () {
				/** @var array<int, mixed> The arguments of the last call. */
				public array $asked = [];

				/**
				 * Resolve nothing.
				 *
				 * @param string      $credentialId The credential.
				 * @param string      $appId        The app.
				 * @param string|null $uid          The user.
				 *
				 * @return string
				 */
				public function resolveInjectable(string $credentialId, string $appId, ?string $uid): string {
					$this->asked = [$credentialId, $appId, $uid];
					return '';
				}
			};
			$factory = $this->factory(['credentialResolver' => $scopes, 'container' => $this->containerServing($broker)]);

			try {
				$this->call($factory, 'resolveCliToken', ['credentialId' => 'cred-1', 'uid' => 'alice']);
				self::fail('An empty token was handed to the runner.');
			} catch (ProviderUnavailableException $e) {
				self::assertSame(503, $e->getCode());
				self::assertStringStartsWith('Anthropic executionMode "cli"', $e->getMessage());
			}

			self::assertSame(['cred-1', 'hermiq', 'alice'], $broker->asked);
		}//end testAnEmptyBrokerTokenIsRefused()

		/**
		 * A resolved token is returned as the broker gave it.
		 *
		 * @return void
		 */
		public function testAResolvedBrokerTokenIsReturned(): void {
			$scopes = $this->createMock(CredentialScopeResolver::class);
			$scopes->method('scopeOfCredential')->willReturn('personal');
			$broker = new class () {
				/**
				 * Resolve the subscription token.
				 *
				 * @param string      $credentialId The credential.
				 * @param string      $appId        The app.
				 * @param string|null $uid          The user.
				 *
				 * @return string
				 */
				public function resolveInjectable(string $credentialId, string $appId, ?string $uid): string {
					return 'sub-token';
				}
			};
			$factory = $this->factory(['credentialResolver' => $scopes, 'container' => $this->containerServing($broker)]);

			self::assertSame('sub-token', $this->call($factory, 'resolveCliToken', ['credentialId' => 'cred-1', 'uid' => null]));
		}//end testAResolvedBrokerTokenIsReturned()

		/**
		 * Empty turns are dropped from the runner's messages; the rest keep role and order.
		 *
		 * @return void
		 */
		public function testEmptyTurnsAreDroppedFromTheCliMessages(): void {
			$history = [
				LLPhantMessage::user('Hello'),
				LLPhantMessage::assistant(''),
				LLPhantMessage::assistant('Hi there'),
			];

			self::assertSame(
				[
					['role' => 'user', 'content' => 'Hello'],
					['role' => 'assistant', 'content' => 'Hi there'],
				],
				$this->call($this->factory(), 'mapHistoryToCliMessages', ['messageHistory' => $history])
			);
		}//end testEmptyTurnsAreDroppedFromTheCliMessages()

		/**
		 * An openai driver generates on its own ready chat instance.
		 *
		 * @return void
		 */
		public function testAnOpenAiDriverGeneratesOnItsChat(): void {
			$chat = $this->createMock(OpenAIChat::class);
			$chat->expects(self::once())->method('generateText')->with('Summarise this.')->willReturn('A summary.');
			$driver = new ChatDriver(provider: 'openai', chat: $chat, model: 'gpt-4o');

			self::assertSame(
				'A summary.',
				$this->call($this->factory(), 'generateWithDriver', ['driver' => $driver, 'prompt' => 'Summarise this.'])
			);
		}//end testAnOpenAiDriverGeneratesOnItsChat()

		/**
		 * A provider without a credential is refused and told where to select one.
		 *
		 * @return void
		 */
		public function testAProviderWithoutACredentialIsRefused(): void {
			try {
				$this->call($this->factory(), 'assertBrokerCredential', ['credentialId' => '', 'label' => 'Fireworks AI']);
				self::fail('A provider without a credential was allowed.');
			} catch (ProviderUnavailableException $e) {
				self::assertSame(503, $e->getCode());
				self::assertSame(
					'Fireworks AI has no credential. Select one from the credential broker in the Hermiq LLM settings.',
					$e->getMessage()
				);
			}
		}//end testAProviderWithoutACredentialIsRefused()

		/**
		 * Without a session user there is no acting UID.
		 *
		 * @return void
		 */
		public function testThereIsNoUidWithoutASessionUser(): void {
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn(null);

			self::assertNull($this->call($this->factory(['userSession' => $session]), 'currentUid'));
		}//end testThereIsNoUidWithoutASessionUser()

		/**
		 * Without a user no egress token is minted, and the service is not asked.
		 *
		 * @return void
		 */
		public function testNoEgressTokenIsMintedWithoutAUser(): void {
			$tokens = $this->createMock(RunTokenService::class);
			$tokens->expects(self::never())->method('mint');
			$factory = $this->factory(['runTokenService' => $tokens]);

			self::assertSame('', $this->call($factory, 'mintEgressRunToken', ['agentId' => 'agent-1', 'uid' => null]));
			self::assertSame('', $this->call($factory, 'mintEgressRunToken', ['agentId' => 'agent-1', 'uid' => '']));
		}//end testNoEgressTokenIsMintedWithoutAUser()

		/**
		 * A Fireworks call the broker refuses surfaces as a Fireworks request failure.
		 *
		 * @return void
		 */
		public function testAFailedFireworksCallIsWrapped(): void {
			$broker = new class () {
				/**
				 * Refuse the call.
				 *
				 * @param mixed ...$args The call.
				 *
				 * @return array<string, mixed>
				 */
				public function request(mixed ...$args): array {
					throw new RuntimeException('denied');
				}
			};
			$factory = $this->factory(['container' => $this->containerServing($broker)]);

			try {
				$this->call(
					$factory,
					'sendToFireworks',
					['credentialId' => 'cred-1', 'request' => new Request('POST', 'https://api.fireworks.ai/inference/v1/chat/completions')]
				);
				self::fail('A refused call was answered.');
			} catch (Exception $e) {
				self::assertSame(Exception::class, get_class($e));
				self::assertSame(
					'Fireworks API request failed: Hermiq LLM: the credential broker refused or could not make the call.',
					$e->getMessage()
				);
			}
		}//end testAFailedFireworksCallIsWrapped()
	}//end class
}
