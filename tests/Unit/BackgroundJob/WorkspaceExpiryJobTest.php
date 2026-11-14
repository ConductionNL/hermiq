<?php

/**
 * WorkspaceExpiryJob (tools-agent-workspace-and-installed-tools, task 1).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\WorkspaceExpiryJob;
use OCA\Hermiq\Kernel\Contracts\ServerSideWorkspaceProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Only a conversation that is really gone ends its workspace.
 *
 * @covers \OCA\Hermiq\BackgroundJob\WorkspaceExpiryJob
 */
final class WorkspaceExpiryJobTest extends TestCase {

	private function job(ObjectService $objects, ?ServerSideWorkspaceProviderInterface $provider=null): WorkspaceExpiryJob {
		return new WorkspaceExpiryJob(
			time: $this->createMock(ITimeFactory::class),
			provider: ($provider ?? $this->createMock(ServerSideWorkspaceProviderInterface::class)),
			objectService: $objects,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end job()

	public function testADeletedConversationIsGone(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		self::assertTrue($this->job(objects: $objects)->conversationGone(conversationId: 'conv-1'));
	}//end testADeletedConversationIsGone()

	public function testAConversationThatFindsNothingIsGone(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(null);

		self::assertTrue($this->job(objects: $objects)->conversationGone(conversationId: 'conv-1'));
	}//end testAConversationThatFindsNothingIsGone()

	public function testAnExistingConversationIsLookedUpWithoutAccessRulesAndKept(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('find')->with(
			'conv-1',
			self::anything(),
			self::anything(),
			'hermiq',
			'agentsession',
			false,
			false
		)->willReturn(new ObjectEntity());

		self::assertFalse($this->job(objects: $objects)->conversationGone(conversationId: 'conv-1'));
	}//end testAnExistingConversationIsLookedUpWithoutAccessRulesAndKept()

	public function testAFailedLookupKeepsTheWorkspace(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new RuntimeException('database away'));

		self::assertFalse($this->job(objects: $objects)->conversationGone(conversationId: 'conv-1'));
	}//end testAFailedLookupKeepsTheWorkspace()

	public function testTheRunAsksTheProviderWithTheConversationCheck(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id): ?ObjectEntity {
				if ($id === 'conv-gone') {
					throw new DoesNotExistException('gone');
				}

				return new ObjectEntity();
			}
		);
		$verdicts = [];
		$provider = $this->createMock(ServerSideWorkspaceProviderInterface::class);
		$provider->expects(self::once())->method('reapEndedSessions')->willReturnCallback(
			static function (callable $gone) use (&$verdicts): int {
				$verdicts = ['conv-gone' => $gone('conv-gone'), 'conv-open' => $gone('conv-open')];
				return 1;
			}
		);

		$job = $this->job(objects: $objects, provider: $provider);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		self::assertSame(['conv-gone' => true, 'conv-open' => false], $verdicts);
	}//end testTheRunAsksTheProviderWithTheConversationCheck()

	public function testAFailingReapIsLoggedNotThrown(): void {
		$provider = $this->createMock(ServerSideWorkspaceProviderInterface::class);
		$provider->method('reapEndedSessions')->willThrowException(new RuntimeException('disk'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$job = new WorkspaceExpiryJob(
			time: $this->createMock(ITimeFactory::class),
			provider: $provider,
			objectService: $this->createMock(ObjectService::class),
			logger: $logger
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testAFailingReapIsLoggedNotThrown()
}//end class
