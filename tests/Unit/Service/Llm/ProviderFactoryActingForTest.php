<?php

/**
 * A background task's broker call acts for the task's user (claude-provider-for-every-member).
 *
 * Nextcloud runs Assistant tasks from cron with no session. Hermiq's Anthropic path asked
 * the broker with `actingUserId = currentUid()`, null there, so the broker refused every
 * background call, for a personal and an organisation credential alike. These tests drive
 * the REAL `generateText()` -> `callAnthropicChat()` -> `BrokerHttpClient` path with the
 * OpenRegister broker stubbed, and read the acting user the broker actually received.
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
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-a-background-task-acts-for-the-tasks-user
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * The acting user ProviderFactory hands the broker.
 *
 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-a-background-task-acts-for-the-tasks-user
 */
class ProviderFactoryActingForTest extends TestCase {

	/**
	 * Every broker call, in order: credential id and acting user.
	 *
	 * @var array<int, array{credentialId: string, path: string, actingUserId: string|null}>
	 */
	private array $calls = [];

	/**
	 * How many broker calls went through the broker's background entry.
	 *
	 * @var int
	 */
	private int $backgroundCalls = 0;

	/**
	 * The session user the mocked session reports; a test may change it mid-work.
	 *
	 * @var string|null
	 */
	private ?string $sessionUid = null;

	/**
	 * Build a factory configured for Anthropic (API key over http) through a stub broker.
	 *
	 * @param string|null $sessionUid The session user, or null for a cron run.
	 * @param bool $brokerFails Whether the broker throws instead of answering.
	 * @param array<string, mixed> $anthropicConfig Overrides for `anthropicConfig`.
	 * @param CredentialScopeResolver|null $resolver The credential resolver, if any.
	 *
	 * @return ProviderFactory
	 */
	private function factory(
		?string $sessionUid,
		bool $brokerFails = false,
		array $anthropicConfig = [],
		?CredentialScopeResolver $resolver = null,
	): ProviderFactory {
		$broker = $this->createMock(CredentialBrokerService::class);
		$answer = function (
			string $credentialId,
			string $appId,
			string $method,
			string $path,
			array $headers = [],
			?string $body = null,
			?string $actingUserId = null,
			bool $background = false,
		) use ($brokerFails): array {
				$this->calls[] = ['credentialId' => $credentialId, 'path' => $path, 'actingUserId' => $actingUserId];
				if ($background === true) {
					$this->backgroundCalls++;
				}
				if ($brokerFails === true) {
					throw new RuntimeException('Request not permitted');
				}

				return [
					'status' => 200,
					'headers' => ['content-type' => ['application/json']],
					'body' => (string)json_encode(
						[
							'content' => [['type' => 'text', 'text' => 'A short summary.']],
							'stop_reason' => 'end_turn',
						]
					),
				];
		};
		$broker->method('request')->willReturnCallback($answer);
		$broker->method('requestForBackgroundUser')->willReturnCallback(
			static fn (string $credentialId, string $appId, string $method, string $path, array $headers = [], ?string $body = null, string $actingUserId = ''): array
				=> $answer($credentialId, $appId, $method, $path, $headers, $body, $actingUserId, true)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === BrokerHttpClient::BROKER_CLASS ? $broker : null)
		);

