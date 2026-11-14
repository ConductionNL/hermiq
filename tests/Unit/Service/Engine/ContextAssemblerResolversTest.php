<?php

/**
 * Characterisation tests for ContextAssembler's per-entry resolvers.
 *
 * Pins the exact text, query configuration and log lines that the object-query,
 * file, document and agent-context resolvers produce for every skip branch, so the
 * resolvers can be split into named steps without changing behaviour.
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
 * @spec openspec/changes/agent-context-system/tasks.md#4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Characterisation tests for the ContextAssembler resolvers.
 *
 * @spec openspec/changes/agent-context-system/tasks.md#4-1
 */
class ContextAssemblerResolversTest extends TestCase {

	/**
	 * Logged lines as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $logLines = [];

	/**
	 * A logger that records every line it receives.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		$lines = &$this->logLines;
		return new class($lines) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $lines The shared line store.
			 */
			public function __construct(private array &$lines) {
			}//end __construct()

			/**
			 * Record one line.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ': ' . $message;
			}//end log()
		};
	}//end recordingLogger()

	/**
	 * An ObjectEntity with the given payload.
	 *
	 * @param array<string, mixed> $payload The object data.
	 * @param string $uuid The object uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload, string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * Every object query shape: skipped entries, the built query configuration,
	 * a failing read and a non-object result.
	 *
	 * @return void
	 */
	public function testObjectQueriesBuildConfigSkipAndLog(): void {
		$calls = [];
		$current = '';
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnCallback(
			function (string $register) use (&$current, $objectService) {
				$current = $register;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$calls, &$current) {
				$calls[] = [$current, $config];
				if ($current === 'broken') {
					throw new RuntimeException('read failed');
				}

				return [
					'not-an-entity',
					$this->entity(['title' => 'A/B'], 'o-1'),
				];
			}
		);
		$objectService->method('find')->willReturn(
			$this->entity(
				[
					'name' => 'Q',
					'objectQueries' => [
						'not-an-array',
						['register' => 'r', 'schema' => ''],
						['register' => '', 'schema' => 's'],
						['register' => 'r1', 'schema' => 's1', 'limit' => '5', 'filters' => ['a' => 1], 'search' => 'term'],
						['register' => 'r2', 'schema' => 's2', 'filters' => [], 'search' => ''],
						['register' => 'r3', 'schema' => 's3', 'filters' => 'x', 'search' => 7],
						['register' => 'broken', 'schema' => 's4'],
					],
				],
				'ctx'
			)
		);

		$assembler = new ContextAssembler($objectService, $this->createMock(IRootFolder::class), $this->recordingLogger());
		$result = $assembler->assemble(contextId: 'ctx', actingUserId: '');

		$this->assertSame(
			[
				['r1', ['limit' => 5, 'filters' => ['a' => 1], 'search' => 'term']],
				['r2', ['limit' => 20]],
				['r3', ['limit' => 20]],
				['broken', ['limit' => 20]],
			],
			$calls
		);
		$block = '{"id":"o-1","title":"A/B"}';
		$this->assertSame(
			"Context: Q\nSource: r1/s1\n" . $block . "\n\nSource: r2/s2\n" . $block . "\n\nSource: r3/s3\n" . $block,
			$result['text']
		);
		$this->assertSame(['warning: Hermiq ContextAssembler query broken/s4 failed: read failed'], $this->logLines);

	}//end testObjectQueriesBuildConfigSkipAndLog()

	/**
	 * A non-array objectQueries value contributes nothing.
	 *
	 * @return void
	 */
	public function testNonArrayObjectQueriesContributeNothing(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');
		$objectService->method('find')->willReturn($this->entity(['name' => 'N', 'objectQueries' => 'x'], 'ctx'));

		$assembler = new ContextAssembler($objectService, $this->createMock(IRootFolder::class), $this->recordingLogger());

		$this->assertSame('Context: N', $assembler->assemble(contextId: 'ctx', actingUserId: 'alice')['text']);
		$this->assertSame([], $this->logLines);

	}//end testNonArrayObjectQueriesContributeNothing()

	/**
	 * Every file entry shape: skipped entries, a missing file, a folder, a capped file,
	 * a failing read and a normal read, with their log lines in order.
	 *
	 * @return void
	 */
	public function testFilesSkipCapAndLog(): void {
		$big = str_repeat('x', 20005);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturnCallback(
			static fn (string $path): bool => in_array($path, ['dir', 'big.txt', 'boom.txt', 'ok.txt'], true)
		);
		$userFolder->method('get')->willReturnCallback(
			function (string $path) use ($big) {
				if ($path === 'dir') {
					return $this->createMock(Folder::class);
				}

				if ($path === 'boom.txt') {
					throw new RuntimeException('locked');
				}

				$file = $this->createMock(File::class);
				$file->method('getContent')->willReturn($path === 'big.txt' ? $big : 'hello');
				return $file;
			}
		);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			$this->entity(
				[
					'name' => 'F',
					'charBudget' => 100000,
					'files' => [
						'not-an-array',
						['path' => '/'],
						[],
						['path' => 'gone.txt'],
						['path' => 'dir'],
						['path' => 'big.txt'],
						['path' => 'boom.txt'],
						['path' => 'ok.txt'],
					],
				],
				'ctx'
			)
		);

