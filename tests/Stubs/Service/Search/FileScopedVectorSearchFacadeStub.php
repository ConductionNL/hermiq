<?php

/**
 * Test stand-in for a VectorSearchFacade whose searches take a file scope.
 *
 * The published facade's searchSemantic() and searchHybrid() take no `fileIds`;
 * this stub adds that argument, so a test can show what VectorSearch hands a
 * facade that has a file scope (memory-retrieval-scope).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * The vector facade stub with a `fileIds` argument on both searches.
 */
class FileScopedVectorSearchFacadeStub extends VectorSearchFacadeStub {

	/**
	 * Semantic search, optionally within a file set.
	 *
	 * @param string                 $query   The question.
	 * @param int                    $limit   At most this many rows.
	 * @param array<int, int|string> $views   View ids.
	 * @param array<int, int>        $fileIds The files in scope; empty for no file scope.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchSemantic(string $query, int $limit=10, array $views=[], array $fileIds=[]): array {
		$this->calls[] = ['searchSemantic', ['query' => $query, 'limit' => $limit, 'views' => $views, 'fileIds' => $fileIds]];

		return $this->rows;
	}//end searchSemantic()

	/**
	 * Hybrid search, optionally within a file set.
	 *
	 * @param string                 $query   The question.
	 * @param int                    $limit   At most this many rows.
	 * @param array<int, int|string> $views   View ids.
	 * @param array<int, int>        $fileIds The files in scope; empty for no file scope.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchHybrid(string $query, int $limit=10, array $views=[], array $fileIds=[]): array {
		$this->calls[] = ['searchHybrid', ['query' => $query, 'limit' => $limit, 'views' => $views, 'fileIds' => $fileIds]];

		return $this->rows;
	}//end searchHybrid()
}//end class
