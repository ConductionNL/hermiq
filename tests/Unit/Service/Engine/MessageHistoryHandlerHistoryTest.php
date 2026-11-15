<?php

/**
 * Characterisation tests for how MessageHistoryHandler turns stored turns into
 * the model's history.
 *
 * Pins which turns are skipped (and the warning each skip logs), the role mapping,
 * when speaker labels are added, and the attachment lines, so the conversion can be
 * split into named steps without changing what the model reads.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Characterisation tests for the MessageHistoryHandler history conversion.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class MessageHistoryHandlerHistoryTest extends TestCase {

	/**
	 * Logged warning/info lines with their context minus file and line.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logLines = [];

	/**
	 * A handler whose store returns the given payloads (newest first, as findAll does).
	 *
	 * @param array<int, array<string, mixed>> $newestFirst The stored turns.
	 *
	 * @return MessageHistoryHandler
	 */
	private function handler(array $newestFirst): MessageHistoryHandler {
		$entities = [];
		foreach ($newestFirst as $index => $payload) {
			$entity = new ObjectEntity();
			$entity->setUuid('m-' . $index);
			$entity->setObject($payload);
			$entities[] = $entity;
		}

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($entities);

		$lines = &$this->logLines;
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, array<int, mixed>> $lines The shared line store.
			 */
			public function __construct(private array &$lines) {
			}//end __construct()

			/**
			 * Record warning and info lines.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				if ($level === 'debug') {
					return;
				}

				unset($context['file'], $context['line']);
				$this->lines[] = [$level, (string)$message, $context];
			}//end log()
		};

		return new MessageHistoryHandler($objectService, $logger);
	}//end handler()

	/**
	 * The history as role/content pairs.
	 *
	 * @param array<int, LLPhantMessage> $history The built history.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function pairs(array $history): array {
		return array_map(
			static fn (LLPhantMessage $message): array => [$message->role->value, $message->content],
			$history
		);
	}//end pairs()

	/**
	 * Turns without a role or content, or with an unknown role, are skipped with a
	 * warning each; the rest map to their role, with attachment lines on user turns.
	 *
	 * @return void
	 */
	public function testSkipsAndMapsEveryTurnShape(): void {
		$handler = $this->handler(
			[
				['role' => 'system', 'content' => 'Be brief.'],
				['role' => 'user', 'content' => 'Look', 'attachments' => [['fileId' => 7, 'name' => 'a.pdf'], 'x', ['name' => 'no id'], ['fileId' => '9']]],
				['role' => 'user', 'content' => 'Plain', 'attachments' => 'not-a-list'],
				['role' => 'tool', 'content' => 'raw'],
				['role' => 'assistant', 'content' => ''],
				['content' => 'orphan'],
				['role' => 'assistant', 'content' => 'Hello'],
				['role' => 'user', 'content' => 'Hi', 'attachments' => [['name' => 'none']]],
			]
		);

		$history = $handler->buildMessageHistory(conversationId: 'conv-1');

		$this->assertSame(
			[
				['user', 'Hi'],
				['assistant', 'Hello'],
				['user', 'Plain'],
				['user', "Look\n\nAttached earlier: a.pdf, file 7\nAttached earlier: , file 9"],
				['system', 'Be brief.'],
			],
			$this->pairs($history)
		);
		$this->assertSame(
			[
				['warning', '[MessageHistoryHandler] Skipping message with missing role or content', ['hasRole' => false, 'hasContent' => true]],
				['warning', '[MessageHistoryHandler] Skipping message with missing role or content', ['hasRole' => true, 'hasContent' => false]],
				['warning', '[MessageHistoryHandler] Unknown message role', ['role' => 'tool']],
				['info', '[MessageHistoryHandler] Message history built', ['historyCount' => 5]],
			],
			$this->logLines
		);

	}//end testSkipsAndMapsEveryTurnShape()

	/**
	 * One human author (or none) keeps the turns unlabelled; ignored are assistant
	 * authors and empty or non-string author ids.
	 *
	 * @return void
	 */
	public function testASingleSpeakerSessionIsNotLabelled(): void {
		$handler = $this->handler(
			[
				['role' => 'user', 'content' => 'Two', 'authorId' => 'alice', 'authorDisplayName' => 'Alice'],
				['role' => 'assistant', 'content' => 'Ok', 'authorId' => 'bot'],
				['role' => 'user', 'content' => 'One', 'authorId' => 'alice'],
				['role' => 'user', 'content' => 'Zero', 'authorId' => ''],
				['role' => 'user', 'content' => 'Num', 'authorId' => 5],
			]
		);

		$this->assertSame(
			[['user', 'Num'], ['user', 'Zero'], ['user', 'One'], ['assistant', 'Ok'], ['user', 'Two']],
			$this->pairs($handler->buildMessageHistory(conversationId: 'conv-1'))
		);

	}//end testASingleSpeakerSessionIsNotLabelled()

	/**
	 * Two human authors label every human turn: the display name, else the uid, else
	 * nothing; the label comes before the attachment lines.
	 *
	 * @return void
	 */
	public function testASharedSessionLabelsHumanTurns(): void {
		$handler = $this->handler(
			[
				['role' => 'user', 'content' => 'Anon'],
				['role' => 'user', 'content' => 'From bob', 'authorId' => 'bob', 'attachments' => [['fileId' => 3, 'name' => 'b.txt']]],
				['role' => 'assistant', 'content' => 'Sure'],
				['role' => 'user', 'content' => 'From alice', 'authorId' => 'alice', 'authorDisplayName' => 'Alice A.'],
			]
		);

		$this->assertSame(
			[
				['user', 'Alice A.: From alice'],
				['assistant', 'Sure'],
				['user', "bob: From bob\n\nAttached earlier: b.txt, file 3"],
				['user', 'Anon'],
			],
			$this->pairs($handler->buildMessageHistory(conversationId: 'conv-1'))
		);

	}//end testASharedSessionLabelsHumanTurns()
}//end class