		$this->sessionUid = $sessionUid;
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->sessionUid === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->sessionUid);
				return $user;
			}
		);

		$settings = $this->createMock(LlmSettingsHandler::class);
		$settings->method('getLLMSettingsOnly')->willReturn(
			[
				'chatProvider' => 'anthropic',
				'anthropicConfig' => array_merge(
					[
						'credentialId' => 'cred-org',
						'chatModel' => 'claude-opus-4-8',
						'authMode' => 'api_key',
						'executionMode' => 'http',
						'baseUrl' => 'https://api.anthropic.com/v1',
					],
					$anthropicConfig
				),
			]
		);

		return new ProviderFactory(
			$settings,
			$this->createMock(IManager::class),
			$userSession,
			new NullLogger(),
			'hermiq',
			null,
			null,
			$resolver,
			null,
			null,
			null,
			$container
		);
	}//end factory()

	/**
	 * A cron-run Assistant task reaches the broker acting for the task's user.
	 *
	 * Fails on the old code: the broker received `actingUserId` null.
	 *
	 * @return void
	 */
	public function testASessionlessTaskActsForItsUser(): void {
		$text = $this->factory(sessionUid: null)->generateText(prompt: 'Summarise this.', userId: 'bob', allowNextcloud: false);

		$this->assertSame('A short summary.', $text);
		$this->assertCount(1, $this->calls);
		$this->assertSame('/v1/messages', $this->calls[0]['path']);
		$this->assertSame('cred-org', $this->calls[0]['credentialId']);
		$this->assertSame('bob', $this->calls[0]['actingUserId']);
		// The broker was asked through its background entry: the sessionless rule applies.
		$this->assertSame(1, $this->backgroundCalls);
	}//end testASessionlessTaskActsForItsUser()

	/**
	 * A user switch inside background work (the contextagent turn runs inside OpenRegister's
	 * runAs()) never turns the call into a session call: the broker still judges the task's
	 * user by the background rule, with the session hidden.
	 *
	 * Fails on the first version of this change: the switched-in user was sent as a session
	 * user, so an administrator passed the session rule for every organisation.
	 *
	 * @return void
	 */
	public function testAUserSwitchInsideBackgroundWorkStaysABackgroundCall(): void {
		$factory = $this->factory(sessionUid: null);
		$factory->actingFor(
			userId: 'admin',
			work: function () use ($factory): void {
				// What runAs('admin') does: a session user appears mid-work.
				$this->sessionUid = 'admin';
				$factory->callAnthropicChat(
					credentialId: 'cred-org',
					model: 'claude-opus-4-8',
					baseUrl: 'https://api.anthropic.com/v1',
					messageHistory: [LLPhantMessage::user('Hi.')]
				);
			}
		);

		$this->assertSame('admin', $this->calls[0]['actingUserId']);
		$this->assertSame(1, $this->backgroundCalls, 'the broker call must go through the background entry');
	}//end testAUserSwitchInsideBackgroundWorkStaysABackgroundCall()

	/**
	 * A signed-in user is never replaced by the named one.
	 *
	 * @return void
	 */
	public function testASessionAlwaysWins(): void {
		$this->factory(sessionUid: 'alice')->generateText(prompt: 'Summarise this.', userId: 'bob', allowNextcloud: false);

		$this->assertSame('alice', $this->calls[0]['actingUserId']);
		$this->assertSame(0, $this->backgroundCalls, 'a real session is never hidden');
	}//end testASessionAlwaysWins()

	/**
	 * The acting user is gone once the work ends, so a later call acts for nobody.
	 *
	 * @return void
	 */
	public function testTheActingUserDoesNotOutliveTheWork(): void {
		$factory = $this->factory(sessionUid: null);
		$factory->generateText(prompt: 'First.', userId: 'bob', allowNextcloud: false);
		$factory->callAnthropicChat(
			credentialId: 'cred-org',
			model: 'claude-opus-4-8',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: [LLPhantMessage::user('Second.')]
		);

		$this->assertSame('bob', $this->calls[0]['actingUserId']);
		$this->assertNull($this->calls[1]['actingUserId']);
	}//end testTheActingUserDoesNotOutliveTheWork()

	/**
	 * The acting user is cleared also when the work throws.
	 *
	 * @return void
	 */
	public function testTheActingUserIsClearedWhenTheWorkThrows(): void {
		$factory = $this->factory(sessionUid: null, brokerFails: true);

		try {
			$factory->generateText(prompt: 'First.', userId: 'bob', allowNextcloud: false);
			$this->fail('The refused broker call should surface as an exception.');
		} catch (Throwable $e) {
			$this->assertNotSame('', $e->getMessage());
		}

		try {
			$factory->callAnthropicChat(
				credentialId: 'cred-org',
				model: 'claude-opus-4-8',
				baseUrl: 'https://api.anthropic.com/v1',
				messageHistory: [LLPhantMessage::user('Second.')]
			);
		} catch (Throwable $e) {
			// Expected: the stub broker refuses every call here.
		}

		$this->assertSame('bob', $this->calls[0]['actingUserId']);
		$this->assertNull($this->calls[1]['actingUserId']);
	}//end testTheActingUserIsClearedWhenTheWorkThrows()

	/**
	 * Nested scopes unwind to the outer user, and an empty id acts for nobody.
	 *
	 * @return void
	 */
	public function testNestedScopesUnwindAndAnEmptyIdActsForNobody(): void {
		$factory = $this->factory(sessionUid: null);
		$send = static fn () => $factory->callAnthropicChat(
			credentialId: 'cred-org',
			model: 'claude-opus-4-8',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: [LLPhantMessage::user('Hi.')]
		);

		$refused = false;
		$factory->actingFor(
			userId: 'bob',
			work: static function () use ($factory, $send, &$refused): void {
				// An inner scope naming nobody acts for nobody: the call is refused before
				// it reaches the broker, never sent as bob.
				try {
					$factory->actingFor(userId: '', work: $send);
				} catch (\Throwable $e) {
					$refused = true;
				}

				$send();
			}
		);

		$this->assertTrue($refused);
		$this->assertCount(1, $this->calls);
		$this->assertSame('bob', $this->calls[0]['actingUserId']);
	}//end testNestedScopesUnwindAndAnEmptyIdActsForNobody()

	/**
	 * Anthropic with an API key over http takes the resolver's personal or organisation credential.
	 *
	 * Fails on the old code: the driver always carried the configured credential.
	 *
	 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-anthropic-resolves-a-personal-then-organisation-credential
	 *
	 * @return void
	 */
	public function testAnthropicApiKeyUsesTheScopedCredential(): void {
		$resolver = $this->createMock(CredentialScopeResolver::class);
		$resolver->expects($this->once())->method('resolve')
			->with('anthropic', 'bob', 'org-1', null)
			->willReturn('cred-org-1');

		$factory = $this->factory(sessionUid: 'bob', resolver: $resolver);
		$driver = $factory->createChatDriver(llmConfig: $factory->getLlmConfig(), organisation: 'org-1');

		$this->assertSame('cred-org-1', $driver->credentialId);
	}//end testAnthropicApiKeyUsesTheScopedCredential()

	/**
	 * With no scoped credential found the configured instance credential stays.
	 *
	 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-anthropic-resolves-a-personal-then-organisation-credential
	 *
	 * @return void
	 */
	public function testAnthropicFallsBackToTheConfiguredCredential(): void {
		$resolver = $this->createMock(CredentialScopeResolver::class);
		$resolver->method('resolve')->willReturn(null);

		$factory = $this->factory(sessionUid: 'alice', resolver: $resolver);
		$driver = $factory->createChatDriver(llmConfig: $factory->getLlmConfig(), organisation: 'org-1');

		$this->assertSame('cred-org', $driver->credentialId);
	}//end testAnthropicFallsBackToTheConfiguredCredential()

	/**
	 * A Model field saved empty by the settings dialog falls back to the default model.
	 *
	 * Fails on the old code: `??` kept the empty string, and Anthropic refuses '' as a model.
	 *
	 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-anthropic-resolves-a-personal-then-organisation-credential
	 *
	 * @return void
	 */
	public function testAnEmptyModelFallsBackToTheDefault(): void {
		$factory = $this->factory(sessionUid: 'alice', anthropicConfig: ['chatModel' => '']);
		$driver = $factory->createChatDriver(llmConfig: $factory->getLlmConfig());

		$this->assertSame('claude-opus-4-8', $driver->model);
	}//end testAnEmptyModelFallsBackToTheDefault()

	/**
	 * OAuth carries a personal subscription: no lookup may swap another credential in.
	 *
	 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-anthropic-resolves-a-personal-then-organisation-credential
	 *
	 * @return void
	 */
	public function testAnthropicOauthKeepsTheConfiguredCredential(): void {
		$resolver = $this->createMock(CredentialScopeResolver::class);
		$resolver->expects($this->never())->method('resolve');

		$factory = $this->factory(sessionUid: 'alice', anthropicConfig: ['authMode' => 'oauth', 'credentialId' => 'cred-max'], resolver: $resolver);
		$driver = $factory->createChatDriver(llmConfig: $factory->getLlmConfig(), organisation: 'org-1');

		$this->assertSame('cred-max', $driver->credentialId);
	}//end testAnthropicOauthKeepsTheConfiguredCredential()
}//end class
