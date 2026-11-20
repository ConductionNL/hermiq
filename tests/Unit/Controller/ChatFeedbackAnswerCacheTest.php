<?php

/**
 * Unit tests for the thumbs-down hook on cached answers (models-prompt-and-answer-cache).
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatController;
use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A thumbs down on an answer served from the cache deletes that entry; a
 * thumbs up, or a thumbs down on an answer the model gave, deletes nothing.
 *
 * @covers \OCA\Hermiq\Controller\ChatController
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */
final class ChatFeedbackAnswerCacheTest extends TestCase {

	/**
	 * The request carrying the feedback.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * The answer cache.
	 *
	 * @var AnswerCacheService&MockObject
	 */
	private AnswerCacheService $cache;

	/**
	 * Mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([]);
		$this->objects->method('saveObject')->willReturnCallback(
			static function (mixed $object): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('fb-1');
				$entity->setObject($object);
				return $entity;
			}
		);
		$this->cache = $this->createMock(AnswerCacheService::class);
	}//end setUp()

	/**
	 * A thumbs down on a cached answer deletes the entry that served it.
	 *
	 * @return void
	 */
	public function testAThumbsDownOnACachedAnswerDeletesItsEntry(): void {
		$this->rate(type: 'negative', message: ['answerCache' => ['layer' => 'exact', 'entryId' => 'entry-7']]);
		$this->cache->expects($this->once())->method('remove')->with('entry-7')->willReturn(true);

		$response = $this->controller()->sendFeedback('conv-1', 'msg-1');

		$this->assertSame(200, $response->getStatus());
	}//end testAThumbsDownOnACachedAnswerDeletesItsEntry()

	/**
	 * A thumbs up keeps the entry.
	 *
	 * @return void
	 */
	public function testAThumbsUpKeepsTheEntry(): void {
		$this->rate(type: 'positive', message: ['answerCache' => ['layer' => 'exact', 'entryId' => 'entry-7']]);
		$this->cache->expects($this->never())->method('remove');

		$this->assertSame(200, $this->controller()->sendFeedback('conv-1', 'msg-1')->getStatus());
	}//end testAThumbsUpKeepsTheEntry()

	/**
	 * A thumbs down on an answer the model gave, or one with a broken record, deletes nothing.
	 *
	 * @return void
	 */
	public function testAThumbsDownWithoutAServingEntryDeletesNothing(): void {
		$this->cache->expects($this->never())->method('remove');

		foreach ([[], ['answerCache' => 'entry-7'], ['answerCache' => ['layer' => 'exact', 'entryId' => '']]] as $message) {
			$this->rate(type: 'negative', message: $message);
			$this->assertSame(200, $this->controller()->sendFeedback('conv-1', 'msg-1')->getStatus());
		}
	}//end testAThumbsDownWithoutAServingEntryDeletesNothing()

	/**
	 * A refused rating deletes nothing.
	 *
	 * @return void
	 */
	public function testARatingOfAnotherConversationsMessageDeletesNothing(): void {
		$this->rate(type: 'negative', message: ['answerCache' => ['entryId' => 'entry-7']], sessionId: 'conv-OTHER');
		$this->cache->expects($this->never())->method('remove');

		$this->assertSame(404, $this->controller()->sendFeedback('conv-1', 'msg-1')->getStatus());
	}//end testARatingOfAnotherConversationsMessageDeletesNothing()

	/**
	 * Without the cache service the feedback is still saved.
	 *
	 * @return void
	 */
	public function testWithoutTheCacheTheFeedbackIsStillSaved(): void {
		$this->rate(type: 'negative', message: ['answerCache' => ['entryId' => 'entry-7']]);

		$this->assertSame(200, $this->controller(withCache: false)->sendFeedback('conv-1', 'msg-1')->getStatus());
	}//end testWithoutTheCacheTheFeedbackIsStillSaved()

	/**
	 * Stub a rating of msg-1 in conv-1, owned by alice.
	 *
	 * @param string               $type      The feedback type.
	 * @param array<string, mixed> $message   The rated message's extra fields.
	 * @param string               $sessionId The conversation the message belongs to.
	 *
	 * @return void
	 */
	private function rate(string $type, array $message, string $sessionId='conv-1'): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (['type' => $type, 'comment' => ''][$key] ?? $default)
		);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([]);
		$this->objects->method('saveObject')->willReturnCallback(
			static function (mixed $object): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('fb-1');
				$entity->setObject($object);
				return $entity;
			}
		);
		$this->objects->method('find')->willReturnCallback(
			static function (int|string $id, ?ObjectFindOptions $options = null) use ($message, $sessionId): ObjectEntity {
				$entity = new ObjectEntity();
				if ($options?->schema === 'agentsession') {
					$entity->setUuid('conv-1');
					$entity->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
					return $entity;
				}

				$entity->setUuid('msg-1');
				$entity->setObject(['sessionId' => $sessionId, 'role' => 'assistant'] + $message);
				return $entity;
			}
		);
	}//end rate()

	/**
	 * The controller under test.
	 *
	 * @param bool $withCache Whether the answer cache is wired.
	 *
	 * @return ChatController
	 */
	private function controller(bool $withCache=true): ChatController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ChatController(
			request: $this->request,
			engine: $this->createMock(Engine::class),
			objectService: $this->objects,
			userSession: $session,
			l10n: $l10n,
			runStepBus: $this->createMock(RunStepBus::class),
			providerFactory: $this->createMock(ProviderFactory::class),
			accessRequests: $this->createMock(ToolAccessRequestService::class),
			logger: new NullLogger(),
			answerCache: ($withCache === true) ? $this->cache : null
		);
	}//end controller()
}//end class
