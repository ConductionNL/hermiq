<?php

/**
 * Test stand-in for OpenRegister's VectorSearchFacade (vector-rag).
 *
 * Mirrors the published contract: isAvailable(), searchSemantic(), searchHybrid()
 * and embedTexts(), with the same parameter names, plus the optional
 * embeddingModelId(). Its own class name, so it never shadows the real facade;
 * tests hand its name to VectorSearch. Behaviour is set per test through the
 * public properties, and every call is recorded.
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
 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use RuntimeException;

/**
 * Configurable stand-in for the vector facade.
 */
class VectorSearchFacadeStub {

	/**
	 * Whether an embedding backend is configured.
	 *
	 * @var bool
	 */
	public bool $available = true;

	/**
	 * When set, every search and embed call throws this message.
	 *
	 * @var string|null
	 */
	public ?string $throw = null;

	/**
	 * The rows every search answers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Vectors by text; a text without one embeds to [0, 0].
	 *
	 * @var array<string, array<int, float>>
	 */
	public array $vectors = [];

	/**
	 * The embedding model id.
	 *
	 * @var string
	 */
	public string $model = 'nomic-embed-text';

	/**
	 * Every call, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * Whether an embedding backend is configured.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return $this->available;
	}//end isAvailable()

	/**
	 * Semantic search.
	 *
	 * @param string                 $query The question.
	 * @param int                    $limit At most this many rows.
	 * @param array<int, int|string> $views View ids.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchSemantic(string $query, int $limit = 10, array $views = []): array {
		return $this->answer(method: 'searchSemantic', arguments: ['query' => $query, 'limit' => $limit, 'views' => $views]);
	}//end searchSemantic()

	/**
	 * Hybrid search.
	 *
	 * @param string                 $query The question.
	 * @param int                    $limit At most this many rows.
	 * @param array<int, int|string> $views View ids.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchHybrid(string $query, int $limit = 10, array $views = []): array {
		return $this->answer(method: 'searchHybrid', arguments: ['query' => $query, 'limit' => $limit, 'views' => $views]);
	}//end searchHybrid()

	/**
	 * Embeddings of the texts.
	 *
	 * @param array<int, string> $texts The texts.
	 *
	 * @return array<int, array<int, float>>
	 */
	public function embedTexts(array $texts): array {
		$this->calls[] = ['embedTexts', ['texts' => $texts]];
		if ($this->throw !== null) {
			throw new RuntimeException($this->throw);
		}

		return array_map(fn (string $text): array => ($this->vectors[$text] ?? [0.0, 0.0]), $texts);
	}//end embedTexts()

	/**
	 * The embedding model id.
	 *
	 * @return string
	 */
	public function embeddingModelId(): string {
		return $this->model;
	}//end embeddingModelId()

	/**
	 * Record a search call and answer it.
	 *
	 * @param string               $method    The method.
	 * @param array<string, mixed> $arguments Its arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function answer(string $method, array $arguments): array {
		$this->calls[] = [$method, $arguments];
		if ($this->throw !== null) {
			throw new RuntimeException($this->throw);
		}

		return $this->rows;
	}//end answer()
}//end class
