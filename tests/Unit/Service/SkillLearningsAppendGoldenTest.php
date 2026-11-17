<?php

/**
 * Golden test for SkillLearningsPromotionService::appendToLearnings().
 *
 * Pins exactly where each promoted entry lands in a learnings file: at the end of
 * its own section (before the next heading, above trailing blank lines), at the end
 * of the file under a new heading when the section is missing, never for an unknown
 * section, and with its provenance comment, so the insertion can be rewritten without
 * moving a line.
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
 * @spec openspec/specs/skill-learnings/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\Hermiq\Service\SkillLearningsPromotionService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden test for appending promoted learnings.
 *
 * @spec openspec/specs/skill-learnings/spec.md
 */
class SkillLearningsAppendGoldenTest extends TestCase {

	/**
	 * The service on a frozen clock.
	 *
	 * @return SkillLearningsPromotionService
	 */
	private function service(): SkillLearningsPromotionService {
		return new class($this->createMock(ObjectService::class), $this->createMock(SkillLearningsCaptureService::class), new NullLogger()) extends SkillLearningsPromotionService {
			/**
			 * @return DateTimeImmutable
			 */
			protected function now(): DateTimeImmutable {
				return new DateTimeImmutable('2026-07-27T12:00:00+00:00', new DateTimeZone('UTC'));
			}//end now()
		};
	}//end service()

	/**
	 * One promoted candidate.
	 *
	 * @param string $section The section tag.
	 * @param string $text The learning.
	 * @param string $evalFail The eval failure, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function promoted(string $section, string $text, string $evalFail = ''): array {
		return ['section' => $section, 'text' => $text, 'runs' => ['r1', 'r2', 'r1'], 'evalFail' => $evalFail];
	}//end promoted()

	/**
	 * Entries land at the end of their own section, above its trailing blank lines and
	 * before the next heading; a missing section is appended at the end of the file;
	 * an unknown section is skipped.
	 *
	 * @return void
	 */
	public function testEachEntryLandsAtTheEndOfItsSection(): void {
		$content = implode(
			"\n",
			[
				'# Learnings',
				'',
				'## Patterns That Work',
				'- old pattern',
				'',
				'',
				'## Mistakes to Avoid',
				'## Domain Knowledge',
				'- old fact',
			]
		) . "\n\n";

		$result = $this->service()->appendToLearnings(
			$content,
			[
				$this->promoted('patterns', 'P1'),
				$this->promoted('mistakes', 'M1', 'case 3 failed'),
				$this->promoted('domain', 'D1'),
				$this->promoted('questions', 'Q1'),
				$this->promoted('nonsense', 'X1'),
				$this->promoted('patterns', 'P2'),
			]
		);

		$this->assertSame(
			implode(
				"\n",
				[
					'# Learnings',
					'',
					'## Patterns That Work',
					'- old pattern',
					'- P1 <!-- promoted 2026-07-27 | runs: r1,r2 -->',
					'- P2 <!-- promoted 2026-07-27 | runs: r1,r2 -->',
					'',
					'',
					'## Mistakes to Avoid',
					'- M1 <!-- promoted 2026-07-27 | runs: r1,r2 | eval-fail: case 3 failed -->',
					'## Domain Knowledge',
					'- old fact',
					'- D1 <!-- promoted 2026-07-27 | runs: r1,r2 -->',
					'',
					'## Open Questions',
					'- Q1 <!-- promoted 2026-07-27 | runs: r1,r2 -->',
				]
			) . "\n",
			$result
		);

	}//end testEachEntryLandsAtTheEndOfItsSection()

	/**
	 * An empty file starts from the scaffold, and nothing promoted leaves it as is.
	 *
	 * @return void
	 */
	public function testAnEmptyFileStartsFromTheScaffold(): void {
		$service = $this->service();
		$scaffold = $service->emptyLearningsScaffold();

		$this->assertSame($scaffold, $service->appendToLearnings('  ', []));
		$this->assertSame(
			str_replace("## Domain Knowledge\n", "## Domain Knowledge\n- D1 <!-- promoted 2026-07-27 | runs: r1,r2 -->\n", $scaffold),
			$service->appendToLearnings('', [$this->promoted('domain', 'D1')])
		);

	}//end testAnEmptyFileStartsFromTheScaffold()
}//end class
