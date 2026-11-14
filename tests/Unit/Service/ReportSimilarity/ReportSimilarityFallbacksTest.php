<?php

/**
 * Pins the fallbacks of the report-similarity settings and the term-overlap
 * score: unusable stored values fall back to the defaults, and a text without
 * significant terms scores zero.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ReportSimilarity
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ReportSimilarity;

use OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings;
use OCA\Hermiq\Service\ReportSimilarity\TermOverlapScorer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings
 * @covers \OCA\Hermiq\Service\ReportSimilarity\TermOverlapScorer
 */
class ReportSimilarityFallbacksTest extends TestCase {

	/**
	 * Settings over an app config that holds the given raw JSON.
	 *
	 * @param string $raw The stored value.
	 *
	 * @return ReportSimilaritySettings
	 */
	private function settings(string $raw): ReportSimilaritySettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('hermiq', 'reportSimilarity', '')->willReturn($raw);

		return new ReportSimilaritySettings($config);
	}//end settings()

	/**
	 * Stored JSON that is not an object, or not JSON at all, yields every default.
	 *
	 * @return void
	 */
	public function testStoredValueThatIsNotAnObjectYieldsTheDefaults(): void {
		foreach (['"just a string"', '42', 'not json'] as $raw) {
			$settings = $this->settings($raw);

			$this->assertSame(
				[ReportSimilaritySettings::DEFAULT_UPPER, ReportSimilaritySettings::DEFAULT_LOWER, ReportSimilaritySettings::DEFAULT_WINDOW_MINUTES],
				[$settings->upper(), $settings->lower(), $settings->windowMinutes('incident')],
				$raw
			);
		}
	}//end testStoredValueThatIsNotAnObjectYieldsTheDefaults()

	/**
	 * A threshold outside 0..1 falls back to its default; the bounds themselves are kept.
	 *
	 * @return void
	 */
	public function testThresholdsOutsideTheUnitRangeFallBack(): void {
		$outside = $this->settings((string)json_encode(['upper' => 1.5, 'lower' => -0.1]));
		$this->assertSame(ReportSimilaritySettings::DEFAULT_UPPER, $outside->upper());
		$this->assertSame(ReportSimilaritySettings::DEFAULT_LOWER, $outside->lower());

		$bounds = $this->settings((string)json_encode(['upper' => 1, 'lower' => 0]));
		$this->assertSame(1.0, $bounds->upper());
		$this->assertSame(0.0, $bounds->lower());
	}//end testThresholdsOutsideTheUnitRangeFallBack()

	/**
	 * Windows stored as something other than a map fall back to the default window.
	 *
	 * @return void
	 */
	public function testWindowsThatAreNotAMapFallBack(): void {
		$settings = $this->settings((string)json_encode(['windows' => 'incident:30']));

		$this->assertSame(ReportSimilaritySettings::DEFAULT_WINDOW_MINUTES, $settings->windowMinutes('incident'));
	}//end testWindowsThatAreNotAMapFallBack()

	/**
	 * A side without significant terms scores zero with no shared terms.
	 *
	 * @return void
	 */
	public function testATextWithoutSignificantTermsScoresZero(): void {
		$scorer = new TermOverlapScorer();
		$empty  = ['score' => 0.0, 'terms' => [], 'scorer' => 'term-overlap'];

		$this->assertSame($empty, $scorer->score('de en 112 ab', 'Lantaarnpaal kapot'));
		$this->assertSame($empty, $scorer->score('Lantaarnpaal kapot', ''));
	}//end testATextWithoutSignificantTermsScoresZero()

	/**
	 * The score is shared terms over all terms, rounded to four places, with the shared terms sorted.
	 *
	 * @return void
	 */
	public function testScoreIsTheJaccardOverlapOfTheTerms(): void {
		$result = (new TermOverlapScorer())->score(
			'Lantaarnpaal kapot in de Kerkstraat',
			'Kerkstraat: lantaarnpaal staat scheef, niet kapot'
		);

		// Shared: kapot, kerkstraat, lantaarnpaal (3); union adds staat, scheef (5).
		$this->assertSame(
			['score' => 0.6, 'terms' => ['kapot', 'kerkstraat', 'lantaarnpaal'], 'scorer' => 'term-overlap'],
			$result
		);

		$third = (new TermOverlapScorer())->score('alpha beta gamma', 'alpha delta epsilon');
		$this->assertSame(0.2, $third['score']);
	}//end testScoreIsTheJaccardOverlapOfTheTerms()
}//end class
