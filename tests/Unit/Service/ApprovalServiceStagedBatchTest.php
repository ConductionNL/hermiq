<?php

/**
 * A batch integriq staged, on the real ApprovalService: the Approval it saves
 * is valid against the real register fragment, stores the binding Hermiq
 * later compares, and is raised once per batch (approval-verification-contract).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service
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

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-a-staged-batch-raises-an-approval-that-keeps-its-binding-req-apver-002
 */
final class ApprovalServiceStagedBatchTest extends TestCase {

	private const AGENT = '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51';

	private const BINDING = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/**
	 * Payloads handed to ObjectService::saveObject, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * What findAll returns for the Approval schema.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * The filters findAll was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $filters = [];

	/**
	 * The staged batch becomes a valid pending toolcall Approval that stores the
	 * binding and the proposal, and goes to the agent owner.
	 *
	 * @return void
	 */
	public function testAStagedBatchIsAValidPendingApprovalWithItsBinding(): void {
		$approval = $this->service(agentOwner: 'alice')->ensurePendingApprovalForStagedBatch(
			agentId: self::AGENT,
			toolId: 'integriq.replayDeadLetters',
			proposalId: 'p1',
			binding: self::BINDING,
			targetIds: ['d2', 'd1']
		);

		self::assertSame('appr-1', $approval->getUuid());
		self::assertCount(1, $this->saved);
		$payload = $this->saved[0];
		self::assertSame('pending', $payload['status']);
		self::assertSame('toolcall', $payload['sourceType']);
		self::assertSame(self::AGENT, $payload['agentId']);
		self::assertSame('integriq.replayDeadLetters', $payload['toolId']);
		self::assertSame(self::BINDING, $payload['binding']);
		self::assertSame(['proposalId' => 'p1', 'targetIds' => ['d2', 'd1']], $payload['toolArguments']);
		self::assertSame('alice', $payload['reviewer']);
		self::assertStringContainsString('2', $payload['prompt']);
		self::assertTrue($this->validApproval($payload), 'The saved payload must be valid against the register Approval schema.');
	}//end testAStagedBatchIsAValidPendingApprovalWithItsBinding()

	/**
	 * Staging the same batch again returns the pending approval, and writes nothing.
	 *
	 * @return void
	 */
	public function testTheSameBatchIsRaisedOnce(): void {
		$service = $this->service(agentOwner: 'alice');
		$service->ensurePendingApprovalForStagedBatch(agentId: self::AGENT, toolId: 'integriq.replayDeadLetters', proposalId: 'p1', binding: self::BINDING, targetIds: ['d1']);
		$key          = (string)$this->saved[0]['correlationId'];
		$this->stored = [$this->entity(uuid: 'a-pending', data: ['status' => 'pending', 'sourceType' => 'toolcall', 'correlationId' => $key])];

		$again = $service->ensurePendingApprovalForStagedBatch(agentId: self::AGENT, toolId: 'integriq.replayDeadLetters', proposalId: 'p1', binding: self::BINDING, targetIds: ['d1']);

		self::assertSame('a-pending', $again->getUuid());
		self::assertCount(1, $this->saved);
		self::assertSame(['correlationId' => $key, 'sourceType' => 'toolcall'], $this->filters[array_key_last($this->filters)]);
	}//end testTheSameBatchIsRaisedOnce()

	/**
	 * Another binding, tool or agent is another approval (another key).
	 *
	 * @return void
	 */
	public function testAnotherBatchHasAnotherKey(): void {
		$service = $this->service(agentOwner: 'alice');
		$service->ensurePendingApprovalForStagedBatch(agentId: self::AGENT, toolId: 'integriq.replayDeadLetters', proposalId: 'p1', binding: self::BINDING, targetIds: ['d1']);
		$service->ensurePendingApprovalForStagedBatch(agentId: self::AGENT, toolId: 'integriq.replayDeadLetters', proposalId: 'p2', binding: str_repeat('c', 64), targetIds: ['d1']);
		$service->ensurePendingApprovalForStagedBatch(agentId: self::AGENT, toolId: 'integriq.discardDeadLetters', proposalId: 'p1', binding: self::BINDING, targetIds: ['d1']);
		$service->ensurePendingApprovalForStagedBatch(agentId: 'other-agent', toolId: 'integriq.replayDeadLetters', proposalId: 'p1', binding: self::BINDING, targetIds: ['d1']);

		self::assertCount(4, array_unique(array_column($this->saved, 'correlationId')));
	}//end testAnotherBatchHasAnotherKey()

	/**
	 * The real ApprovalService over a recording ObjectService.
	 *
	 * @param string $agentOwner The agent's owner ('' for none).
	 *
	 * @return ApprovalService
	 */
	private function service(string $agentOwner): ApprovalService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('find')->willReturn($this->entity(uuid: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51', data: ['name' => 'Coder'], owner: $agentOwner));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return $this->entity(uuid: 'appr-' . count($this->saved), data: $object);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->filters[] = ($config['filters'] ?? []);
				return $this->stored;
			}
		);

		$delivery = $this->createMock(DeliveryService::class);
		$delivery->method('deliverApprovalRequestForToolInvocation')->willReturn(
			new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null)
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$bridge = $this->createMock(ApprovalTaskBridge::class);
		$bridge->method('ensureTaskFor')->willReturn(null);

		return new ApprovalService(
			$objects,
			$this->createMock(IUserSession::class),
			$users,
			$this->createMock(IGroupManager::class),
			$delivery,
			$this->createMock(AuditTrailMapper::class),
			new RedactionService($this->createMock(IConfig::class)),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$bridge,
		);
	}//end service()

	/**
	 * An ObjectEntity.
	 *
	 * @param string               $uuid  The uuid.
	 * @param array<string, mixed> $data  The object.
	 * @param string               $owner The owner.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data, string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Validate a payload against the register's Approval schema (slug `$ref`s removed).
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function validApproval(array $payload): bool {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->Approval;
		$this->stripSlugRefs(node: $schema);
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $schema)->isValid();
	}//end validApproval()

	/**
	 * Remove every `$ref` that is not a JSON pointer, recursively.
	 *
	 * @param mixed $node A schema node.
	 *
	 * @return void
	 */
	private function stripSlugRefs(mixed $node): void {
		if (is_object($node) === false && is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $child) {
			if ($key === '$ref' && is_string($child) === true && str_starts_with($child, '#') === false) {
				unset($node->{'$ref'});
				continue;
			}

			$this->stripSlugRefs(node: $child);
		}
	}//end stripSlugRefs()
}//end class
