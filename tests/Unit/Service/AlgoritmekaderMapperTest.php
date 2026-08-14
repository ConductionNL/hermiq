<?php

/**
 * Unit tests for AlgoritmekaderMapper (algoritmeregister-publication).
 *
 * Exercises the publish-readiness matrix — a feature is publishable ONLY when it is
 * high-risk, enabled, DPO-acknowledged, and carries every mandatory Algoritmekader field;
 * every other case is refused fail-closed with the failing conditions named — and the
 * mapping to the Algoritmekader publication shape.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AlgoritmekaderMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the publish-readiness gate + Algoritmekader mapping.
 *
 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
 */
class AlgoritmekaderMapperTest extends TestCase {

	/**
	 * A fully publish-ready AiFeature payload.
	 *
	 * @return array<string, mixed>
	 */
	private function readyFeature(): array {
		return [
			'slug' => 'autonomous-agent-run',
			'name' => 'Autonomous agent run',
			'description' => 'Runs agents on a schedule.',
			'riskCategory' => 'high',
			'lifecycle' => 'enabled',
			'dpoAckBy' => 'dpo',
			'dpoAckAt' => '2026-07-07T10:00:00+00:00',
			'doel' => 'Automate casework triage.',
			'wettelijkeGrondslag' => 'Art. 6 AVG.',
			'impacttoetsen' => [['soort' => 'DPIA', 'referentie' => 'DPIA-2026-01']],
			'dataBronnen' => 'Case register.',
			'menselijkeTussenkomst' => 'Human approval gate before any action.',
			'verantwoordelijke' => ['organisatie' => 'Gemeente Zeist', 'contact' => 'privacy@zeist.nl'],
			'publicatiecategorie' => 'Impactful algorithm',
		];

	}//end readyFeature()

	/**
	 * A ready feature passes every condition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function testReadyFeatureIsReady(): void {
		$mapper = new AlgoritmekaderMapper();
		$this->assertSame([], $mapper->assessReadiness($this->readyFeature()));

	}//end testReadyFeatureIsReady()

	/**
	 * A limited-risk feature is refused as out of scope (only high risk publishes).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function testLimitedRiskIsRefused(): void {
		$feature = $this->readyFeature();
		$feature['riskCategory'] = 'limited';

		$mapper = new AlgoritmekaderMapper();
		$failing = $mapper->assessReadiness($feature);

		$this->assertNotEmpty($failing);
		$this->assertContains('riskCategory', $failing);

	}//end testLimitedRiskIsRefused()

	/**
	 * A not-enabled feature is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function testNotEnabledIsRefused(): void {
		$feature = $this->readyFeature();
		$feature['lifecycle'] = 'disabled';

		$this->assertContains('lifecycle', (new AlgoritmekaderMapper())->assessReadiness($feature));

	}//end testNotEnabledIsRefused()

	/**
	 * A feature the DPO has not acknowledged is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function testUnacknowledgedIsRefused(): void {
		$feature = $this->readyFeature();
		unset($feature['dpoAckBy'], $feature['dpoAckAt']);

		$this->assertContains('dpoAcknowledgement', (new AlgoritmekaderMapper())->assessReadiness($feature));

	}//end testUnacknowledgedIsRefused()

	/**
	 * A missing mandatory Algoritmekader field is named in the failing conditions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function testMissingLegalBasisIsNamed(): void {
		$feature = $this->readyFeature();
		unset($feature['wettelijkeGrondslag']);

		$failing = (new AlgoritmekaderMapper())->assessReadiness($feature);
		$this->assertContains('wettelijkeGrondslag', $failing);

	}//end testMissingLegalBasisIsNamed()

	/**
	 * An empty impacttoetsen array counts as missing (non-empty required).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function testEmptyImpactAssessmentsIsMissing(): void {
		$feature = $this->readyFeature();
		$feature['impacttoetsen'] = [];

		$this->assertContains('impacttoetsen', (new AlgoritmekaderMapper())->assessReadiness($feature));

	}//end testEmptyImpactAssessmentsIsMissing()

	/**
	 * A blank string mandatory field counts as missing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function testBlankMandatoryFieldIsMissing(): void {
		$feature = $this->readyFeature();
		$feature['doel'] = '   ';

		$this->assertContains('doel', (new AlgoritmekaderMapper())->assessReadiness($feature));

	}//end testBlankMandatoryFieldIsMissing()

	/**
	 * The mapping produces the Algoritmekader publication shape with the metadata carried.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testMapProducesAlgoritmekaderShape(): void {
		$mapped = (new AlgoritmekaderMapper())->map($this->readyFeature());

		$this->assertSame('Autonomous agent run', $mapped['title']);
		$this->assertSame('Gemeente Zeist', $mapped['organization']);
		$this->assertSame('Art. 6 AVG.', $mapped['algoritmekader']['wettelijkeGrondslag']);
		$this->assertSame('high', $mapped['algoritmekader']['riskCategory']);
		$this->assertCount(1, $mapped['algoritmekader']['impacttoetsen']);
		$this->assertSame('hermiq', $mapped['source']['app']);
		$this->assertSame('autonomous-agent-run', $mapped['source']['slug']);

	}//end testMapProducesAlgoritmekaderShape()
}//end class
