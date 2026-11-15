<?php

/**
 * Golden test for the trigger of ConversationManagementHandler::checkAndSummarize().
 *
 * Runs the summarisation check over conversations with every kind of token
 * count and last-summary time, and compares whether the summarisation was
 * triggered (its log lines and message read) with a recorded fixture. Times
 * are relative to now, at least half an hour from the one-hour bound. Set
 * GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode compares
 * nothing.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use DateTime;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Golden comparison of when a conversation is summarised.
 *
 * @covers \OCA\Hermiq\Service\Engine\ConversationManagementHandler
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
 */
class ConversationSummaryDueGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../../Fixtures/Golden/conversation-summary-due.json';

	/**
	 * Token counts around the 4000-token bound.
	 *
	 * @var array<int, mixed>
	 */
	private const TOKEN_COUNTS = [null, 0, 3999, 4000, 4001, 12000, '3999', '5000', 3999.5, 'many', true];

	/**
	 * Last-summary times: absent, minutes before now, or not a time.
	 *
	 * @var array<int, int|string|null>
	 */
	private const LAST_SUMMARIES = [null, 0, 30, 90, 600, 'not a time', ''];

	/**
	 * Every combination is triggered or skipped as recorded.
	 *
	 * @return void
	 */
	public function testSummaryTriggerMatchesTheRecordedFixture(): void {
		$actual = [];
		foreach ([null, 'not an array', []] as $metadata) {
			$actual[] = $this->runCase(label: 'metadata ' . json_encode($metadata), metadata: $metadata);
		}

		foreach (self::TOKEN_COUNTS as $tokens) {
			foreach (self::LAST_SUMMARIES as $minutesAgo) {
				$metadata = ['token_count' => $tokens];
				if ($tokens === null) {
					$metadata = [];
				}

				if (is_int($minutesAgo) === true) {
					$metadata['last_summary_at'] = (new DateTime('-' . $minutesAgo . ' minutes'))->format('c');
				} else if ($minutesAgo !== null) {
					$metadata['last_summary_at'] = $minutesAgo;
				}

				$label = json_encode($tokens) . ' tokens, last summary ' . json_encode($minutesAgo);
				$actual[] = $this->runCase(label: $label, metadata: $metadata);
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(expected: $expected, actual: $actual);

	}//end testSummaryTriggerMatchesTheRecordedFixture()

	/**
	 * Run checkAndSummarize() on one conversation; the message read finds nothing.
	 *
	 * @param string $label    The case label.
	 * @param mixed  $metadata The conversation's `metadata`.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(string $label, mixed $metadata): array {
		$reads = 0;
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function () use (&$reads): array {
				$reads++;
				return [];
			}
		);
		$objectService->expects($this->never())->method('saveObject');

		$conversation = new ObjectEntity();
		$conversation->setUuid('conv-1');
		$payload = ['title' => 'T'];
		if ($metadata !== null) {
			$payload['metadata'] = $metadata;
		}

		$conversation->setObject($payload);

		$logger = $this->logger();
		$handler = new ConversationManagementHandler(
			objectService: $objectService,
			providerFactory: $this->createMock(originalClassName: ProviderFactory::class),
			logger: $logger
		);
		$handler->checkAndSummarize(conversation: $conversation);

		return ['case' => $label, 'messageReads' => $reads, 'log' => $logger->lines];

	}//end runCase()

	/**
	 * A logger that keeps each line's level, message and context without the source.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * The recorded lines.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|Stringable    $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->lines[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};

	}//end logger()
}//end class
