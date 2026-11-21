<?php

/**
 * Contract of AlgoritmekaderMapper
 *
 * The public surface of AlgoritmekaderMapper that other components call, so they depend
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
 * Contract of AlgoritmekaderMapper.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AlgoritmekaderMapperInterface {

	/**
	 * Assess publish-readiness, returning the list of failing conditions (empty = ready).
	 *
	 * Fail-closed: any missing/wrong condition is reported by a stable machine name — the
	 * risk gate (`riskCategory` when not `high`), the lifecycle gate (`lifecycle` when not
	 * `enabled`), the DPO gate (`dpoAcknowledgement` when unacknowledged), and one entry per
	 * absent mandatory Algoritmekader field. The caller renders these back so an operator
	 * knows exactly what to complete before the entry can be published.
	 *
	 * @param array<string, mixed> $feature The AiFeature payload.
	 *
	 * @return array<int, string> The failing condition names; an empty array means ready.
	 *
	 * @spec openspec/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function assessReadiness(array $feature): array;

	/**
	 * Map a ready `AiFeature` to the Algoritmekader publication shape.
	 *
	 * Produces an OpenCatalogi-publishable envelope (`title`, `summary`, `description`,
	 * `organization`) carrying the Dutch Algoritmekader metadata under `algoritmekader`
	 * (doel / wettelijke grondslag / impacttoetsen / databronnen / menselijke tussenkomst /
	 * verantwoordelijke / publicatiecategorie) plus provenance back to the hermiq
	 * governance record. The mapper does NOT check readiness here — the controller runs
	 * {@see assessReadiness()} first and refuses fail-closed, so a call to `map()` on an
	 * unready feature never happens on the publish path.
	 *
	 * @param array<string, mixed> $feature The AiFeature payload (assumed ready).
	 *
	 * @return array<string, mixed> The Algoritmekader-conformant publication.
	 *
	 * @spec openspec/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function map(array $feature): array;
}//end interface