		$assembler = new ContextAssembler($objectService, $rootFolder, $this->recordingLogger());
		$result = $assembler->assemble(contextId: 'ctx', actingUserId: 'alice');

		$this->assertSame(
			"Context: F\nSource: big.txt\n" . str_repeat('x', 20000) . "\n\nSource: ok.txt\nhello",
			$result['text']
		);
		$this->assertSame(
			[
				'info: Hermiq ContextAssembler: file not found, skipping: gone.txt',
				'info: Hermiq ContextAssembler: path is not a file, skipping: dir',
				'info: Hermiq ContextAssembler: file capped at 20000 bytes: big.txt',
				'warning: Hermiq ContextAssembler could not read file boom.txt: locked',
			],
			$this->logLines
		);

	}//end testFilesSkipCapAndLog()

	/**
	 * Files are not read without an acting user, with a non-array value, or when the
	 * user folder cannot be opened (logged once).
	 *
	 * @return void
	 */
	public function testFilesNeedAUserAndAnOpenFolder(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willThrowException(new RuntimeException('no such user'));

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			fn (string $id): ObjectEntity => match ($id) {
				'scalar' => $this->entity(['name' => 'S', 'files' => 'x'], $id),
				'empty' => $this->entity(['name' => 'E', 'files' => []], $id),
				default => $this->entity(['name' => 'U', 'files' => [['path' => 'a.txt']]], $id),
			}
		);

		$assembler = new ContextAssembler($objectService, $rootFolder, $this->recordingLogger());

		$this->assertSame('Context: S', $assembler->assemble(contextId: 'scalar', actingUserId: 'bob')['text']);
		$this->assertSame('Context: E', $assembler->assemble(contextId: 'empty', actingUserId: 'bob')['text']);
		$this->assertSame('Context: U', $assembler->assemble(contextId: 'files', actingUserId: '')['text']);
		$this->assertSame([], $this->logLines);

		$this->assertSame('Context: U', $assembler->assemble(contextId: 'files', actingUserId: 'bob')['text']);
		$this->assertSame(['warning: Hermiq ContextAssembler could not open the folder for bob: no such user'], $this->logLines);

	}//end testFilesNeedAUserAndAnOpenFolder()

	/**
	 * Every document entry shape, with the skip line naming the entry's index.
	 *
	 * @return void
	 */
	public function testDocumentsSkipAndLogByIndex(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			$this->entity(
				[
					'name' => 'D',
					'documents' => [
						'not-an-array',
						['name' => '  ', 'body' => 'b'],
						['name' => 'n', 'body' => '   '],
						['name' => 5, 'body' => 'b'],
						['name' => ' Title ', 'body' => ' Body '],
						'k' => ['name' => 'x'],
					],
				],
				'ctx'
			)
		);

		$assembler = new ContextAssembler($objectService, $this->createMock(IRootFolder::class), $this->recordingLogger());

		$this->assertSame("Context: D\nDocument: Title\n Body ", $assembler->assemble(contextId: 'ctx', actingUserId: '')['text']);
		$this->assertSame(
			[
				'info: Hermiq ContextAssembler: document 0 has no name or body, skipping',
				'info: Hermiq ContextAssembler: document 1 has no name or body, skipping',
				'info: Hermiq ContextAssembler: document 2 has no name or body, skipping',
				'info: Hermiq ContextAssembler: document 3 has no name or body, skipping',
				'info: Hermiq ContextAssembler: document k has no name or body, skipping',
			],
			$this->logLines
		);

	}//end testDocumentsSkipAndLogByIndex()

	/**
	 * An agent's context refs: invalid refs and unresolvable contexts are skipped,
	 * the rest are joined by a blank line; a non-array refs value is a no-op.
	 *
	 * @return void
	 */
	public function testAssembleForAgentSkipsInvalidAndUnresolvedRefs(): void {
		$requested = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (string $id) use (&$requested): ?ObjectEntity {
				$requested[] = $id;
				if ($id === 'gone') {
					return null;
				}

				return $this->entity(['name' => strtoupper($id)], $id);
			}
		);

		$assembler = new ContextAssembler($objectService, $this->createMock(IRootFolder::class), $this->recordingLogger());

		$agent = $this->entity(['contextRefs' => ['a', 7, '', 'gone', 'b']], 'agent');
		$this->assertSame("Context: A\n\nContext: B", $assembler->assembleForAgent(agent: $agent, actingUserId: 'alice'));
		$this->assertSame(['a', 'gone', 'b'], $requested);

		$scalar = $this->entity(['contextRefs' => 'a'], 'agent');
		$this->assertSame('', $assembler->assembleForAgent(agent: $scalar, actingUserId: 'alice'));
		$this->assertSame(['a', 'gone', 'b'], $requested);

	}//end testAssembleForAgentSkipsInvalidAndUnresolvedRefs()
}//end class
