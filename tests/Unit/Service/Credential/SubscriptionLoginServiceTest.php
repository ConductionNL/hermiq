<?php

/**
 * Hermiq SubscriptionLoginService unit tests.
 *
 * A stubbed runner, a stubbed broker and a capturing logger: the sign-in token
 * reaches only the vendor's hosts, the subscription token goes to the broker as
 * a personal credential, and neither the browser's answer nor a log line holds
 * any part of it (models-provider-connections Task 5 and 6).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Credential
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
 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-signed-in-token-is-kept-only-in-the-broker-req-signin-002
 */

declare(strict_types=1);

namespace OCA\AppAPI {

	if (class_exists(PublicFunctions::class) === false) {
		/**
		 * Stand-in so the service sees AppAPI's public interface.
		 */
		class PublicFunctions {
		}
	}
}

namespace OCA\Hermiq\Tests\Unit\Service\Credential {

	use OCA\Hermiq\Service\Credential\SubscriptionLoginException;
	use OCA\Hermiq\Service\Credential\SubscriptionLoginService;
	use OCA\Hermiq\Service\Llm\RunTokenRequest;
	use OCA\Hermiq\Service\Llm\RunTokenService;
	use OCA\OpenRegister\Db\ObjectEntity;
	use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
	use OCA\OpenRegister\Service\Credential\CredentialMint;
	use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
	use OCP\App\IAppManager;
	use OCP\Http\Client\IResponse;
	use OCP\ICache;
	use OCP\ICacheFactory;
	use OCP\Security\ISecureRandom;
	use PHPUnit\Framework\Attributes\CoversClass;
	use PHPUnit\Framework\Attributes\UsesClass;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\AbstractLogger;
	use RuntimeException;

	/**
	 * The sign-in flow against a stubbed runner and broker.
	 */
	#[CoversClass(SubscriptionLoginService::class)]
	#[UsesClass(SubscriptionLoginException::class)]
	#[UsesClass(RunTokenRequest::class)]
	final class SubscriptionLoginServiceTest extends TestCase {

		private const TOKEN = 'sk-ant-oat01-SECRET-TOKEN-never-logged';

		private const LOGIN_ID = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

		/**
		 * The runner's answers by "METHOD route"; each a [status, body] pair.
		 *
		 * @var array<string, array{0: int, 1: array<string, mixed>}>
		 */
		private array $runner = [];

		/**
		 * Every request the runner received, as [method, route, params].
		 *
		 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
		 */
		private array $requests = [];

		/**
		 * Whether the runner ExApp is enabled; null when it is not installed.
		 *
		 * @var bool|null
		 */
		private ?bool $runnerEnabled = true;

		/**
		 * Every log line, message and context.
		 *
		 * @var list<string>
		 */
		private array $logLines = [];

		/**
		 * The minted sign-in requests.
		 *
		 * @var list<RunTokenRequest>
		 */
		private array $minted = [];

		/**
		 * The consumed run tokens.
		 *
		 * @var list<string>
		 */
		private array $consumed = [];

		/**
		 * The broker; replaced per test when a test asserts on the mint.
		 *
		 * @var CredentialBrokerService|null
		 */
		private ?CredentialBrokerService $broker = null;

		/**
		 * The shared cache store, so two services model two requests.
		 *
		 * @var array<string, mixed>
		 */
		private array $cacheStore = [];

		/**
		 * A service over the stubs.
		 *
		 * @param bool $appApiInstalled Whether AppAPI is installed.
		 *
		 * @return SubscriptionLoginService
		 */
		private function service(bool $appApiInstalled = true): SubscriptionLoginService {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('isInstalled')->willReturn($appApiInstalled);

			$appApi = new class($this) {
				public function __construct(private SubscriptionLoginServiceTest $test) {
				}

				public function getExApp(string $appId): ?array {
					return $this->test->exApp(appId: $appId);
				}

				public function exAppRequest(string $appId, string $route, ?string $userId, string $method, array $params, array $options): mixed {
					return $this->test->runnerAnswer(appId: $appId, route: $route, method: $method, params: $params, options: $options);
				}
			};

			$broker = ($this->broker ?? $this->createMock(CredentialBrokerService::class));
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				static function (string $id) use ($appApi, $broker): object {
					return match ($id) {
						'OCA\\AppAPI\\PublicFunctions' => $appApi,
						CredentialBrokerService::class => $broker,
						default => throw new RuntimeException('unexpected ' . $id),
					};
				}
			);

			$tokens = $this->createMock(RunTokenService::class);
			$tokens->method('mintFor')->willReturnCallback(
				function (RunTokenRequest $request): string {
					$this->minted[] = $request;
					return 'sign-in-token-' . count($this->minted);
				}
			);
			$tokens->method('consume')->willReturnCallback(
				function (string $token): void {
					$this->consumed[] = $token;
				}
			);

