<?php

/**
 * Pins which tokens TermOverlapScorer::terms() keeps, before its split.
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

use OCA\Hermiq\Service\ReportSimilarity\TermOverlapScorer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\ReportSimilarity\TermOverlapScorer
 */
class TermOverlapScorerTermsTest extends TestCase {

	/**
	 * Short tokens, stop words and digit runs are dropped; each term counts once, in first-seen order.
	 *
	 * @return void
	 */
	public function testOnlySignificantTermsAreKeptOnce(): void {
		$terms = (new TermOverlapScorer())->terms(
			"  Een LANTAARNPAAL is kapot, bij de Kerkstraat! Bel 0612345678 of 112.\nLantaarnpaal staat scheef; the lamp-post fell and we called. ab x9 b12 Straße ÉÉN"
		);

		$this->assertSame(
			['lantaarnpaal', 'kapot', 'kerkstraat', 'bel', 'staat', 'scheef', 'lamp', 'post', 'fell', 'called', 'b12', 'straße', 'één'],
			$terms
		);
	}//end testOnlySignificantTermsAreKeptOnce()

	/**
	 * A blank text has no terms.
	 *
	 * @return void
	 */
	public function testABlankTextHasNoTerms(): void {
		$this->assertSame([], (new TermOverlapScorer())->terms(" \n\t "));
	}//end testABlankTextHasNoTerms()
}//end class
