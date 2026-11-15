<?php

/**
 * Unit tests for RetrievalSourceCollector — search hits turned into context
 * text and citable sources.
 *
 * The properties pinned here: per-type limits and switches are honoured,
 * the loop stops once both typed buckets are closed, untyped hits are never
 * counted against a limit, and every source carries a usable name and the
 * pointer its type needs (object uuid/register/schema, file id/path).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\RetrievalSourceCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the retrieval source collector.
 */
#[CoversClass(RetrievalSourceCollector::class)]
class RetrievalSourceCollectorTest extends TestCase {

	/**
	 * Build a collector with the given per-type switches and limits.
	 *
	 * @param bool $files       Whether file sources are included.
	 * @param bool $objects     Whether object sources are included.
	 * @param int  $fileLimit   Maximum file sources.
	 * @param int  $objectLimit Maximum object sources.
	 *
	 * @return RetrievalSourceCollector
	 */
	private function collector(bool $files=true, bool $objects=true, int $fileLimit=5, int $objectLimit=5): RetrievalSourceCollector {
		return new RetrievalSourceCollector(
			settings: [
				'include' => ['file' => $files, 'object' => $objects],
				'limit' => ['file' => $fileLimit, 'object' => $objectLimit],
			]
		);

	}//end collector()

	/**
	 * An object hit gets its pointer, register line and default similarity.
	 *
	 * @return void
	 */
	public function testObjectHitCarriesPointerAndRegisterLine(): void {
		$result = $this->collector()->collect(
			found: [
				'text' => "Intro\n",
				'results' => [
					[
						'entity_type' => 'object',
						'entity_id' => 'abc',
						'title' => 'Permit 12',
						'chunk_text' => 'Body',
						'register_name' => 'Permits',
						'metadata' => json_encode(['uuid' => 'u-1', 'register_id' => 7, 'schema' => 3, 'uri' => '/o/u-1']),
					],
				],
			]
		);

		$this->assertSame("Intro\nSource: Permit 12 (register: Permits)\nBody\n\n", $result['text']);
		$this->assertSame(0, $result['fileSources']);
		$this->assertSame(1, $result['objectSources']);
		$this->assertSame(
			[
				'id' => 'abc',
				'type' => 'object',
				'name' => 'Permit 12',
				'similarity' => 1.0,
				'text' => 'Body',
				'uuid' => 'u-1',
				'register' => 'Permits',
				'schema' => 3,
				'uri' => '/o/u-1',
			],
			$result['sources'][0]
		);

	}//end testObjectHitCarriesPointerAndRegisterLine()

	/**
	 * Without a register name the object pointer falls back to the metadata ids.
	 *
	 * @return void
	 */
	public function testObjectPointerFallsBackToMetadata(): void {
		$result = $this->collector()->collect(
			found: [
				'text' => '',
				'results' => [
					['entity_type' => 'object', 'entity_id' => 'x', 'name' => 'N', 'register_name' => '', 'metadata' => ['register' => 'r', 'schema_id' => 's']],
				],
			]
		);

		$this->assertSame("Source: N\n\n\n", $result['text']);
		$this->assertSame('', $result['sources'][0]['register']);
		$this->assertSame('s', $result['sources'][0]['schema']);
		$this->assertNull($result['sources'][0]['uuid']);

	}//end testObjectPointerFallsBackToMetadata()

	/**
	 * A file hit gets its file pointer, falling back to the entity id.
	 *
	 * @return void
	 */
	public function testFileHitCarriesFilePointer(): void {
		$result = $this->collector()->collect(
			found: [
				'text' => '',
				'results' => [
					['entity_type' => 'file', 'entity_id' => 42, 'text' => 'T', 'score' => 0.4, 'metadata' => ['file_name' => 'a.pdf', 'file_path' => '/a.pdf', 'mime_type' => 'application/pdf']],
					['entity_type' => 'file', 'entity_id' => 43, 'filename' => 'b.txt', 'metadata' => ['file_id' => 99]],
				],
			]
		);

		$this->assertSame(2, $result['fileSources']);
		$this->assertSame(['id' => 42, 'type' => 'file', 'name' => 'a.pdf', 'similarity' => 0.4, 'text' => 'T', 'file_id' => 42, 'file_path' => '/a.pdf', 'mime_type' => 'application/pdf'], $result['sources'][0]);
		$this->assertSame(99, $result['sources'][1]['file_id']);
		$this->assertSame('b.txt', $result['sources'][1]['name']);
		$this->assertNull($result['sources'][1]['file_path']);

	}//end testFileHitCarriesFilePointer()

	/**
	 * Names fall back from fields to metadata to the entity id to a placeholder.
	 *
	 * @return void
	 */
	public function testNameFallbacks(): void {
		$result = $this->collector()->collect(
			found: [
				'text' => '',
				'results' => [
					['entity_type' => 'note', 'entity_id' => '1234567890abc', 'metadata' => 'not json'],
					['entity_id' => 'zz'],
					['entity_type' => 'note', 'metadata' => json_encode(['object_title' => 'From meta'])],
					['entity_type' => 'note', 'metadata' => []],
				],
			]
		);

		$names = array_column($result['sources'], 'name');
		$this->assertSame(['Note #12345678', 'Item #zz', 'From meta', 'Unknown Source'], $names);
		$this->assertSame('unknown', $result['sources'][1]['type']);
		// Untyped hits are never counted against a limit.
		$this->assertSame(0, $result['fileSources']);
		$this->assertSame(0, $result['objectSources']);

	}//end testNameFallbacks()

	/**
	 * A switched-off type is skipped while other hits still come through.
	 *
	 * @return void
	 */
	public function testSwitchedOffTypeIsSkipped(): void {
		$result = $this->collector(files: false)->collect(
			found: [
				'text' => '',
				'results' => [
					['entity_type' => 'file', 'entity_id' => 1, 'title' => 'F'],
					['entity_type' => 'object', 'entity_id' => 2, 'title' => 'O'],
					['entity_type' => 'other', 'entity_id' => 3, 'title' => 'X'],
				],
			]
		);

		$this->assertSame(['O', 'X'], array_column($result['sources'], 'name'));
		$this->assertSame(0, $result['fileSources']);
		$this->assertSame(1, $result['objectSources']);

	}//end testSwitchedOffTypeIsSkipped()

	/**
	 * Collection stops as soon as both typed buckets hit their limit.
	 *
	 * @return void
	 */
	public function testStopsOnceBothBucketsAreFull(): void {
		$result = $this->collector(fileLimit: 1, objectLimit: 1)->collect(
			found: [
				'text' => '',
				'results' => [
					['entity_type' => 'file', 'entity_id' => 1, 'title' => 'F1'],
					['entity_type' => 'file', 'entity_id' => 2, 'title' => 'F2'],
					['entity_type' => 'object', 'entity_id' => 3, 'title' => 'O1'],
					['entity_type' => 'other', 'entity_id' => 4, 'title' => 'never reached'],
				],
			]
		);

		$this->assertSame(['F1', 'O1'], array_column($result['sources'], 'name'));
		$this->assertSame(1, $result['fileSources']);
		$this->assertSame(1, $result['objectSources']);

	}//end testStopsOnceBothBucketsAreFull()
}//end class
