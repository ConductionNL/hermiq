<?php

/**
 * Unit tests for the edges of ContextAssembler that the main suite leaves out.
 *
 * The draft-vs-active eval swaps a skill's content in memory without touching
 * the stored object; a skill id that is not a string or cannot be read is
 * skipped rather than failing the run; and a Context whose flag cannot be
 * persisted still assembles, declares its viewRefs, and ignores a malformed
 * `documents` value.
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the skill-content override, unreadable skills and the Context flag write.
 *
 * @covers \OCA\Hermiq\Service\Engine\ContextAssembler
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
 */
class ContextAssemblerEdgesTest extends TestCase {

	/**
	 * An entity with the given uuid and payload.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $payload The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * The assembler over the given object service and logger.
	 *
	 * @param ObjectService $objectService The object service.
	 * @param LoggerInterface|null $logger The logger, a null logger by default.
	 *
	 * @return ContextAssembler
	 */
	private function assembler(ObjectService $objectService, ?LoggerInterface $logger = null): ContextAssembler {
		return new ContextAssembler($objectService, $this->createMock(IRootFolder::class), ($logger ?? new NullLogger()));
	}//end assembler()

	/**
	 * A transient content override replaces a skill's name, description and
	 * body for the run, keeps stored fields it does not name, and stops
	 * applying once cleared.
	 *
	 * @return void
	 */
	public function testATransientContentOverrideSwapsTheSkillsTextUntilCleared(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			$this->entity('sk-1', ['name' => 'stored', 'description' => 'Stored description', 'body' => 'Stored body', 'state' => 'active'])
		);
		$objectService->expects($this->never())->method('saveObject');
		$assembler = $this->assembler($objectService);

		$assembler->setTransientSkillContentOverride(override: ['sk-1' => ['name' => 'draft', 'body' => 'Draft body']]);
		$draft = $assembler->assembleSkillsForRun(agent: null, skillSetOverride: ['sk-1']);

		$this->assertSame("Skill: draft\nStored description\n\nDraft body", $draft['text']);
		$this->assertSame(['sk-1'], $draft['skillsUsed']);

		$assembler->setTransientSkillContentOverride(override: null);
		$active = $assembler->assembleSkillsForRun(agent: null, skillSetOverride: ['sk-1']);

		$this->assertSame("Skill: stored\nStored description\n\nStored body", $active['text']);
	}//end testATransientContentOverrideSwapsTheSkillsTextUntilCleared()

	/**
	 * Ids that are not non-empty strings are never looked up, and a skill
	 * whose read throws is skipped with a warning while the rest still load.
	 *
	 * @return void
	 */
	public function testMalformedAndUnreadableSkillsAreSkipped(): void {
		$objectService = $this->createMock(ObjectService::class);
		$looked = [];
		$objectService->method('find')->willReturnCallback(
			function (string $id) use (&$looked): ObjectEntity {
				$looked[] = $id;
				if ($id === 'sk-broken') {
					throw new RuntimeException('register offline');
				}

				return $this->entity($id, ['name' => 'ok', 'state' => 'active']);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not resolve skill sk-broken: register offline')
		);

		$bundle = $this->assembler($objectService, $logger)->assembleSkillsForRun(
			agent: null,
			skillSetOverride: [42, '', null, 'sk-broken', 'sk-ok']
		);

		$this->assertSame(['sk-broken', 'sk-ok'], $looked);
		$this->assertSame(['sk-ok'], $bundle['skillsUsed']);
		$this->assertSame('Skill: ok', $bundle['text']);
	}//end testMalformedAndUnreadableSkillsAreSkipped()

	/**
	 * A Context whose flag write fails still returns its assembled result;
	 * the failure is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailedFlagWriteStillReturnsTheContext(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			$this->entity('ctx-1', ['name' => 'Empty', 'charBudget' => -1, 'needsConsolidation' => false])
		);
		$objectService->expects($this->once())->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not persist needsConsolidation for ctx-1: locked')
		);

		$result = $this->assembler($objectService, $logger)->assemble(contextId: 'ctx-1', actingUserId: 'alice');

		$this->assertSame(['text' => 'Context: Empty', 'needsConsolidation' => true], $result);
	}//end testAFailedFlagWriteStillReturnsTheContext()

	/**
	 * Declared viewRefs are logged with their count, and a `documents` value
	 * that is not a list contributes nothing.
	 *
	 * @return void
	 */
	public function testViewRefsAreDeclaredAndMalformedDocumentsIgnored(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			$this->entity('ctx-2', ['name' => 'Views', 'documents' => 'not-a-list', 'viewRefs' => ['v1', 'v2']])
		);
		$objectService->expects($this->never())->method('saveObject');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Hermiq ContextAssembler: Context ctx-2 declares 2 viewRefs — resolution deferred (mirrors Agent.views).'
		);

		$result = $this->assembler($objectService, $logger)->assemble(contextId: 'ctx-2', actingUserId: 'alice');

		$this->assertSame(['text' => 'Context: Views', 'needsConsolidation' => false], $result);
	}//end testViewRefsAreDeclaredAndMalformedDocumentsIgnored()
}//end class
