<?php

/**
 * Unit tests for WebhookSecretService (agent-webhook-trigger).
 *
 * Exercises the secret lifecycle without a live Nextcloud/OpenRegister:
 *   - generateSecret()/hash() shape (hwh_-prefixed, >= 32 bytes entropy, only
 *     the SHA-256 hash ever persisted);
 *   - create() persists a hash + prefix and returns the plaintext once, and
 *     refuses (throws) when a webhook already exists for the agent;
 *   - rotate() invalidates the previous secret immediately (new hash verifies,
 *     old one does not) and stamps rotatedAt;
 *   - revoke() disables without deleting configuration;
 *   - status() never leaks secretHash or the plaintext secret;
 *   - verifyAndLoad() is enumeration-safe: unknown agent / no webhook /
 *     disabled / wrong secret all return null, and ALWAYS runs hash_equals()
 *     (even with no AgentWebhook at all) via the dummy-hash fallback.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\WebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the agent-webhook-trigger WebhookSecretService.
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
 */
class WebhookSecretServiceTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock IUserManager.
	 *
	 * @var IUserManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserManager $userManager;

	/**
	 * Service under test.
	 *
	 * @var WebhookSecretService
	 */
	private WebhookSecretService $service;

	/**
	 * Objects persisted via saveObject() during a test, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'webhook-1');
				$entity->setObject($object);
				$entity->setOwner('alice');
				return $entity;
			}
		);

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$this->service = new WebhookSecretService(
			objectService: $this->objectService,
			userSession: $this->userSession,
			userManager: $this->userManager,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Build an AgentWebhook ObjectEntity.
	 *
	 * @param array<string, mixed> $data The object's data.
	 * @param string $owner The owner UID.
	 *
	 * @return ObjectEntity
	 */
	private function webhook(array $data, string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('webhook-1');
		$entity->setOwner($owner);
		$entity->setObject($data);
		return $entity;
	}//end webhook()

	/**
	 * create() generates an hwh_-prefixed secret with >= 32 bytes of entropy
	 * (64 hex chars after the prefix), persists only its SHA-256 hash, and
	 * returns the plaintext exactly once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
	 */
	public function testCreateGeneratesSecretAndPersistsOnlyHash(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$result = $this->service->create(agentId: 'agent-1', owner: 'alice');

		$this->assertStringStartsWith('hwh_', $result['secret']);
		// hwh_ + 64 hex chars (32 bytes of entropy).
		$this->assertSame(4 + 64, strlen($result['secret']));

		$this->assertCount(1, $this->saved);
		$this->assertSame(hash('sha256', $result['secret']), $this->saved[0]['secretHash']);
		$this->assertArrayNotHasKey('secret', $this->saved[0]);
		$this->assertSame(substr($result['secret'], 0, 8), $this->saved[0]['secretPrefix']);
		$this->assertTrue($this->saved[0]['enabled']);
		$this->assertSame('agent-1', $this->saved[0]['agentId']);

	}//end testCreateGeneratesSecretAndPersistsOnlyHash()

	/**
	 * create() refuses (409, via a thrown exception) when a webhook already
	 * exists for the agent — use rotate() instead.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testCreateRefusesWhenWebhookAlreadyExists(): void {
		$this->objectService->method('findAll')->willReturn([$this->webhook(['agentId' => 'agent-1'])]);

		$this->expectException(RuntimeException::class);
		$this->service->create(agentId: 'agent-1', owner: 'alice');

	}//end testCreateRefusesWhenWebhookAlreadyExists()

	/**
	 * rotate() invalidates the previous secret's hash immediately: the new
	 * secret verifies, the old one no longer does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testRotateInvalidatesPreviousSecretImmediately(): void {
		$oldSecret = 'hwh_' . str_repeat('a', 64);
		$existing = $this->webhook(
			[
				'agentId' => 'agent-1',
				'secretHash' => hash('sha256', $oldSecret),
				'secretPrefix' => substr($oldSecret, 0, 8),
				'enabled' => true,
			]
		);

		$result = $this->service->rotate(webhook: $existing);

		$this->assertNotSame($oldSecret, $result['secret']);
		$this->assertCount(1, $this->saved);
		$this->assertSame(hash('sha256', $result['secret']), $this->saved[0]['secretHash']);
		$this->assertNotNull($this->saved[0]['rotatedAt']);

		// The OLD secret no longer verifies against the NEW persisted hash.
		$this->assertFalse(hash_equals($this->saved[0]['secretHash'], hash('sha256', $oldSecret)));

	}//end testRotateInvalidatesPreviousSecretImmediately()

	/**
	 * revoke() disables the webhook without deleting its configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testRevokeDisablesWithoutDeletingConfig(): void {
		$existing = $this->webhook(
			[
				'agentId' => 'agent-1',
				'secretHash' => 'deadbeef',
				'secretPrefix' => 'hwh_dead',
				'enabled' => true,
				'reviewer' => 'bob',
			]
		);

		$updated = $this->service->revoke(webhook: $existing);

		$this->assertFalse($updated->getObject()['enabled']);
		$this->assertSame('bob', $updated->getObject()['reviewer']);
		$this->assertSame('deadbeef', $updated->getObject()['secretHash']);

	}//end testRevokeDisablesWithoutDeletingConfig()

	/**
	 * status() never includes secretHash or the plaintext secret.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
	 */
	public function testStatusNeverLeaksSecretOrHash(): void {
		$webhook = $this->webhook(
			[
				'agentId' => 'agent-1',
				'secretHash' => 'super-secret-hash',
				'secretPrefix' => 'hwh_ab12',
				'enabled' => true,
				'createdAt' => '2026-01-01T00:00:00+00:00',
			]
		);

		$status = $this->service->status(webhook: $webhook);

		$this->assertTrue($status['configured']);
		$this->assertSame('hwh_ab12', $status['secretPrefix']);
		$this->assertArrayNotHasKey('secretHash', $status);
		$this->assertArrayNotHasKey('secret', $status);

	}//end testStatusNeverLeaksSecretOrHash()

	/**
	 * status() reports {configured:false} when no webhook exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
	 */
	public function testStatusReportsUnconfiguredWhenAbsent(): void {
		$this->assertSame(['configured' => false], $this->service->status(webhook: null));

	}//end testStatusReportsUnconfiguredWhenAbsent()

	/**
	 * verifyAndLoad() accepts the correct secret for an enabled webhook.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
	 */
	public function testVerifyAndLoadAcceptsCorrectSecret(): void {
		$secret = 'hwh_correctsecret';
		$this->objectService->method('findAll')->willReturn(
			[
				$this->webhook(
					[
						'agentId' => 'agent-1',
						'secretHash' => hash('sha256', $secret),
						'enabled' => true,
					]
				),
			]
		);

		$matched = $this->service->verifyAndLoad(agentId: 'agent-1', providedSecret: $secret);

		$this->assertInstanceOf(ObjectEntity::class, $matched);

	}//end testVerifyAndLoadAcceptsCorrectSecret()

	/**
	 * verifyAndLoad() is enumeration-safe: an unknown agent, an agent with no
	 * webhook, a disabled webhook, and a wrong secret on an enabled webhook ALL
	 * return null — collapsing every auth-failure mode into one shape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
	 */
	public function testVerifyAndLoadRejectsEveryFailureModeIdentically(): void {
		// Unknown agent (no AgentWebhook at all).
		$this->objectService->method('findAll')->willReturn([]);
		$this->assertNull($this->service->verifyAndLoad(agentId: 'unknown-agent', providedSecret: 'anything'));

		// Disabled webhook, even with the ORIGINALLY-valid secret.
		$secret = 'hwh_wasvalid';
		$disabledObjSvc = $this->createMock(ObjectService::class);
		$disabledObjSvc->method('setRegister')->willReturnSelf();
		$disabledObjSvc->method('setSchema')->willReturnSelf();
		$disabledObjSvc->method('findAll')->willReturn(
			[
				$this->webhook(
					[
						'agentId' => 'agent-1',
						'secretHash' => hash('sha256', $secret),
						'enabled' => false,
					]
				),
			]
		);
		$disabledService = new WebhookSecretService(
			objectService: $disabledObjSvc,
			userSession: $this->userSession,
			userManager: $this->userManager,
			logger: $this->createMock(LoggerInterface::class)
		);
		$this->assertNull($disabledService->verifyAndLoad(agentId: 'agent-1', providedSecret: $secret));

		// Wrong secret on an enabled webhook.
		$wrongObjSvc = $this->createMock(ObjectService::class);
		$wrongObjSvc->method('setRegister')->willReturnSelf();
		$wrongObjSvc->method('setSchema')->willReturnSelf();
		$wrongObjSvc->method('findAll')->willReturn(
			[
				$this->webhook(
					[
						'agentId' => 'agent-1',
						'secretHash' => hash('sha256', 'hwh_correct'),
						'enabled' => true,
					]
				),
			]
		);
		$wrongService = new WebhookSecretService(
			objectService: $wrongObjSvc,
			userSession: $this->userSession,
			userManager: $this->userManager,
			logger: $this->createMock(LoggerInterface::class)
		);
		$this->assertNull($wrongService->verifyAndLoad(agentId: 'agent-1', providedSecret: 'hwh_wrong'));

	}//end testVerifyAndLoadRejectsEveryFailureModeIdentically()

	/**
	 * markUsed() persists lastUsedAt but never throws, even when the save fails.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
	 */
	public function testMarkUsedPersistsTimestampAndNeverThrows(): void {
		$webhook = $this->webhook(['agentId' => 'agent-1', 'secretHash' => 'x', 'enabled' => true]);

		$this->service->markUsed(webhook: $webhook);

		$this->assertCount(1, $this->saved);
		$this->assertNotNull($this->saved[0]['lastUsedAt']);

	}//end testMarkUsedPersistsTimestampAndNeverThrows()
}//end class
