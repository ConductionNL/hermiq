<?php

/**
 * Unit tests for AgentAnswerCacheController ("Clear cached answers").
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentAnswerCacheController;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Controller\AgentAnswerCacheController
 */
class AgentAnswerCacheControllerTest extends TestCase {

	private ObjectService&MockObject $objects;

	private IUserSession&MockObject $session;

	private AgentAccessServiceInterface&MockObject $access;

	private AnswerCacheService&MockObject $cache;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->access = $this->createMock(AgentAccessServiceInterface::class);
		$this->cache = $this->createMock(AnswerCacheService::class);
	}

	private function controller(): AgentAnswerCacheController {
		return new AgentAnswerCacheController(
			$this->createMock(IRequest::class),
			$this->objects,
			$this->session,
			$this->access,
			$this->cache,
			new NullLogger()
		);
	}

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}

	private function agent(string $uuid): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid($uuid);
		return $agent;
	}

	public function testTheOwnerClearsTheAgentsEntries(): void {
		$this->signIn('alice');
		$agent = $this->agent('agent-1');
		$this->objects->method('find')->willReturn($agent);
		$this->access->expects($this->once())->method('canUserModifyAgent')->with($agent, 'alice')->willReturn(true);
		$this->cache->expects($this->once())->method('clearForAgent')->with('agent-1')->willReturn(3);

		$response = $this->controller()->clear('agent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['removed' => 3], $response->getData());
	}

	public function testSomeoneElseIsRefusedAndNothingIsCleared(): void {
		$this->signIn('bob');
		$this->objects->method('find')->willReturn($this->agent('agent-1'));
		$this->access->method('canUserModifyAgent')->willReturn(false);
		$this->cache->expects($this->never())->method('clearForAgent');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->clear('agent-1')->getStatus());
	}

	public function testAnUnknownAgentIs404(): void {
		$this->signIn('alice');
		$this->objects->method('find')->willThrowException(new RuntimeException('gone'));
		$this->cache->expects($this->never())->method('clearForAgent');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->clear('agent-x')->getStatus());
	}

	public function testAnAnonymousCallerIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->objects->expects($this->never())->method('find');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->clear('agent-1')->getStatus());
	}

	public function testAFailingClearIs500(): void {
		$this->signIn('alice');
		$this->objects->method('find')->willReturn($this->agent('agent-1'));
		$this->access->method('canUserModifyAgent')->willReturn(true);
		$this->cache->method('clearForAgent')->willThrowException(new RuntimeException('db'));

		$response = $this->controller()->clear('agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to clear the cached answers'], $response->getData());
	}
}
