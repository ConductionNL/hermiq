<?php

/**
 * Tests for KnowledgeBaseRetrieval: an agent answers from its knowledge bases, in scope only.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\KnowledgeBase
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md#task-3-retrieval-scoped-to-the-knowledge-bases
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\KnowledgeBase;

use OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseRetrieval;
use OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseScopeResolver;
use OCA\Hermiq\Service\KnowledgeBase\VectorFacadeProbe;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * The retrieval over a mocked object store, scope and probe.
 */
#[CoversClass(KnowledgeBaseRetrieval::class)]
class KnowledgeBaseRetrievalTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The scope.
	 *
	 * @var KnowledgeBaseScopeResolver&MockObject
	 */
	private KnowledgeBaseScopeResolver&MockObject $scope;

	/**
	 * The probe.
	 *
	 * @var VectorFacadeProbe&MockObject
	 */
	private VectorFacadeProbe&MockObject $probe;

	/**
	 * The retrieval under test.
	 *
	 * @var KnowledgeBaseRetrieval
	 */
	private KnowledgeBaseRetrieval $retrieval;

	/**
	 * Build the retrieval over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects   = $this->createMock(ObjectService::class);
		$this->scope     = $this->createMock(KnowledgeBaseScopeResolver::class);
		$this->probe     = $this->createMock(VectorFacadeProbe::class);
		$this->retrieval = new KnowledgeBaseRetrieval($this->objects, $this->scope, $this->probe, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * The attached knowledge bases are read, resolved as the speaker, and searched in scope.
	 *
	 * @return void
	 */
	public function testTheAttachedKnowledgeBasesAreSearchedAsTheSpeaker(): void {
		$knowledgeBase = new ObjectEntity();
		$knowledgeBase->setObject(['name' => 'Inkoopbeleid', 'sources' => [['type' => 'file', 'fileId' => 7]]]);
		$this->probe->method('canSearchFiles')->willReturn(true);
		$this->objects->expects($this->exactly(2))->method('find')
			->willReturnCallback(
				static function (string|int $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true) use ($knowledgeBase): ObjectEntity {
					$_extend = $options?->extend ?? [];
					$files = $options?->files ?? false;
					$register = $options?->register;
					$schema = $options?->schema;
					if ($id === 'kb-gone') {
						throw new RuntimeException('not found');
					}

					TestCase::assertSame(['kb-1', 'hermiq', 'agentknowledgebase', false, false], [$id, $register, $schema, $_rbac, $_multitenancy]);
					return $knowledgeBase;
				}
			);
		$this->scope->expects($this->once())->method('fileIds')
			->with($this->callback(static fn (array $bases): bool => ($bases[0]['name'] ?? null) === 'Inkoopbeleid' && count($bases) === 1), 'bert')
			->willReturn([7, 8]);
		$this->probe->expects($this->once())->method('searchFiles')->with('Wat is de betalingstermijn?', [7, 8], 3)
			->willReturn(
				[
					['entity_id' => 7, 'chunk_text' => 'Betaling binnen 30 dagen', 'similarity' => 0.91],
					['metadata' => ['file_id' => 8], 'entity_type' => 'file', 'chunk_text' => 'Bijlage'],
					['entity_id' => 99, 'chunk_text' => 'out of scope'],
				]
			);

		$rows = $this->retrieval->rows(
			agentData: ['knowledgeBaseRefs' => ['kb-1', ' kb-1 ', 'kb-gone', ['nested'], '']],
			query: 'Wat is de betalingstermijn?',
			speaker: 'bert',
			limit: 3
		);

		$this->assertSame(
			[
				['entity_type' => 'file', 'entity_id' => 7, 'chunk_text' => 'Betaling binnen 30 dagen', 'similarity' => 0.91],
				['entity_type' => 'file', 'metadata' => ['file_id' => 8], 'chunk_text' => 'Bijlage'],
			],
			$rows
		);
	}//end testTheAttachedKnowledgeBasesAreSearchedAsTheSpeaker()

	/**
	 * No knowledge bases, no speaker, or no file-scoped search: nothing is read or searched.
	 *
	 * @return void
	 */
	public function testNothingIsSearchedWithoutRefsSpeakerOrScope(): void {
		$this->objects->expects($this->never())->method('find');
		$this->probe->expects($this->never())->method('searchFiles');
		$this->probe->method('canSearchFiles')->willReturn(false);

		$this->assertSame([], $this->retrieval->rows(agentData: [], query: 'q', speaker: 'bert', limit: 3));
		$this->assertSame([], $this->retrieval->rows(agentData: ['knowledgeBaseRefs' => 'kb-1'], query: 'q', speaker: 'bert', limit: 3));
		$this->assertSame([], $this->retrieval->rows(agentData: ['knowledgeBaseRefs' => ['kb-1']], query: 'q', speaker: '', limit: 3));
		$this->assertSame([], $this->retrieval->rows(agentData: ['knowledgeBaseRefs' => ['kb-1']], query: 'q', speaker: 'bert', limit: 3));
	}//end testNothingIsSearchedWithoutRefsSpeakerOrScope()

	/**
	 * A person who may read none of the files gets no search at all.
	 *
	 * @return void
	 */
	public function testAPersonWhoMayReadNoneOfTheFilesGetsNoSearch(): void {
		$this->probe->method('canSearchFiles')->willReturn(true);
		$this->objects->method('find')->willReturn(new ObjectEntity());
		$this->scope->method('fileIds')->willReturn([]);
		$this->probe->expects($this->never())->method('searchFiles');

		$this->assertSame([], $this->retrieval->rows(agentData: ['knowledgeBaseRefs' => ['kb-1']], query: 'q', speaker: 'bert', limit: 3));
	}//end testAPersonWhoMayReadNoneOfTheFilesGetsNoSearch()
}//end class
