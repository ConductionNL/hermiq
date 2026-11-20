<?php

/**
 * Unit tests for recording the serving answer-cache entry on a stored answer
 * (models-prompt-and-answer-cache).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An answer served from the cache carries `answerCache: {layer, entryId}`, so a
 * thumbs down can find the entry; an answer the model gave carries none.
 *
 * @covers \OCA\Hermiq\Service\Engine\MessageHistoryHandler
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */
final class MessageAnswerCacheRecordTest extends TestCase {

	/**
	 * A cached answer records its layer and entry id, and nothing else from the hit.
	 *
	 * @return void
	 */
	public function testACachedAnswerRecordsItsEntry(): void {
		$saved = $this->store(answerCache: ['layer' => 'exact', 'entryId' => 'entry-7', 'similarity' => 1.0]);

		$this->assertSame(['layer' => 'exact', 'entryId' => 'entry-7'], $saved['answerCache']);
	}//end testACachedAnswerRecordsItsEntry()

	/**
	 * An answer the model gave, or a hit without an entry id, records nothing.
	 *
	 * @return void
	 */
	public function testNoEntryIsRecordedWithoutOne(): void {
		$this->assertArrayNotHasKey('answerCache', $this->store(answerCache: null));
		$this->assertArrayNotHasKey('answerCache', $this->store(answerCache: ['layer' => 'exact', 'entryId' => '']));
		$this->assertArrayNotHasKey('answerCache', $this->store(answerCache: ['layer' => 'exact']));
	}//end testNoEntryIsRecordedWithoutOne()

	/**
	 * A missing layer is stored as empty, the entry id kept.
	 *
	 * @return void
	 */
	public function testAMissingLayerIsEmpty(): void {
		$this->assertSame(['layer' => '', 'entryId' => 'e'], $this->store(answerCache: ['entryId' => 'e'])['answerCache']);
	}//end testAMissingLayerIsEmpty()

	/**
	 * Store one assistant answer and return the saved payload.
	 *
	 * @param array<string, mixed>|null $answerCache The serving entry, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function store(?array $answerCache): array {
		$saved = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): ObjectEntity {
				$saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('msg-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		(new MessageHistoryHandler($objects, new NullLogger()))->storeMessage(
			conversationId: 'conv-1',
			role: 'assistant',
			content: 'Op maandag.',
			answerCache: $answerCache
		);

		return $saved;
	}//end store()
}//end class
