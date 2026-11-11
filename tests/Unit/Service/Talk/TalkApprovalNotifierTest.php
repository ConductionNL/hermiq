<?php

/**
 * TalkApprovalNotifier: posting an approval request into the agent's room.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-an-approval-request-posted-to-talk-records-where-it-landed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkApprovalBinding;
use OCA\Hermiq\Service\Talk\TalkApprovalNotifier;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Where the request goes, under whose bot, how it is bound, and every way it stays best-effort.
 */
final class TalkApprovalNotifierTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $posted = [];

	/** @var array<int, array{0: ObjectEntity, 1: string, 2: string}> */
	private array $bound = [];

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	private array $warned = [];

	/**
	 * The notifier over recording doubles.
	 *
	 * @param bool         $available Whether Talk is available.
	 * @param string|null  $room      The agent's room, or null.
	 * @param mixed        $messageId What posting returns, or a Throwable to throw.
	 *
	 * @return TalkApprovalNotifier
	 */
	private function notifier(bool $available = true, ?string $room = 'room-1', mixed $messageId = 'msg-9'): TalkApprovalNotifier {
		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn($available);
		$bridge->method('postToRoomReturningId')->willReturnCallback(
			function (string $roomToken, string $message, ?string $agentId = null) use ($messageId): ?string {
				$this->posted[] = ['roomToken' => $roomToken, 'message' => $message, 'agentId' => $agentId];
				if ($messageId instanceof \Throwable) {
					throw $messageId;
				}

				return $messageId;
			}
		);
		$agents = $this->createMock(TalkAgentBinding::class);
		$agents->method('roomForAgent')->willReturn($room);
		$approvals = $this->createMock(TalkApprovalBinding::class);
		$approvals->method('bind')->willReturnCallback(
			function (ObjectEntity $approval, string $roomToken, string $messageId): bool {
				$this->bound[] = [$approval, $roomToken, $messageId];
				return true;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warned[] = [(string)$message, $context];
			}
		);

		return new TalkApprovalNotifier(bridge: $bridge, agentBinding: $agents, approvalBinding: $approvals, logger: $logger);
	}//end notifier()

	/**
	 * An approval of an agent.
	 *
	 * @param string $agentId The agent id.
	 *
	 * @return ObjectEntity
	 */
	private function approval(string $agentId = 'agent-1'): ObjectEntity {
		$approval = new ObjectEntity();
		$approval->setObject(['agentId' => $agentId]);
		return $approval;
	}//end approval()

	/**
	 * The request is posted under the agent's own bot in its room, and bound to the message.
	 *
	 * @return void
	 */
	public function testTheRequestIsPostedUnderTheAgentsBotAndBound(): void {
		$approval = $this->approval();

		self::assertTrue($this->notifier()->postRequest(approval: $approval, displayName: 'Send the letter'));

		self::assertCount(1, $this->posted);
		self::assertSame('room-1', $this->posted[0]['roomToken']);
		self::assertSame('agent-1', $this->posted[0]['agentId']);
		self::assertStringStartsWith('⏸️ **Approval needed** for ', $this->posted[0]['message']);
		self::assertStringContainsString('Send the letter', $this->posted[0]['message']);
		self::assertStringEndsWith("React 👍 to approve or 👎 to deny. Only the reviewer's reaction counts, and a decision cannot be undone.", $this->posted[0]['message']);
		self::assertSame([[$approval, 'room-1', 'msg-9']], $this->bound);
		self::assertSame([], $this->warned);
	}//end testTheRequestIsPostedUnderTheAgentsBotAndBound()

	/**
	 * No Talk, no agent, no room, or no message id: false, nothing bound, nothing warned.
	 *
	 * @return void
	 */
	public function testEveryMissingPieceIsAQuietFalse(): void {
		self::assertFalse($this->notifier(available: false)->postRequest(approval: $this->approval(), displayName: 'x'));
		self::assertFalse($this->notifier()->postRequest(approval: $this->approval(agentId: ''), displayName: 'x'));
		self::assertFalse($this->notifier(room: null)->postRequest(approval: $this->approval(), displayName: 'x'));
		self::assertSame([], $this->posted);

		self::assertFalse($this->notifier(messageId: null)->postRequest(approval: $this->approval(), displayName: 'x'));
		self::assertCount(1, $this->posted);
		self::assertSame([], $this->bound);
		self::assertSame([], $this->warned);
	}//end testEveryMissingPieceIsAQuietFalse()

	/**
	 * A throwing post is a warned false; the approval is unaffected.
	 *
	 * @return void
	 */
	public function testAFailingPostIsWarnedAndFalse(): void {
		self::assertFalse($this->notifier(messageId: new RuntimeException('talk down'))->postRequest(approval: $this->approval(), displayName: 'x'));

		self::assertSame('[TalkApprovalNotifier] Could not post the approval request to Talk (the approval is unaffected)', $this->warned[0][0]);
		self::assertSame(['file', 'line', 'error'], array_keys($this->warned[0][1]));
		self::assertSame('talk down', $this->warned[0][1]['error']);
		self::assertSame([], $this->bound);
	}//end testAFailingPostIsWarnedAndFalse()
}//end class
