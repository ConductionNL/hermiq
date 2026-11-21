<?php

/**
 * compliance-ai-literacy on the Talk path: a speaker who must finish the course
 * first is told so in the room, and the engine is never called.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Literacy\LiteracyRequiredException;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkTurnService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Talk turn refuses before the engine.
 *
 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
 */
final class TalkTurnLiteracyTest extends TestCase {

	/**
	 * The course message reaches the room; the engine is not called.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function testASpeakerWhoSkippedTheCourseIsToldInTheRoom(): void {
		$engine = $this->createMock(Engine::class);
		$engine->expects($this->never())->method('processMessage');
		$posted = [];
		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('postToRoom')->willReturnCallback(
			function (string $roomToken, string $message) use (&$posted): bool {
				$posted[] = $message;
				return true;
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$literacy = $this->createMock(LiteracyRequirement::class);
		$literacy->method('assertMayUseAgents')->with('alice')->willThrowException(new LiteracyRequiredException());

		$service = new TalkTurnService(
			$engine,
			$bridge,
			$this->createMock(TalkRoomBinding::class),
			$users,
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$literacy
		);

		$this->assertFalse($service->runTurn('conv-1', 'alice', 'hallo', 'room1'));
		$this->assertCount(1, $posted);
		$this->assertStringContainsString('Finish the short course Working with AI first.', $posted[0]);

	}//end testASpeakerWhoSkippedTheCourseIsToldInTheRoom()

}//end class
