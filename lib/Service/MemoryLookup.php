<?php

/**
 * Hermiq MemoryLookup.
 *
 * One read MemoryService makes of a memory schema through OpenRegister: the
 * equality filters (which carry the caller scoping), the page size, and the
 * optional full-text search term.
 *
 * @category ValueObject
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/agent-memory/tasks.md#task-2-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One memory read.
 *
 * @spec openspec/changes/agent-memory/tasks.md#task-2-5
 */
final class MemoryLookup {

	/**
	 * The full-text search term, or null/'' for none.
	 *
	 * @var string|null
	 */
	public ?string $search = null;

	/**
	 * Describe the read.
	 *
	 * @param array<string, mixed> $filters The equality filters.
	 * @param int                  $limit   The most objects to return.
	 */
	public function __construct(
		public readonly array $filters,
		public readonly int $limit = 100,
	) {
	}//end __construct()

	/**
	 * The OpenRegister findAll() configuration: filters, limit and, when given, the search term.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/agent-memory/tasks.md#task-2-5
	 */
	public function config(): array {
		$config = [
			'filters' => $this->filters,
			'limit' => $this->limit,
		];
		if ($this->search !== null && $this->search !== '') {
			$config['search'] = $this->search;
		}

		return $config;
	}//end config()
}//end class
