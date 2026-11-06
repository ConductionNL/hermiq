<?php

/**
 * Golden test for ConversationManagementHandler::ensureUniqueTitle().
 *
 * Runs the title probe over generated conversation sets and compares the
 * returned title, the lookup it asked for and its log lines with a recorded
 * fixture. Set GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode
 * compares nothing.
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

use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Stringable;

/**
 * Golden comparison of the unique conversation title.
 *
 * @covers \OCA\Hermiq\Service\Engine\ConversationManagementHandler
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class ConversationUniqueTitleGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../../Fixtures/Golden/conversation-unique-title.json';

	private const CASES = 200;

	private const BASES = ['Leave policy', 'a.b', 'Q? (1)', '/x/', 'Leave'];

	private const SUFFIXES = ['', ' (2)', ' (3)', ' (10)', ' (0)', ' (x)', '(4)', ' (5) more', 'X', ' (07)', ' ( 6)'];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated case gives the recorded title, lookup and log lines.
	 *
	 * @return void
	 */
	public function testUniqueTitleMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$base = self::BASES[$this->random->getInt(0, count(self::BASES) - 1)];
			$found = $this->conversations(base: $base);

			$lookups = [];
			$objectService = $this->createMock(originalClassName: ObjectService::class);
			$objectService->method('setRegister')->willReturnCallback(
				function (string $register) use (&$lookups, $objectService): ObjectService {
					$lookups[] = ['register' => $register];
					return $objectService;
				}
			);
			$objectService->method('setSchema')->willReturnCallback(
				function (string $schema) use (&$lookups, $objectService): ObjectService {
					$lookups[] = ['schema' => $schema];
					return $objectService;
				}
			);
			$objectService->method('findAll')->willReturnCallback(
				function (array $config) use (&$lookups, $found): array {
					$lookups[] = ['config' => $config];
					return $found;
				}
			);

			$logger = $this->logger();
			$handler = new ConversationManagementHandler(
				objectService: $objectService,
				providerFactory: $this->createMock(originalClassName: ProviderFactory::class),
				logger: $logger
			);
			$title = $handler->ensureUniqueTitle(baseTitle: $base, userId: 'u' . $case, agentId: 'a' . ($case % 3));

			$actual[] = ['base' => $base, 'title' => $title, 'lookups' => $lookups, 'log' => $logger->lines];
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

	}//end testUniqueTitleMatchesTheRecordedFixture()

	/**
	 * A generated set of found conversations, most of them titled near the base.
	 *
	 * @param string $base The base title of the case.
	 *
	 * @return array<int, mixed>
	 */
	private function conversations(string $base): array {
		$found = [];
		for ($i = $this->random->getInt(0, 8); $i > 0; $i--) {
			$kind = $this->random->getInt(0, 9);
			if ($kind === 0) {
				$found[] = ['title' => 'Leave policy'];
				continue;
			}

			$payload = [];
			if ($kind === 1) {
				$payload['title'] = 42;
			} else if ($kind !== 2) {
				$near = $base;
				if ($kind === 3) {
					$near = self::BASES[$this->random->getInt(0, count(self::BASES) - 1)];
				}

				if ($kind === 4) {
					$near = 'Re: ' . $base;
				}

				$payload['title'] = $near . self::SUFFIXES[$this->random->getInt(0, count(self::SUFFIXES) - 1)];
			}

			$entity = new ObjectEntity();
			$entity->setUuid('c' . $i);
			$entity->setObject($payload);
			$found[] = $entity;
		}

		return $found;

	}//end conversations()

	/**
	 * A logger that keeps each line's level, message and context without the source line.
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
			 * @param mixed              $level   The level.
			 * @param string|Stringable  $message The message.
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
