<?php

/**
 * Contract of ReportSimilarityService
 *
 * The public surface of ReportSimilarityService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ReportSimilarityService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportSimilarityServiceInterface {

	/**
	 * Answer which group a report belongs to, or that it starts one.
	 *
	 * Nothing outside hermiq is created, merged, closed or deleted by this call,
	 * and the answer deliberately carries no instruction about acknowledgement:
	 * Awb 4:3a owes every electronic request a confirmation of receipt, and two
	 * hundred people who wrote to the gemeente are owed two hundred confirmations
	 * whatever a handler's screen shows them as.
	 *
	 * @param string $reportId The owning app's identifier for the incoming report.
	 * @param string $reportType The report type, as the owning app names it.
	 * @param string $text The report's text.
	 * @param string $deterministicKey The owning app's key for this report, when it has one.
	 * @param DateTimeImmutable|null $now The moment of evaluation, for a deterministic test.
	 *
	 * @return array<string, mixed> The group, the score, what decided, and why.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-two-hundred-reports-of-one-power-cut-answer-as-one-group
	 */
	public function evaluate(
		string $reportId,
		string $reportType,
		string $text,
		string $deterministicKey = '',
		?DateTimeImmutable $now = null,
	): array;

	/**
	 * One group, with its reasons: the terms, the window, and per member the score
	 * and what decided it.
	 *
	 * @param string $groupId The group uuid.
	 *
	 * @return array<string, mixed>|null The group, or null.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-why-these-are-one-thing-is-answerable
	 */
	public function group(string $groupId): ?array;

	/**
	 * Take one report out of a group. It stands alone again, unchanged, and the
	 * group's count moves. Nothing was discarded to form the group, so nothing has
	 * to be recovered to undo it.
	 *
	 * @param string $groupId The group uuid.
	 * @param string $reportId The report to remove.
	 *
	 * @return array<string, mixed>|null The group as it now stands, or null when there is no such group.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-pulling-one-report-out-costs-nothing
	 */
	public function removeMember(string $groupId, string $reportId): ?array;
}//end interface
