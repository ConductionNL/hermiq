<?php

/**
 * The rules of an agent's validated mode.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\RetrievalScope;

use OCA\Hermiq\Service\RetrievalScope\ValidatedMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidatedMode::class)]
class ValidatedModeTest extends TestCase {

	public function testOnlyTrueSwitchesTheModeOn(): void {
		$this->assertTrue(ValidatedMode::isOn(['answerOnlyFromValidated' => true]));
		$this->assertFalse(ValidatedMode::isOn(['answerOnlyFromValidated' => 'true']));
		$this->assertFalse(ValidatedMode::isOn(['answerOnlyFromValidated' => 1]));
		$this->assertFalse(ValidatedMode::isOn([]));
	}//end testOnlyTrueSwitchesTheModeOn()

	public function testTheFloorIsTheAgentsBetweenZeroAndOneElseTheDefault(): void {
		$this->assertSame(0.55, ValidatedMode::floor(['ragMinSimilarity' => 0.55]));
		$this->assertSame(1.0, ValidatedMode::floor(['ragMinSimilarity' => 1]));
		$this->assertSame(0.5, ValidatedMode::floor(['ragMinSimilarity' => 1.5]));
		$this->assertSame(0.5, ValidatedMode::floor(['ragMinSimilarity' => -0.1]));
		$this->assertSame(0.5, ValidatedMode::floor(['ragMinSimilarity' => '0.9']));
		$this->assertSame(0.5, ValidatedMode::floor([]));
	}//end testTheFloorIsTheAgentsBetweenZeroAndOneElseTheDefault()

	public function testTheAbstentionIsTheAgentsMessageElseTheDefault(): void {
		$own = 'Dit kan ik niet beantwoorden op basis van de gevalideerde documenten.';
		$this->assertSame($own, ValidatedMode::abstention(['abstentionMessage' => '  ' . $own . ' ']));
		$this->assertSame(ValidatedMode::DEFAULT_ABSTENTION, ValidatedMode::abstention(['abstentionMessage' => '   ']));
		$this->assertSame(ValidatedMode::DEFAULT_ABSTENTION, ValidatedMode::abstention([]));
	}//end testTheAbstentionIsTheAgentsMessageElseTheDefault()

	public function testASessionCannotSwitchObjectsBackOnOrScopeAnotherFile(): void {
		$narrowed = ValidatedMode::narrowed(['includeObjects' => true, 'scope' => ['fileId' => 7], 'numSourcesFiles' => 3]);

		$this->assertSame(['includeObjects' => false, 'numSourcesFiles' => 3], $narrowed);
	}//end testASessionCannotSwitchObjectsBackOnOrScopeAnotherFile()

	public function testTheFileSetIsTheTaggedFilesWithinTheAgentsOwn(): void {
		$this->assertSame([3, 5], ValidatedMode::fileSet(null, [3, 5, 3]));
		$this->assertSame([5], ValidatedMode::fileSet([5, 9], [3, 5]));
		$this->assertSame([], ValidatedMode::fileSet([9], [3, 5]));
		$this->assertSame([], ValidatedMode::fileSet(null, []));
	}//end testTheFileSetIsTheTaggedFilesWithinTheAgentsOwn()

	public function testOnlyRowsAtOrAboveTheFloorPassAndTheBestIsKept(): void {
		$rows = [['similarity' => 0.41], ['score' => 0.55], ['_score' => 0.7], ['chunk_text' => 'no score'], 'not a row'];

		$result = ValidatedMode::aboveFloor($rows, 0.55);

		$this->assertSame([['score' => 0.55], ['_score' => 0.7]], $result['rows']);
		$this->assertSame(2, $result['passed']);
		$this->assertSame(0.7, $result['best']);
		$this->assertSame(['rows' => [], 'passed' => 0, 'best' => null], ValidatedMode::aboveFloor('none', 0.5));
	}//end testOnlyRowsAtOrAboveTheFloorPassAndTheBestIsKept()

	public function testTheRecordSaysAValidatedTurnWithNothingPassingAbstained(): void {
		$this->assertSame(
			['fileFilters' => null, 'validated' => true, 'floor' => 0.55, 'passed' => 0, 'bestSimilarity' => 0.41, 'abstained' => true],
			ValidatedMode::record(null, true, 0.55, 0, 0.41)
		);
		$this->assertFalse(ValidatedMode::record(['namePattern' => 'x'], false, null, 0, null)['abstained']);
		$this->assertFalse(ValidatedMode::record(null, true, 0.5, 1, 0.8)['abstained']);
	}//end testTheRecordSaysAValidatedTurnWithNothingPassingAbstained()
}//end class
