<?php

/**
 * Contract of GraphExtractionService
 *
 * The public surface of GraphExtractionService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of GraphExtractionService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GraphExtractionServiceInterface {

	/**
	 * Extract a batch as the user who enqueued it.
	 *
	 * @param string $uid The enqueueing user.
	 * @param array<int, array<string, mixed>> $sources Each {sourceType, sourceRef}.
	 *
	 * @return array{entities: int, relations: int, skipped: int} What was written, and how many records could not be read.
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-extraction-cannot-read-beyond-its-user
	 */
	public function extract(string $uid, array $sources): array;
}//end interface
