<?php

/**
 * Contract of AiOversightService
 *
 * The public surface of AiOversightService that other components call, so they depend
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
 * Contract of AiOversightService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiOversightServiceInterface {

	/**
	 * List the advisory Approvals about one subject, newest decision first.
	 *
	 * Reads WITHOUT OpenRegister RBAC, because the schema keeps advisory rows
	 * to the oversight role. The caller MUST have decided access first through
	 * AiOversightAccess::maySeeSubject(); this method does no access check.
	 * Every returned row is checked against the requested subject again, so a
	 * filter OpenRegister ignored can never widen the answer. Records stored
	 * under a former id of the origin app are included.
	 *
	 * @param string $originApp The origin app id.
	 * @param string $subjectId The subject's id in that app.
	 * @param string|null $subjectType The subject type, or null for any.
	 * @param integer $limit Page size.
	 * @param integer $offset Paging offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: integer}
	 *
	 * @spec openspec/specs/ai-oversight/spec.md
	 */
	public function listForSubject(
		string $originApp,
		string $subjectId,
		?string $subjectType = null,
		int $limit = 25,
		int $offset = 0,
	): array;

	/**
	 * Record one advisory decision.
	 *
	 * @param array<string, mixed> $record The decision record; see AiOversightRecordedEvent.
	 *
	 * @return string|null The written Approval's uuid, or null when the record was refused.
	 *
	 * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md
	 */
	public function record(array $record): ?string;
}//end interface
