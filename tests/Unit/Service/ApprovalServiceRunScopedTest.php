<?php

/**
 * The run-scoped pre-authorisation of governed workspace writes, on the real
 * ApprovalService: the Approval it saves is valid against the real register
 * fragment, pins the run, repository and ref, is routed to the agent owner,
 * and only an approved decision by a named person for THIS run counts.
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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
 */
final class ApprovalServiceRunScopedTest extends TestCase {

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
	 * The requested pre-authorisation is a valid Approval that pins run, repository and ref and goes to the agent owner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
	 */
	public function testTheRequestIsAValidApprovalPinningRunRepositoryAndRef(): void {
		$result = $this->service(agentOwner: 'alice')->requestRunPreAuthorisation(
			agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51',
			runId: 'run-1',
			toolId: 'hermiq.workspaceWriteFile',
			repository: 'example-org/example-app',
			ref: 'development'
		);

		self::assertSame('pending', $result['status']);
		self::assertCount(1, $this->saved);
		$payload = $this->saved[0];
		self::assertSame('workspace-run', $payload['sourceType']);
		self::assertSame(['runId' => 'run-1', 'repository' => 'example-org/example-app', 'ref' => 'development'], $payload['toolArguments']);
		self::assertSame('alice', $payload['reviewer']);
		self::assertSame('user', $payload['reviewerType']);
		self::assertTrue($this->validApproval($payload), 'The saved payload must be valid against the register Approval schema.');
	}//end testTheRequestIsAValidApprovalPinningRunRepositoryAndRef()

	/**
	 * An agent without an owner routes the request to the admin group, still a valid Approval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
	 */
	public function testAnUnownedAgentAsksTheAdminGroup(): void {
		$this->service(agentOwner: '')->requestRunPreAuthorisation(
			agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51',
			runId: 'run-1',
			toolId: 'hermiq.workspaceCommit',
			repository: 'example-org/example-app',
			ref: 'main'
		);

		self::assertSame('admin', $this->saved[0]['reviewer']);
		self::assertSame('group', $this->saved[0]['reviewerType']);
		self::assertTrue($this->validApproval($this->saved[0]));
	}//end testAnUnownedAgentAsksTheAdminGroup()

	/**
	 * An approved decision names its human decider and wins over a later pending re-request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-run-scoped-pre-authorisation-covers-the-run-and-nothing-else
	 */
	public function testAnApprovedDecisionNamesItsDeciderAndWins(): void {
		$service = $this->service(agentOwner: 'alice');
		$service->requestRunPreAuthorisation(agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51', runId: 'run-1', toolId: 'hermiq.workspaceWriteFile', repository: 'o/r', ref: 'main');
		$key = (string)$this->saved[0]['correlationId'];
		$this->stored = [
			$this->entity(uuid: 'a-approved', data: ['status' => 'approved', 'sourceType' => 'workspace-run', 'correlationId' => $key, 'decidedBy' => 'carol']),
			$this->entity(uuid: 'a-pending', data: ['status' => 'pending', 'sourceType' => 'workspace-run', 'correlationId' => $key, 'decidedBy' => null]),
		];

		self::assertSame(['uuid' => 'a-approved', 'status' => 'approved', 'decidedBy' => 'carol'], $service->runPreAuthorisation(agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51', runId: 'run-1'));
		self::assertSame(['correlationId' => $key, 'sourceType' => 'workspace-run'], $this->filters[0]);
	}//end testAnApprovedDecisionNamesItsDeciderAndWins()

	/**
	 * Another run of the same agent has another key, so the first run's approval does not cover it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-run-scoped-pre-authorisation-covers-the-run-and-nothing-else
	 */
	public function testAnotherRunIsNotCovered(): void {
		$service = $this->service(agentOwner: 'alice');
		$service->requestRunPreAuthorisation(agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51', runId: 'run-1', toolId: 'hermiq.workspaceWriteFile', repository: 'o/r', ref: 'main');
		$key = (string)$this->saved[0]['correlationId'];
		// Even if the store ignored the filter, the service must not accept another run's record.
		$this->stored = [
			$this->entity(uuid: 'a-approved', data: ['status' => 'approved', 'sourceType' => 'workspace-run', 'correlationId' => $key, 'decidedBy' => 'carol']),
		];

		self::assertNull($service->runPreAuthorisation(agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51', runId: 'run-2'));
		self::assertNull($service->runPreAuthorisation(agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a52', runId: 'run-1'));
		self::assertNotNull($service->runPreAuthorisation(agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51', runId: 'run-1'));
	}//end testAnotherRunIsNotCovered()

	/**
	 * A denied decision outranks a pending re-request; a tool-call approval with the same key is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-an-unapproved-write-is-refused-before-it-happens
	 */
	public function testADenialOutranksAPendingRequestAndOtherSourcesDoNotCount(): void {
		$service = $this->service(agentOwner: 'alice');
		$service->requestRunPreAuthorisation(agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51', runId: 'run-1', toolId: 'hermiq.workspaceWriteFile', repository: 'o/r', ref: 'main');
		$key = (string)$this->saved[0]['correlationId'];
		$this->stored = [
			$this->entity(uuid: 'a-pending', data: ['status' => 'pending', 'sourceType' => 'workspace-run', 'correlationId' => $key]),
			$this->entity(uuid: 'a-denied', data: ['status' => 'denied', 'sourceType' => 'workspace-run', 'correlationId' => $key, 'decidedBy' => 'carol']),
			$this->entity(uuid: 'a-tool', data: ['status' => 'approved', 'sourceType' => 'toolcall', 'correlationId' => $key, 'decidedBy' => 'mallory']),
		];

		self::assertSame('a-denied', $service->runPreAuthorisation(agentId: '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51', runId: 'run-1')['uuid'] ?? null);
	}//end testADenialOutranksAPendingRequestAndOtherSourcesDoNotCount()

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
