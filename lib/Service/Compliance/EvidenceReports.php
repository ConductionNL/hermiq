<?php

/**
 * Hermiq EvidenceReports.
 *
 * The compliance evidence that comes from a report of its own rather than from a
 * governance seam `ComplianceService` reads directly: the AI literacy completions
 * (compliance-ai-literacy, article 4) and the bias checks (reuse-scheduled-bias-evals).
 * Each report is optional; a missing one reads as `unevidenced`, never as a pass.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Compliance
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Compliance;

use OCA\Hermiq\Service\Eval\BiasCheckEvidence;
use OCA\Hermiq\Service\Literacy\LiteracyReport;

/**
 * The report-backed compliance evidence: AI literacy and bias checks.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */
class EvidenceReports {

	/**
	 * Constructor.
	 *
	 * @param LiteracyReport|null    $literacyReport    The article 4 evidence (compliance-ai-literacy).
	 * @param BiasCheckEvidence|null $biasCheckEvidence The bias-testing evidence and the latest bias check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function __construct(
		private readonly ?LiteracyReport $literacyReport = null,
		private readonly ?BiasCheckEvidence $biasCheckEvidence = null,
	) {
	}//end __construct()

	/**
	 * The `ai-literacy-completion` evidence for an organisation.
	 *
	 * @param string $organisation The organisation identifier.
	 *
	 * @return array{status: string, detail: string}
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-the-ai-act-article-4-control-reads-its-status-from-completions-req-ailit-003
	 */
	public function literacy(string $organisation): array {
		return $this->literacyReport?->evidence(organisation: $organisation)
			?? ['status' => 'unevidenced', 'detail' => 'The AI literacy report is not available.'];
	}//end literacy()

	/**
	 * The `bias-eval-recency` evidence.
	 *
	 * @return array{status: string, detail: string}
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function biasTesting(): array {
		return $this->biasCheckEvidence?->evidence()
			?? ['status' => 'unevidenced', 'detail' => 'The bias check evidence is not available.'];
	}//end biasTesting()

	/**
	 * The agent's latest bias check for its factsheet, or null for "No bias check run".
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function latestBiasCheck(string $agentId): ?array {
		return $this->biasCheckEvidence?->latestFor(agentId: $agentId);
	}//end latestBiasCheck()
}//end class
