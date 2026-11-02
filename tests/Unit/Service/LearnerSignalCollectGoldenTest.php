<?php

/**
 * Golden test for LearnerSignalReader::collect().
 *
 * Runs the signal collection over generated learner data, with reads that
 * fail or come back empty per schema, and compares the result, the reads it
 * asked for and its log lines with a recorded fixture. Set GOLDEN_RECORD=1 to
 * (re)write the fixture; a run in record mode compares nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\LearnerSignalReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of the learner signal collection.
 *
 * @covers \OCA\Hermiq\Service\LearnerSignalReader
 *
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-2-5
 */
class LearnerSignalCollectGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/learner-signal-collect.json';

	private const CASES = 100;

	private const GOAL_STATES = ['open', 'open', 'done', '', 'OPEN', null, 7];

	private const LIFECYCLES = ['completed', 'completed', 'active', '', 'Completed', null];

	private const COMPETENCY_IDS = ['k1', 'k2', '', null, 5, 'k1'];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated case gives the recorded result, reads and log lines.
	 *
	 * @return void
	 */
	public function testCollectMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$answers = [
				'enrolment' => $this->answer(rows: fn (): array => ['lifecycle' => $this->pick(pool: self::LIFECYCLES)]),
				'course' => $this->answer(rows: fn (): array => ['title' => 'c' . $this->random->getInt(1, 9)]),
				'xapi-statement' => $this->answer(rows: fn (): array => ['verb' => 'completed']),
				'learning-plan' => $this->answer(rows: fn (): array => $this->plan()),
				'competency-attainment' => $this->answer(
					rows: fn (): array => $this->omitSome(payload: ['competencyId' => $this->pick(pool: self::COMPETENCY_IDS)])
				),
			];

			$reads = [];
			$schema = '';
			$objectService = $this->createMock(originalClassName: ObjectService::class);
			$objectService->method('setRegister')->willReturnCallback(
				function (string $register) use (&$reads, $objectService): ObjectService {
					$reads[] = 'register ' . $register;
					return $objectService;
				}
			);
			$objectService->method('setSchema')->willReturnCallback(
				function (string $slug) use (&$reads, &$schema, $objectService): ObjectService {
					$reads[] = 'schema ' . $slug;
					$schema = $slug;
					return $objectService;
				}
			);
			$objectService->method('findAll')->willReturnCallback(
				function (array $config) use (&$reads, &$schema, $answers): array {
					$reads[] = 'config ' . json_encode($config, JSON_THROW_ON_ERROR);
					$answer = ($answers[$schema] ?? []);
					if ($answer === 'throws') {
						throw new RuntimeException('read failed for ' . $schema);
					}

					return $answer;
				}
			);

			$logger = $this->logger();
			$reader = new LearnerSignalReader(objectService: $objectService, logger: $logger);
			$result = $reader->collect(learnerUid: 'learner-' . $case, registerSlug: 'reg-' . ($case % 2));

			$actual[] = ['result' => $result, 'reads' => $reads, 'log' => $logger->lines];
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

	}//end testCollectMatchesTheRecordedFixture()

	/**
	 * One schema's answer: a failed read, or a list of objects and non-objects.
	 *
	 * @param callable $rows Builds one object payload.
	 *
	 * @return string|array<int, mixed>
	 */
	private function answer(callable $rows): string|array {
		if ($this->random->getInt(0, 6) === 0) {
			return 'throws';
		}

		$out = [];
		for ($i = $this->random->getInt(0, 5); $i > 0; $i--) {
			if ($this->random->getInt(0, 9) === 0) {
				$out[] = ['not' => 'an entity'];
				continue;
			}

			$entity = new ObjectEntity();
			$entity->setUuid('u' . $i);
			$entity->setObject($rows());
			$out[] = $entity;
		}

		return $out;

	}//end answer()

	/**
	 * A learning plan with a generated goal list.
	 *
	 * @return array<string, mixed>
	 */
	private function plan(): array {
		$kind = $this->random->getInt(0, 5);
		if ($kind === 0) {
			return [];
		}

		if ($kind === 1) {
			return ['goals' => 'not a list'];
		}

		$goals = [];
		for ($i = $this->random->getInt(0, 4); $i > 0; $i--) {
			if ($this->random->getInt(0, 7) === 0) {
				$goals[] = 'loose';
				continue;
			}

			$goals[] = $this->omitSome(payload: ['id' => 'g' . $i, 'status' => $this->pick(pool: self::GOAL_STATES)]);
		}

		return ['goals' => $goals];

	}//end plan()

	/**
	 * Drop each key of a payload with a small chance.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array<string, mixed>
	 */
	private function omitSome(array $payload): array {
		foreach (array_keys($payload) as $key) {
			if ($this->random->getInt(0, 4) === 0) {
				unset($payload[$key]);
			}
		}

		return $payload;

	}//end omitSome()

	/**
	 * Pick one value from a pool.
	 *
	 * @param array<int, mixed> $pool The pool.
	 *
	 * @return mixed
	 */
	private function pick(array $pool): mixed {
		return $pool[$this->random->getInt(0, count($pool) - 1)];

	}//end pick()

	/**
	 * A logger that keeps each line's level, message and the exception message.
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
				$exception = null;
				if (($context['exception'] ?? null) instanceof \Throwable) {
					$exception = $context['exception']->getMessage();
				}

				$this->lines[] = ['level' => $level, 'message' => (string)$message, 'exception' => $exception];
			}
		};

	}//end logger()
}//end class
