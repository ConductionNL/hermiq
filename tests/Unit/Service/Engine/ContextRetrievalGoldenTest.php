<?php

/**
 * Golden test for ContextRetrievalHandler::retrieveContext().
 *
 * Runs retrieval over generated agents, RAG settings, graph answers and search
 * rows, and compares the context, every log line and every search call with a
 * recorded fixture. Set GOLDEN_RECORD=1 to (re)write the fixture; a run in record
 * mode compares nothing.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use Exception;
use OCA\Hermiq\Service\Engine\AppRegisterScope;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Graph\GraphContextRetriever;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of RAG context retrieval.
 *
 * @covers \OCA\Hermiq\Service\Engine\ContextRetrievalHandler
 * @covers \OCA\Hermiq\Service\Engine\RetrievalSourceCollector
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 */
class ContextRetrievalGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../../Fixtures/Golden/context-retrieval.json';

	private const CASES = 400;

	private const MODES = ['graph', 'graph', 'graph', 'graph', 'semantic', 'hybrid', 'keyword', 'other', null];

	private const COUNTS = [0, 1, 2, 3, 5, '2', null];

	private const FLAGS = [true, false, null, 0, 'false'];

	private const VIEWS = ['v1', 'v2', 'v3'];

	private const TYPES = ['file', 'file', 'object', 'object', 'other', null];

	private const NAMES = ['Alpha', '', null, 'Beta report'];

	/**
	 * Every generated case retrieves exactly as recorded.
	 *
	 * @return void
	 */
	public function testRetrievalMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			mt_srand($case + 1);
			$actual[] = $this->runCase();
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped('Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(count($expected), $actual);
		foreach ($expected as $case => $expectedCase) {
			// A per-case identity check: a full assertSame diff over the whole fixture takes minutes.
			$this->assertTrue($expectedCase === $actual[$case], 'Case ' . $case . ' differs from the fixture.');
		}

		$this->assertGreaterThan(0, array_sum(array_map(static fn (array $r): int => count($r['result']['sources'] ?? []), $actual)));
	}//end testRetrievalMatchesTheRecordedFixture()

	/**
	 * Runs one seeded case and returns what it produced, round-tripped through JSON.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(): array {
		$calls = [];
		$logger = $this->logger();
		$handler = new ContextRetrievalHandler(
			$this->objectService(calls: $calls),
			$logger,
			$this->pick([null, $this->graphRetriever(), $this->graphRetriever()]),
			$this->pick([null, $this->appScope(), $this->appScope()])
		);

		$agent = $this->pick([null, $this->agent(), $this->agent(), $this->agent()]);
		$record = ['result' => null, 'error' => null];
		try {
			$record['result'] = $handler->retrieveContext(
				query: 'query ' . mt_rand(0, 99),
				agent: $agent,
				selectedViews: $this->subset(self::VIEWS),
				ragSettings: $this->ragSettings()
			);
		} catch (\Throwable $e) {
			$record['error'] = get_class($e);
		}

		$record['calls'] = $calls;
		$record['logs'] = $logger->lines;

		return json_decode(json_encode($record, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}//end runCase()

	/**
	 * A logger that keeps level, message and context, without file and line.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class extends AbstractLogger {
			/** @var array<int, array<string, mixed>> */
			public array $lines = [];

			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->lines[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};
	}//end logger()

	/**
	 * An ObjectService whose searches are recorded by parameter name.
	 *
	 * @param array<int, array<string, mixed>> $calls Receives each call.
	 *
	 * @return ObjectService
	 */
	private function objectService(array &$calls): ObjectService {
		$failing = (mt_rand(0, 9) === 0);
		$service = $this->createMock(ObjectService::class);
		$service->method('searchObjectsPaginated')->willReturnCallback(
			function (...$args) use (&$calls, $failing): array {
				$calls[] = $this->named(args: $args);
				if ($failing === true) {
					throw new Exception('search failed');
				}

				return ['results' => $this->searchRows()];
			}
		);

		return $service;
	}//end objectService()

	/**
	 * The arguments that differ from their default, keyed by parameter name.
	 *
	 * @param array<int, mixed> $args The positional arguments the mock received.
	 *
	 * @return array<string, mixed>
	 */
	private function named(array $args): array {
		$named = [];
		$params = (new ReflectionMethod(ObjectService::class, 'searchObjectsPaginated'))->getParameters();
		foreach ($params as $index => $param) {
			if (array_key_exists($index, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$index]) {
				continue;
			}

			$named[$param->getName()] = $args[$index];
		}

		return $named;
	}//end named()

	/**
	 * Search rows of mixed shapes: arrays, entities and scalars.
	 *
	 * @return array<int, mixed>
	 */
	private function searchRows(): array {
		$rows = [];
		for ($i = mt_rand(0, 6); $i > 0; $i--) {
			$kind = mt_rand(0, 5);
			if ($kind === 0) {
				$entity = new ObjectEntity();
				$entity->setUuid('ent-' . mt_rand(0, 9));
				$entity->setObject(['name' => $this->pick(self::NAMES), 'n' => mt_rand(0, 9)]);
				$rows[] = $entity;
				continue;
			}

			if ($kind === 1) {
				$rows[] = 'scalar-' . mt_rand(0, 9);
				continue;
			}

			$row = ['id' => $this->pick(['r' . mt_rand(0, 9), null]), 'uuid' => 'u' . mt_rand(0, 9)];
			$row = array_merge($row, $this->maybe(['name' => $this->pick(self::NAMES), 'title' => $this->pick(self::NAMES)]));
			$row = array_merge($row, $this->maybe(['_score' => $this->pick([0.5, 2, '1.5', 0.9]), '_source' => ['data' => 'raw ' . mt_rand(0, 9)]]));
			$rows[] = $row;
		}

		return $rows;
	}//end searchRows()

	/**
	 * A graph retriever that answers null, a neighbourhood, or throws.
	 *
	 * @return GraphContextRetriever
	 */
	private function graphRetriever(): GraphContextRetriever {
		$answer = mt_rand(0, 6);
		$graph = ['results' => $this->graphRows(), 'relations' => $this->pick(['', "A -> B\n", 'rel ' . mt_rand(0, 9) . "\n"])];
		if (mt_rand(0, 4) === 0) {
			unset($graph['relations']);
		}

		$retriever = $this->createMock(GraphContextRetriever::class);
		$retriever->method('retrieve')->willReturnCallback(
			static function (string $query, int $limit) use ($answer, $graph): ?array {
				if ($answer === 0) {
					throw new RuntimeException('graph down at ' . $limit);
				}

				if ($answer === 1) {
					return null;
				}

				if (isset($graph['relations']) === true) {
					$graph['relations'] .= 'limit=' . $limit . "\n";
				}

				return $graph;
			}
		);

		return $retriever;
	}//end graphRetriever()

	/**
	 * Graph rows in the wide source shape (files, objects, metadata as text or array).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function graphRows(): array {
		// One object row named only by its metadata, whose register is named twice.
		$rows = [[
			'entity_id' => 'directed-' . mt_rand(0, 9),
			'entity_type' => 'object',
			'register_name' => $this->pick(['Zaken', null]),
			'metadata' => ['register_id' => 'reg-meta', 'object_title' => 'Meta title', 'file_name' => 'meta.txt'],
		]];
		for ($i = mt_rand(0, 9); $i > 0; $i--) {
			$row = ['entity_id' => $this->pick(['e' . mt_rand(100, 999) . 'abcdefghij', null, '', 7])];
			$type = $this->pick(self::TYPES);
			if ($type !== null) {
				$row['entity_type'] = $type;
			}

			if (mt_rand(0, 2) === 0) {
				$row = array_merge($row, $this->maybe(['title' => $this->pick(self::NAMES), 'name' => $this->pick(self::NAMES)]));
			}
			$row = array_merge($row, $this->maybe(['filename' => $this->pick(['f.pdf', ''])]));
			$row = array_merge($row, $this->maybe(['similarity' => $this->pick([0.8, null, 1])]));
			$row = array_merge($row, $this->maybe(['score' => $this->pick([0.3, 4])]));
			$row = array_merge($row, $this->maybe(['chunk_text' => $this->pick(['chunk', null])]));
			$row = array_merge($row, $this->maybe(['text' => 'body ' . mt_rand(0, 9)]));
			$row = array_merge($row, $this->maybe(['register_name' => $this->pick(['Zaken', '', null])]));
			if (mt_rand(0, 3) > 0) {
				$row['metadata'] = $this->metadata();
			}
			$rows[] = $row;
		}

		return $rows;
	}//end graphRows()

	/**
	 * Row metadata as an array, a JSON text, broken JSON or a scalar.
	 *
	 * @return mixed
	 */
	private function metadata(): mixed {
		$fields = $this->maybe([
			'uuid' => 'mu' . mt_rand(0, 9),
			'register_id' => 'reg-' . mt_rand(0, 3),
			'register' => 'regname',
			'schema_id' => 'sch-' . mt_rand(0, 3),
			'schema' => 'schname',
			'uri' => '/obj/' . mt_rand(0, 9),
			'file_id' => mt_rand(1, 99),
			'file_path' => '/f/' . mt_rand(0, 9),
			'mime_type' => 'text/plain',
			'object_title' => $this->pick(['Obj title', '']),
			'file_name' => $this->pick(['doc.txt', '']),
			'name' => $this->pick(self::NAMES),
			'title' => $this->pick(self::NAMES),
		]);

		return $this->pick([$fields, $fields, json_encode($fields), json_encode($fields), '{broken', '"text"', 5, [], '']);
	}//end metadata()

	/**
	 * An app register scope naming zero to three registers.
	 *
	 * @return AppRegisterScope
	 */
	private function appScope(): AppRegisterScope {
		$registers = [];
		for ($i = mt_rand(0, 3); $i > 0; $i--) {
			$registers[] = ['id' => 'reg-' . $i, 'name' => 'Register ' . $i];
		}

		$scope = $this->createMock(AppRegisterScope::class);
		$scope->method('registersFor')->willReturn($registers);

		return $scope;
	}//end appScope()

	/**
	 * An agent with a random subset of RAG fields.
	 *
	 * @return ObjectEntity
	 */
	private function agent(): ObjectEntity {
		$data = ['name' => 'agent'];
		if (mt_rand(0, 4) > 0) {
			$data['ragSearchMode'] = $this->pick(self::MODES);
		}

		$data = array_merge($data, $this->maybe(['ragNumSources' => $this->pick(self::COUNTS)]));
		$data = array_merge($data, $this->maybe(['searchFiles' => $this->pick(self::FLAGS)]));
		$data = array_merge($data, $this->maybe(['searchObjects' => $this->pick(self::FLAGS)]));
		$data = array_merge($data, $this->maybe(['views' => $this->subset(self::VIEWS)]));
		$data = array_merge($data, $this->maybe(['applicationSlug' => $this->pick(['dossiq', 'dossiq', '  ', '', null])]));
		if (mt_rand(0, 4) > 0) {
			$data['graphEnabled'] = $this->pick([true, true, false, 'yes']);
		}

		$entity = new ObjectEntity();
		$entity->setUuid('agent-' . mt_rand(0, 9));
		$entity->setObject($data);

		return $entity;
	}//end agent()

	/**
	 * Caller RAG overrides, each present or not.
	 *
	 * @return array<string, mixed>
	 */
	private function ragSettings(): array {
		$settings = $this->maybe(['includeFiles' => $this->pick(self::FLAGS)]);
		$settings = array_merge($settings, $this->maybe(['includeObjects' => $this->pick(self::FLAGS)]));
		$settings = array_merge($settings, $this->maybe(['numSourcesFiles' => $this->pick(self::COUNTS)]));
		return array_merge($settings, $this->maybe(['numSourcesObjects' => $this->pick(self::COUNTS)]));
	}//end ragSettings()

	/**
	 * The given fields, each kept with even chance.
	 *
	 * @param array<string, mixed> $fields The candidate fields.
	 *
	 * @return array<string, mixed>
	 */
	private function maybe(array $fields): array {
		return array_filter($fields, static fn (): bool => mt_rand(0, 1) === 1);
	}//end maybe()

	/**
	 * A random subset of the given values, order kept.
	 *
	 * @param array<int, mixed> $values The values.
	 *
	 * @return array<int, mixed>
	 */
	private function subset(array $values): array {
		return array_values(array_filter($values, static fn (): bool => mt_rand(0, 2) === 0));
	}//end subset()

	/**
	 * One value from the list.
	 *
	 * @param array<int, mixed> $values The values.
	 *
	 * @return mixed
	 */
	private function pick(array $values): mixed {
		return $values[mt_rand(0, count($values) - 1)];
	}//end pick()
}//end class
