<?php

/**
 * The graph retrieval mode: seeds from the query, a bounded visible neighbourhood,
 * records hydrated live as the acting user, and the relations as a compact block
 * (knowledge-graph).
 *
 * Runs on the real GraphService over the in-memory graph; the same ObjectService
 * double serves the underlying records under their own schema slug.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use OCA\Hermiq\Service\Graph\GraphContextRetriever;
use OCA\Hermiq\Service\Graph\GraphSourceReader;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for GraphContextRetriever.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
 */
class GraphContextRetrieverTest extends TestCase {

	use InMemoryGraph;

	/**
	 * A graph (Jan worksFor Acme, Acme partOf a protected holding, Jan mentions a file)
	 * and the retriever over it, acting as alice.
	 *
	 * @return GraphContextRetriever
	 */
	private function retriever(): GraphContextRetriever {
		$graph = $this->graph();
		$jan = $this->node($graph, 'Jan Jansen', 'rec-jan');
		$acme = $this->node($graph, 'Acme', 'rec-acme');
		$holding = $this->node($graph, 'Secret Holding', 'rec-holding');
		$report = $graph->upsertEntity(['label' => 'Q3 report', 'entityType' => 'document', 'sourceType' => 'file', 'sourceRef' => ['fileId' => 7]]);
		$graph->upsertRelation(['fromEntity' => $jan, 'predicate' => 'worksFor', 'toEntity' => $acme]);
		$graph->upsertRelation(['fromEntity' => $acme, 'predicate' => 'partOf', 'toEntity' => $holding]);
		$graph->upsertRelation(['fromEntity' => $jan, 'predicate' => 'authoredBy', 'toEntity' => $report]);
		$this->readable = ['rec-jan', 'rec-acme', 'file:7'];
		$this->store['contact']['rec-jan'] = ['name' => 'Jan Jansen', 'role' => 'buyer'];
		$this->store['contact']['rec-acme'] = ['name' => 'Acme', 'city' => 'Utrecht'];
		$this->store['contact']['rec-holding'] = ['name' => 'Secret Holding', 'owner' => 'nobody may know'];

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(40);
		$file->method('getContent')->willReturn('Q3 revenue grew by four percent.');
		$file->method('getPath')->willReturn('/alice/files/q3.md');
		$file->method('getMimetype')->willReturn('text/markdown');
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => ($id === 7) ? $file : null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$reader = new GraphSourceReader(
			$this->objects,
			$root,
			$this->createMock(MailReadService::class),
			$this->createMock(LoggerInterface::class)
		);

		return new GraphContextRetriever($graph, $reader, $session);

	}//end retriever()

	/**
	 * A query naming an entity assembles the visible neighbourhood: live record text for
	 * each visible node and the visible relations, nothing from the protected record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-a-graph-mode-turn-assembles-a-neighborhood
	 */
	public function testAGraphTurnAssemblesTheVisibleNeighbourhood(): void {
		$context = $this->retriever()->retrieve(query: 'What does Jan Jansen buy?', limit: 10);

		$this->assertNotNull($context);
		$texts = implode("\n", array_column($context['results'], 'text'));
		$this->assertStringContainsString('buyer', $texts);
		$this->assertStringContainsString('Utrecht', $texts);
		$this->assertStringContainsString('Q3 revenue grew', $texts);
		$this->assertSame(['object', 'object', 'file'], array_column($context['results'], 'entity_type'));
		$this->assertStringContainsString('Relations:', $context['relations']);
		$this->assertStringContainsString('Jan Jansen -[worksFor]-> Acme', $context['relations']);
		$this->assertStringContainsString('Jan Jansen -[authoredBy]-> Q3 report', $context['relations']);
		$this->assertStringNotContainsString('Holding', $texts . $context['relations']);
		$this->assertStringNotContainsString('nobody may know', $texts);

	}//end testAGraphTurnAssemblesTheVisibleNeighbourhood()

	/**
	 * The text is read when the turn runs, so an edit after extraction shows up.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-the-underlying-record-changes-after-extraction
	 */
	public function testTheRecordIsReadLive(): void {
		$retriever = $this->retriever();
		$this->store['contact']['rec-jan'] = ['name' => 'Jan Jansen', 'role' => 'head of purchasing'];

		$context = $retriever->retrieve(query: 'Jan Jansen', limit: 10);

		$this->assertStringContainsString('head of purchasing', (string)$context['results'][0]['text']);
		$this->assertStringNotContainsString('buyer', (string)$context['results'][0]['text']);

	}//end testTheRecordIsReadLive()

	/**
	 * No seed in the query, or only a hidden one, means no graph context (the caller
	 * then degrades to keyword retrieval).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-an-empty-graph-degrades-to-keyword-retrieval
	 */
	public function testNoVisibleSeedMeansNoGraphContext(): void {
		$retriever = $this->retriever();

		$this->assertNull($retriever->retrieve(query: 'What is the weather?', limit: 10));
		$this->assertNull($retriever->retrieve(query: 'Tell me about Secret Holding', limit: 10));

	}//end testNoVisibleSeedMeansNoGraphContext()
}//end class
