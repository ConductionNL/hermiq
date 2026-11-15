<?php

/**
 * The pre-authorisation of computer actions on one machine for one run, on the
 * real ApprovalService with a fake clock: a reviewer may widen an approved
 * computer action to its machine for its run for fifteen minutes; the record
 * names its decision maker, is valid against the register, and covers neither
 * another run, another machine nor a moment past its end.
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
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
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
 * REQ-COMPUTER-003: approve the actions on one machine for one run for at most fifteen minutes.
 */
final class ApprovalServiceComputerRunTest extends TestCase {

	private const AGENT = '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a51';

	private const DECIDED = '2026-10-20T10:00:00+00:00';

	/**
	 * Payloads the service saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * What findAll answers.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * The filters each findAll was asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $filters = [];

	public function testAnApprovedClickWidensToItsMachineForItsRunForFifteenMinutes(): void {
		$result = $this->service()->preauthoriseComputerRun(
			approval: $this->entity(uuid: 'click-1', data: $this->clickApproval()),
			deciderUid: 'carol',
			now: new DateTimeImmutable(self::DECIDED)
		);

		self::assertSame(['uuid' => 'appr-1', 'expiresAt' => '2026-10-20T10:15:00+00:00'], $result);
		$payload = $this->saved[0];
		self::assertSame('computer-run', $payload['sourceType']);
		self::assertSame('approved', $payload['status']);
		self::assertSame('carol', $payload['decidedBy']);
		self::assertSame('conversation:c-1', $payload['runId']);
		self::assertSame(['target' => 'werkplek-03'], $payload['toolArguments']);
		self::assertSame('2026-10-20T10:15:00+00:00', $payload['expiresAt']);
		self::assertTrue($this->validApproval($payload), 'The saved pre-authorisation must be valid against the register Approval schema.');
	}//end testAnApprovedClickWidensToItsMachineForItsRunForFifteenMinutes()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function notWidenable(): array {
		return [
			'still pending' => [['status' => 'pending']],
			'not a computer action' => [['toolId' => 'hermiq.computerScreenshot']],
			'another source' => [['sourceType' => 'workspace-run']],
			'no run' => [['runId' => '']],
			'no machine' => [['toolArguments' => ['x' => 1]]],
		];
	}//end notWidenable()

	/**
	 * @param array<string, mixed> $change What differs from an approved click.
	 *
	 * @dataProvider notWidenable
	 */
	public function testOnlyAnApprovedComputerActionOfARunOnAMachineCanBeWidened(array $change): void {
		$result = $this->service()->preauthoriseComputerRun(
			approval: $this->entity(uuid: 'click-1', data: array_merge($this->clickApproval(), $change)),
			deciderUid: 'carol',
			now: new DateTimeImmutable(self::DECIDED)
		);

		self::assertNull($result);
		self::assertSame([], $this->saved);
	}//end testOnlyAnApprovedComputerActionOfARunOnAMachineCanBeWidened()

	public function testThePreAuthorisationCoversItsRunAndMachineUntilItEnds(): void {
		$service = $this->service();
		$service->preauthoriseComputerRun(approval: $this->entity(uuid: 'click-1', data: $this->clickApproval()), deciderUid: 'carol', now: new DateTimeImmutable(self::DECIDED));
		$this->stored = [$this->entity(uuid: 'pre-1', data: $this->saved[0])];

		$at = static fn (string $moment): DateTimeImmutable => new DateTimeImmutable($moment);

		self::assertSame(
			['uuid' => 'pre-1', 'decidedBy' => 'carol', 'expiresAt' => '2026-10-20T10:15:00+00:00'],
			$service->computerRunPreAuthorisation(agentId: self::AGENT, runId: 'conversation:c-1', target: 'werkplek-03', now: $at('2026-10-20T10:14:59+00:00'))
		);
		self::assertSame(['correlationId' => $this->saved[0]['correlationId'], 'sourceType' => 'computer-run'], $this->filters[0]);

		// Sixteen minutes later a new approval is needed.
		self::assertNull($service->computerRunPreAuthorisation(agentId: self::AGENT, runId: 'conversation:c-1', target: 'werkplek-03', now: $at('2026-10-20T10:16:00+00:00')));
		self::assertNull($service->computerRunPreAuthorisation(agentId: self::AGENT, runId: 'conversation:c-1', target: 'werkplek-03', now: $at('2026-10-20T10:15:00+00:00')));
	}//end testThePreAuthorisationCoversItsRunAndMachineUntilItEnds()