			$cache = $this->createMock(ICache::class);
			$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cacheStore[$key] ?? null);
			$cache->method('set')->willReturnCallback(
				function (string $key, mixed $value): bool {
					$this->cacheStore[$key] = $value;
					return true;
				}
			);
			$cache->method('remove')->willReturnCallback(
				function (string $key): bool {
					unset($this->cacheStore[$key]);
					return true;
				}
			);
			$cacheFactory = $this->createMock(ICacheFactory::class);
			$cacheFactory->method('createDistributed')->willReturn($cache);

			$random = $this->createMock(ISecureRandom::class);
			$random->method('generate')->willReturn('abcdefghijklmnop');

			$logger = new class($this->logLines) extends AbstractLogger {
				public function __construct(private array &$lines) {
				}

				public function log($level, string|\Stringable $message, array $context = []): void {
					$this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
				}
			};

			return new SubscriptionLoginService($appManager, $container, $tokens, $cacheFactory, $random, $logger);
		}//end service()

		/**
		 * The stubbed AppAPI's view of the runner ExApp.
		 *
		 * @param string $appId The ExApp id.
		 *
		 * @return array<string, mixed>|null The ExApp, or null when not installed.
		 */
		public function exApp(string $appId): ?array {
			$this->assertSame('hermiq-llm-runner', $appId);
			if ($this->runnerEnabled === null) {
				return null;
			}

			return ['appid' => $appId, 'enabled' => $this->runnerEnabled];
		}//end exApp()

		/**
		 * The stubbed runner's answer to one request.
		 *
		 * @param string               $appId   The ExApp id.
		 * @param string               $route   The route.
		 * @param string               $method  The method.
		 * @param array<string, mixed> $params  The parameters.
		 * @param array<string, mixed> $options The AppAPI options.
		 *
		 * @return mixed The response, or AppAPI's error array.
		 */
		public function runnerAnswer(string $appId, string $route, string $method, array $params, array $options): mixed {
			$this->assertSame('hermiq-llm-runner', $appId);
			$this->assertGreaterThan(30, $options['timeout']);
			$this->requests[] = [$method, $route, $params];
			$answer = ($this->runner[$method . ' ' . $route] ?? null);
			if ($answer === null) {
				return ['error' => 'not reachable'];
			}

			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($answer[0]);
			$response->method('getBody')->willReturn(json_encode($answer[1]));
			return $response;
		}//end runnerAnswer()

		/**
		 * A runner that offers the Claude sign-in and runs one to completion.
		 *
		 * @return void
		 */
		private function claudeRunner(): void {
			$this->runner['GET /login'] = [200, ['providers' => [['provider' => 'anthropic', 'mode' => 'code']]]];
			$this->runner['POST /login'] = [200, ['loginId' => self::LOGIN_ID, 'url' => 'https://claude.ai/oauth/authorize?x=1', 'mode' => 'code', 'userCode' => '']];
			$this->runner['POST /login/' . self::LOGIN_ID . '/code'] = [200, ['token' => self::TOKEN]];
		}//end claudeRunner()

		/**
		 * Assert no log line holds any part of the token.
		 *
		 * @return void
		 */
		private function assertTokenNeverLogged(): void {
			foreach ($this->logLines as $line) {
				$this->assertStringNotContainsString('SECRET-TOKEN', $line);
			}
		}//end assertTokenNeverLogged()

		/**
		 * No runner: nothing offered, and a start is refused with the stated text.
		 *
		 * @return void
		 *
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
		 */
		public function testWithoutTheRunnerNothingIsOffered(): void {
			$this->runnerEnabled = null;
			$this->assertSame(['available' => false, 'providers' => []], $this->service()->offered(uid: 'alice'));

			$this->runnerEnabled = false;
			$this->assertSame(['available' => false, 'providers' => []], $this->service()->offered(uid: 'alice'));
			$this->assertSame(['available' => false, 'providers' => []], $this->service(appApiInstalled: false)->offered(uid: 'alice'));

			try {
				$this->service()->start(uid: 'alice', provider: 'anthropic');
				$this->fail('a start without a runner must be refused');
			} catch (SubscriptionLoginException $e) {
				$this->assertSame(503, $e->getCode());
				$this->assertSame('Subscription sign-in needs the Hermiq LLM runner app.', $e->getMessage());
			}

			$this->assertSame([], $this->requests);
			$this->assertSame([], $this->minted);
		}//end testWithoutTheRunnerNothingIsOffered()

		/**
		 * Only the sign-ins hermiq knows are offered, as the runner reports them.
		 *
		 * @return void
		 *
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-chatgpt-subscription-can-sign-in-where-the-codex-cli-allows-it-req-signin-004
		 */
		public function testTheOfferFollowsTheRunner(): void {
			$this->runner['GET /login'] = [200, ['providers' => [['provider' => 'anthropic', 'mode' => 'code'], ['provider' => 'grok', 'mode' => 'code']]]];
			$this->assertSame(
				['available' => true, 'providers' => [['provider' => 'anthropic', 'mode' => 'code']]],
				$this->service()->offered(uid: 'alice')
			);

			// A runner without the sign-in route offers none, and no ChatGPT button shows.
			$this->runner['GET /login'] = [404, ['error' => 'not found']];
			$this->assertSame(['available' => true, 'providers' => []], $this->service()->offered(uid: 'alice'));
		}//end testTheOfferFollowsTheRunner()

		/**
		 * A start mints a ten-minute token for the vendor's sign-in hosts only and
		 * returns the runner's URL.
		 *
		 * @return void
		 *
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001
		 */
		public function testAStartMintsASignInTokenForTheVendorHostsOnly(): void {
			$this->claudeRunner();

			$started = $this->service()->start(uid: 'alice', provider: 'anthropic');

			$this->assertSame(
				['loginId' => self::LOGIN_ID, 'url' => 'https://claude.ai/oauth/authorize?x=1', 'mode' => 'code', 'userCode' => ''],
				$started
			);
			$this->assertCount(1, $this->minted);
			$this->assertSame('alice', $this->minted[0]->userId);
			$this->assertSame('', $this->minted[0]->agentId);
			$this->assertSame(600, $this->minted[0]->ttlSeconds);
			$this->assertSame(['claude.ai', 'console.anthropic.com', 'api.anthropic.com'], $this->minted[0]->egressHosts);
			$this->assertSame(['POST', '/login', ['provider' => 'anthropic', 'runToken' => 'sign-in-token-1']], $this->requests[0]);
		}//end testAStartMintsASignInTokenForTheVendorHostsOnly()

		/**
		 * An unknown provider is refused before the runner is asked.
		 *
		 * @return void
		 */
		public function testAnUnknownProviderIsRefused(): void {
			$this->claudeRunner();
			$this->expectException(SubscriptionLoginException::class);
			$this->expectExceptionCode(400);

			try {
				$this->service()->start(uid: 'alice', provider: 'grok');
			} finally {
				$this->assertSame([], $this->requests);
			}
		}//end testAnUnknownProviderIsRefused()

		/**
		 * A runner answer without a usable URL spends the token and is refused.
		 *
		 * @return void
		 */
		public function testAStartWithoutAUrlSpendsTheToken(): void {
			$this->runner['POST /login'] = [200, ['loginId' => self::LOGIN_ID, 'url' => 'http://phish.example/']];

			try {
				$this->service()->start(uid: 'alice', provider: 'anthropic');
				$this->fail('an http URL must be refused');
			} catch (SubscriptionLoginException $e) {
				$this->assertSame(502, $e->getCode());
			}

			$this->assertSame(['sign-in-token-1'], $this->consumed);
		}//end testAStartWithoutAUrlSpendsTheToken()

		/**
		 * A finished sign-in stores the token in the broker as alice's personal
		 * anthropic-cli credential, for hermiq only, and answers id and label only.
		 *
		 * @return void
		 *
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-subscription-credential-stays-personal-req-signin-003
		 */
		public function testAFinishedSignInGoesToTheBrokerAsAPersonalCredential(): void {
			$this->claudeRunner();
			$saved = $this->createMock(ObjectEntity::class);
			$saved->method('getUuid')->willReturn('cred-1');
			$broker = $this->createMock(CredentialBrokerService::class);
			$broker->expects($this->once())->method('mintCredential')->willReturnCallback(
				function (CredentialMint $mint, CredentialMintOptions $options) use ($saved): ObjectEntity {
					$this->assertSame('Claude subscription', $mint->name);
					$this->assertSame('anthropic-cli', $mint->provider);
					$this->assertSame('alice', $mint->owner);
					$this->assertSame('personal', $options->scope);
					$this->assertNull($options->organisation);
					$this->assertSame(['hermiq'], $options->allowedApps);
					$this->assertSame(self::TOKEN, $options->secret());
					return $saved;
				}
			);
			$this->broker = $broker;

			$this->service()->start(uid: 'alice', provider: 'anthropic');
			$result = $this->service()->finish(uid: 'alice', loginId: self::LOGIN_ID, code: 'one-time-code');

			$this->assertSame(['id' => 'cred-1', 'label' => 'Claude subscription'], $result);
			$this->assertStringNotContainsString('SECRET-TOKEN', json_encode($result));
			$this->assertSame(['POST', '/login/' . self::LOGIN_ID . '/code', ['code' => 'one-time-code']], $this->requests[1]);
			$this->assertSame(['sign-in-token-1'], $this->consumed);
			$this->assertSame([], $this->cacheStore);
			$this->assertTokenNeverLogged();
		}//end testAFinishedSignInGoesToTheBrokerAsAPersonalCredential()

		/**
		 * A ChatGPT sign-in is stored as a personal openai-cli credential.
		 *
		 * @return void
		 *
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-chatgpt-subscription-can-sign-in-where-the-codex-cli-allows-it-req-signin-004
		 */
		public function testAChatGptSignInIsAPersonalOpenAiCliCredential(): void {
			$this->runner['POST /login'] = [200, ['loginId' => self::LOGIN_ID, 'url' => 'https://auth.openai.com/codex/device', 'mode' => 'device', 'userCode' => 'ABCD-1234']];
			$this->runner['POST /login/' . self::LOGIN_ID . '/code'] = [200, ['token' => '{"tokens":{}}']];
			$saved = $this->createMock(ObjectEntity::class);
			$saved->method('getUuid')->willReturn('cred-2');
			$broker = $this->createMock(CredentialBrokerService::class);
			$broker->expects($this->once())->method('mintCredential')->with(
				$this->callback(static fn (CredentialMint $mint): bool => $mint->provider === 'openai-cli' && $mint->owner === 'bob'),
				$this->callback(static fn (CredentialMintOptions $options): bool => $options->scope === 'personal')
			)->willReturn($saved);
			$this->broker = $broker;

			$started = $this->service()->start(uid: 'bob', provider: 'openai');
			$this->assertSame('ABCD-1234', $started['userCode']);
			$this->assertSame(['auth.openai.com', 'chatgpt.com'], $this->minted[0]->egressHosts);

			$this->assertSame(['id' => 'cred-2', 'label' => 'ChatGPT subscription'], $this->service()->finish(uid: 'bob', loginId: self::LOGIN_ID, code: ''));
		}//end testAChatGptSignInIsAPersonalOpenAiCliCredential()

		/**
		 * Another user cannot finish alice's sign-in, and the runner is not asked.
		 *
		 * @return void
		 */
		public function testOnlyTheUserWhoStartedASignInCanFinishIt(): void {
			$this->claudeRunner();
			$this->service()->start(uid: 'alice', provider: 'anthropic');

			try {
				$this->service()->finish(uid: 'mallory', loginId: self::LOGIN_ID, code: 'x');
				$this->fail('another user must not finish the sign-in');
			} catch (SubscriptionLoginException $e) {
				$this->assertSame(404, $e->getCode());
			}

			$this->assertCount(1, $this->requests);
		}//end testOnlyTheUserWhoStartedASignInCanFinishIt()

		/**
		 * A sign-in the runner did not complete spends the token and stores nothing.
		 *
		 * @return void
		 */
		public function testAFailedExchangeSpendsTheTokenAndStoresNothing(): void {
			$this->claudeRunner();
			$this->runner['POST /login/' . self::LOGIN_ID . '/code'] = [502, ['error' => 'the sign-in did not complete', 'code' => 'not_signed_in']];
			$broker = $this->createMock(CredentialBrokerService::class);
			$broker->expects($this->never())->method('mintCredential');
			$this->broker = $broker;

			$this->service()->start(uid: 'alice', provider: 'anthropic');
			try {
				$this->service()->finish(uid: 'alice', loginId: self::LOGIN_ID, code: 'wrong');
				$this->fail('a failed exchange must be refused');
			} catch (SubscriptionLoginException $e) {
				$this->assertSame(502, $e->getCode());
			}

			$this->assertSame(['sign-in-token-1'], $this->consumed);
		}//end testAFailedExchangeSpendsTheTokenAndStoresNothing()

		/**
		 * A broker refusal is 503, and neither the error nor the log holds the token.
		 *
		 * @return void
		 *
		 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-signed-in-token-is-kept-only-in-the-broker-req-signin-002
		 */
		public function testABrokerRefusalLeaksNoTokenIntoTheLogOrTheAnswer(): void {
			$this->claudeRunner();
			$broker = $this->createMock(CredentialBrokerService::class);
			$broker->method('mintCredential')->willThrowException(new RuntimeException('vault down for ' . self::TOKEN));
			$this->broker = $broker;

			$this->service()->start(uid: 'alice', provider: 'anthropic');
			try {
				$this->service()->finish(uid: 'alice', loginId: self::LOGIN_ID, code: 'one-time-code');
				$this->fail('a broker refusal must be refused');
			} catch (SubscriptionLoginException $e) {
				$this->assertSame(503, $e->getCode());
				$this->assertStringNotContainsString('SECRET-TOKEN', $e->getMessage());
			}

			$this->assertNotSame([], $this->logLines);
			$this->assertTokenNeverLogged();
		}//end testABrokerRefusalLeaksNoTokenIntoTheLogOrTheAnswer()
	}//end class
}
