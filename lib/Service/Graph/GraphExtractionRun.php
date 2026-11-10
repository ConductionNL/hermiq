<?php

/**
 * Hermiq GraphExtractionRun.
 *
 * One source's extraction write: the source every node and edge is attributed
 * to, and the entity uuids written so far by their lower-cased label, so the
 * relations can find their ends. Built and read only inside
 * GraphExtractionService.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

/**
 * One source's extraction write.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
final class GraphExtractionRun {

	/**
	 * The written entities' uuids by lower-cased label.
	 *
	 * @var array<string, mixed>
	 */
	public array $byLabel = [];

	/**
	 * Constructor.
	 *
	 * @param string               $sourceType The source's type.
	 * @param array<string, mixed> $sourceRef  The source's reference.
	 */
	public function __construct(
		public readonly string $sourceType,
		public readonly array $sourceRef,
	) {
	}//end __construct()
}//end class