	public function testAnotherRunOrMachineIsAskedUnderItsOwnKey(): void {
		$service = $this->service();
		$service->preauthoriseComputerRun(approval: $this->entity(uuid: 'click-1', data: $this->clickApproval()), deciderUid: 'carol', now: new DateTimeImmutable(self::DECIDED));
		$key = (string)$this->saved[0]['correlationId'];
		$now = new DateTimeImmutable('2026-10-20T10:05:00+00:00');

		$service->computerRunPreAuthorisation(agentId: self::AGENT, runId: 'conversation:c-2', target: 'werkplek-03', now: $now);
		$service->computerRunPreAuthorisation(agentId: self::AGENT, runId: 'conversation:c-1', target: 'werkplek-04', now: $now);

		self::assertNotSame($key, $this->filters[0]['correlationId']);
		self::assertNotSame($key, $this->filters[1]['correlationId']);
		self::assertNotSame($this->filters[0]['correlationId'], $this->filters[1]['correlationId']);
	}//end testAnotherRunOrMachineIsAskedUnderItsOwnKey()

	public function testAnUnsignedOrUnapprovedRecordCoversNothing(): void {
		$service = $this->service();
		$service->preauthoriseComputerRun(approval: $this->entity(uuid: 'click-1', data: $this->clickApproval()), deciderUid: 'carol', now: new DateTimeImmutable(self::DECIDED));
		$record = $this->saved[0];
		$this->stored = [
			$this->entity(uuid: 'unsigned', data: array_merge($record, ['decidedBy' => ''])),
			$this->entity(uuid: 'denied', data: array_merge($record, ['status' => 'denied'])),
			$this->entity(uuid: 'no-end', data: array_merge($record, ['expiresAt' => ''])),
		];

		self::assertNull($service->computerRunPreAuthorisation(agentId: self::AGENT, runId: 'conversation:c-1', target: 'werkplek-03', now: new DateTimeImmutable('2026-10-20T10:01:00+00:00')));
		self::assertNull($service->computerRunPreAuthorisation(agentId: self::AGENT, runId: '', target: 'werkplek-03', now: new DateTimeImmutable('2026-10-20T10:01:00+00:00')));
	}//end testAnUnsignedOrUnapprovedRecordCoversNothing()

	public function testAComputerActionApprovalRecordsItsRun(): void {
		$this->service()->ensurePendingApprovalForToolCall(
			agentId: self::AGENT,
			toolId: 'hermiq.computerClick',
			arguments: ['target' => 'werkplek-03', 'x' => 10, 'y' => 20],
			correlationId: 'corr-1',
			runId: 'conversation:c-1'
		);
		$this->service()->ensurePendingApprovalForToolCall(agentId: self::AGENT, toolId: 'hermiq.other', arguments: [], correlationId: 'corr-2');

		self::assertSame('conversation:c-1', $this->saved[0]['runId']);
		self::assertArrayNotHasKey('runId', $this->saved[1]);
	}//end testAComputerActionApprovalRecordsItsRun()

	/**
	 * An approved click on werkplek-03 in conversation c-1.
	 *
	 * @return array<string, mixed>
	 */
	private function clickApproval(): array {
		return [
			'status' => 'approved',
			'sourceType' => 'toolcall',
			'agentId' => self::AGENT,
			'toolId' => 'hermiq.computerClick',
			'toolArguments' => ['target' => 'werkplek-03', 'x' => 412, 'y' => 230],
			'runId' => 'conversation:c-1',
			'reviewer' => 'alice',
			'reviewerType' => 'user',
		];
	}//end clickApproval()

	/**
	 * The real ApprovalService over a recording ObjectService.
	 *
	 * @param string $agentOwner The agent's owner ('' for none).
	 *
	 * @return ApprovalService
	 */
	private function service(string $agentOwner = 'alice'): ApprovalService {
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
