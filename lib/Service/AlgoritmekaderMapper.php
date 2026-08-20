<?php

/**
 * Hermiq AlgoritmekaderMapper.
 *
 * Turns a design-time `AiFeature` governance record into the Dutch **Algoritmekader**
 * publication shape required for a national Algoritmeregister entry, and — before it maps
 * anything — enforces the publish-readiness gate. Only an *impactful* (`riskCategory =
 * high`), `enabled`, DPO-acknowledged feature whose every mandatory Algoritmekader field is
 * present MAY be published; every other case is refused fail-closed and the missing/failing
 * conditions are named back to the caller, so hermiq never emits a partial or placeholder
 * national-register entry. The mapper holds NO transport: it does not open a connection to
 * algoritmes.overheid.nl and does not know about OpenCatalogi — the delegation is the
 * controller's job through {@see PublicationGateway}. This class is pure (readiness +
 * mapping), which is what makes the readiness matrix cheap to unit-test.
 *
 * @category Service
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
 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Publish-readiness gate + Algoritmekader mapping for AiFeature records.
 *
 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
 */
class AlgoritmekaderMapper {

	/**
	 * The only risk category in scope for the national Algoritmeregister (impactful).
	 *
	 * @var string
	 */
	public const RISK_PUBLISHABLE = 'high';

	/**
	 * The lifecycle state a feature must be in to be published.
	 *
	 * @var string
	 */
	public const LIFECYCLE_ENABLED = 'enabled';

	/**
	 * The mandatory Algoritmekader metadata fields that must be present to publish.
	 *
	 * @var array<int, string>
	 */
	public const MANDATORY_FIELDS = [
		'doel',
		'statutoryBasis',
		'impactAssessments',
		'dataBronnen',
		'humanIntervention',
		'responsible',
		'publicatiecategorie',
	];

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
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function assessReadiness(array $feature): array {
		$failing = [];

		if ((string)($feature['riskCategory'] ?? '') !== self::RISK_PUBLISHABLE) {
			// Only impactful (high-risk) features belong in the national register.
			$failing[] = 'riskCategory';
		}

		if ((string)($feature['lifecycle'] ?? '') !== self::LIFECYCLE_ENABLED) {
			$failing[] = 'lifecycle';
		}

		if ($this->isDpoAcknowledged(feature: $feature) === false) {
			$failing[] = 'dpoAcknowledgement';
		}

		foreach (self::MANDATORY_FIELDS as $field) {
			if ($this->hasValue(value: ($feature[$field] ?? null)) === false) {
				$failing[] = $field;
			}
		}

		return $failing;
	}//end assessReadiness()

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
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function map(array $feature): array {
		$responsible = [];
		if (is_array(($feature['responsible'] ?? null)) === true) {
			$responsible = $feature['responsible'];
		}

		$impactAssessments = [];
		if (is_array(($feature['impactAssessments'] ?? null)) === true) {
			$impactAssessments = array_values($feature['impactAssessments']);
		}

		return [
			'title' => (string)($feature['name'] ?? ($feature['slug'] ?? '')),
			'summary' => (string)($feature['doel'] ?? ''),
			'description' => (string)($feature['description'] ?? ''),
			'organization' => (string)($responsible['organisation'] ?? ''),
			'algoritmekader' => [
				'doel' => (string)($feature['doel'] ?? ''),
				'statutoryBasis' => (string)($feature['statutoryBasis'] ?? ''),
				'impactAssessments' => $impactAssessments,
				'dataBronnen' => (string)($feature['dataBronnen'] ?? ''),
				'humanIntervention' => (string)($feature['humanIntervention'] ?? ''),
				'responsible' => $responsible,
				'publicatiecategorie' => (string)($feature['publicatiecategorie'] ?? ''),
				'riskCategory' => (string)($feature['riskCategory'] ?? ''),
			],
			'source' => [
				'app' => 'hermiq',
				'slug' => (string)($feature['slug'] ?? ''),
			],
		];

	}//end map()

	/**
	 * Whether the DPO acknowledgement is recorded on the feature (mirror stamp).
	 *
	 * @param array<string, mixed> $feature The AiFeature payload.
	 *
	 * @return bool True when acknowledged.
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	private function isDpoAcknowledged(array $feature): bool {
		return trim((string)($feature['dpoAckBy'] ?? '')) !== ''
			&& trim((string)($feature['dpoAckAt'] ?? '')) !== '';

	}//end isDpoAcknowledged()

	/**
	 * Whether a mandatory field carries a non-empty value (string, non-empty array, or object).
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return bool True when the value counts as present.
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	private function hasValue(mixed $value): bool {
		if ($value === null) {
			return false;
		}

		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		if (is_array($value) === true) {
			return $value !== [];
		}

		return true;
	}//end hasValue()
}//end class
