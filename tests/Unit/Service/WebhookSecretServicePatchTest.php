<?php

/**
 * Unit tests for WebhookSecretService patch(), markUsed() and findForAgent().
 *
 * Covers: patch() updates only the approval-gate fields it is given, coerces
 * requiresApproval to a strict boolean and reviewerType to user|group, never
 * touches the secret hash, and saves as the webhook owner before restoring
 * the prior session user; markUsed() stamps lastUsedAt and swallows a save
 * failure into a warning; findForAgent() returns only the entity whose
 * agentId matches exactly.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\WebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for WebhookSecretService write helpers.
 */
#[CoversClass(WebhookSecretService::class)]
class WebhookSecretServicePatchTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var WebhookSecretService
	 */
	private WebhookSecretService $service;

	/**
	 * Wire fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$this->service     = new WebhookSecretService(
			objectService: $this->objectService,
			userSession: $this->userSession,
			userManager: $this->userManager,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Build an AgentWebhook entity.
	 *
	 * @param array<string, mixed> $payload The body.
	 * @param string|null          $owner   The owner uid.
	 *
	 * @return ObjectEntity
	 */
	private function webhook(array $payload, ?string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('hook-1');
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;
	}//end webhook()

	/**
	 * Capture the object passed to saveObject().
	 *
	 * @param array<string, mixed>|null $saved Receives the saved body.
	 *
	 * @return ObjectEntity The entity saveObject() returns.
	 */
	private function captureSave(?array &$saved): ObjectEntity {
		$result = new ObjectEntity();
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend, string $register, string $schema, ?string $uuid, bool $rbac, bool $multi) use (&$saved, $result): ObjectEntity {
				$saved = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $rbac, 'multi' => $multi];
				return $result;
			}
		);
		return $result;
	}//end captureSave()

	/**
	 * patch() writes all three gate fields, coerced, and leaves the secret alone.
	 *
	 * @return void
	 */
	public function testPatchCoercesTheGateFieldsAndKeepsTheSecret(): void {
		$saved  = null;
		$result = $this->captureSave($saved);

		$returned = $this->service->patch(
			$this->webhook(['agentId' => 'a1', 'secretHash' => 'h', 'requiresApproval' => false, 'reviewerType' => 'user']),
			['requiresApproval' => 'yes', 'reviewer' => 42, 'reviewerType' => 'group']
		);

		$this->assertSame($result, $returned);
		$this->assertSame(
			['id' => 'hook-1', 'agentId' => 'a1', 'secretHash' => 'h', 'requiresApproval' => false, 'reviewerType' => 'group', 'reviewer' => '42'],
			$saved['object']
		);
		$this->assertSame('hermiq', $saved['register']);
		$this->assertSame('agentwebhook', $saved['schema']);
		$this->assertSame('hook-1', $saved['uuid']);
		$this->assertFalse($saved['rbac']);
		$this->assertFalse($saved['multi']);
	}//end testPatchCoercesTheGateFieldsAndKeepsTheSecret()

	/**
	 * requiresApproval=true stays true; an unknown reviewerType falls back to user.
	 *
	 * @return void
	 */
	public function testPatchAcceptsTrueAndFallsBackToUserReviewerType(): void {
		$saved = null;
		$this->captureSave($saved);

		$this->service->patch(
			$this->webhook(['reviewerType' => 'group', 'reviewer' => 'old']),
			['requiresApproval' => true, 'reviewerType' => 'team']
		);

		$this->assertTrue($saved['object']['requiresApproval']);
		$this->assertSame('user', $saved['object']['reviewerType']);
		$this->assertSame('old', $saved['object']['reviewer']);
	}//end testPatchAcceptsTrueAndFallsBackToUserReviewerType()

	/**
	 * patch() with no fields re-saves the body unchanged.
	 *
	 * @return void
	 */
	public function testPatchWithoutFieldsChangesNothing(): void {
		$saved = null;
		$this->captureSave($saved);

		$this->service->patch($this->webhook(['requiresApproval' => true, 'reviewer' => 'bob']), []);

		$this->assertSame(['id' => 'hook-1', 'requiresApproval' => true, 'reviewer' => 'bob'], $saved['object']);
	}//end testPatchWithoutFieldsChangesNothing()

	/**
	 * The save runs as the webhook owner and the prior session user is restored after.
	 *
	 * @return void
	 */
	public function testPatchSavesAsTheOwnerAndRestoresTheSession(): void {
		$prior = $this->createMock(IUser::class);
		$owner = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($prior);
		$this->userManager->expects($this->once())->method('get')->with('alice')->willReturn($owner);

		$set = [];
		$this->userSession->method('setUser')->willReturnCallback(
			static function (?IUser $user) use (&$set): void {
				$set[] = $user;
			}
		);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->patch($this->webhook([]), ['reviewer' => 'x']);

		$this->assertSame([$owner, $prior], $set);
	}//end testPatchSavesAsTheOwnerAndRestoresTheSession()

	/**
	 * An ownerless webhook is saved without switching user, and the session is still restored.
	 *
	 * @return void
	 */
	public function testPatchWithoutOwnerDoesNotLookUpAUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->userManager->expects($this->never())->method('get');
		$this->userSession->expects($this->once())->method('setUser')->with(null);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->patch($this->webhook([], null), []);
	}//end testPatchWithoutOwnerDoesNotLookUpAUser()

	/**
	 * markUsed() stamps a UTC ISO-8601 lastUsedAt and keeps the rest of the body.
	 *
	 * @return void
	 */
	public function testMarkUsedStampsLastUsedAt(): void {
		$saved = null;
		$this->captureSave($saved);
		$this->logger->expects($this->never())->method('warning');

		$before = time();
		$this->service->markUsed($this->webhook(['agentId' => 'a1', 'enabled' => true]));
		$after = time();

		$this->assertSame('a1', $saved['object']['agentId']);
		$this->assertTrue($saved['object']['enabled']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $saved['object']['lastUsedAt']);
		$stamp = strtotime($saved['object']['lastUsedAt']);
		$this->assertGreaterThanOrEqual($before, $stamp);
		$this->assertLessThanOrEqual($after, $stamp);
	}//end testMarkUsedStampsLastUsedAt()

	/**
	 * A save failure in markUsed() becomes a warning, never an exception.
	 *
	 * @return void
	 */
	public function testMarkUsedSwallowsASaveFailure(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->equalTo('Hermiq could not record webhook lastUsedAt for hook-1: locked'));

		$this->service->markUsed($this->webhook([]));
	}//end testMarkUsedSwallowsASaveFailure()

	/**
	 * findForAgent() ignores non-entities and near-miss agent ids, and reads nothing for a blank id.
	 *
	 * @return void
	 */
	public function testFindForAgentReturnsOnlyAnExactMatch(): void {
		$other = new ObjectEntity();
		$other->setObject(['agentId' => 'agent-10']);
		$match = new ObjectEntity();
		$match->setObject(['agentId' => 'agent-1']);

		$this->objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['agentId' => 'agent-1']], false, false)
			->willReturn(['noise', $other, $match]);

		$this->assertNull($this->service->findForAgent(''));
		$this->assertSame($match, $this->service->findForAgent('agent-1'));
	}//end testFindForAgentReturnsOnlyAnExactMatch()

	/**
	 * findForAgent() returns null when nothing matches.
	 *
	 * @return void
	 */
	public function testFindForAgentReturnsNullWithoutAMatch(): void {
		$other = new ObjectEntity();
		$other->setObject(['agentId' => 'agent-2']);
		$this->objectService->method('findAll')->willReturn([$other]);

		$this->assertNull($this->service->findForAgent('agent-1'));
	}//end testFindForAgentReturnsNullWithoutAMatch()
}//end class
