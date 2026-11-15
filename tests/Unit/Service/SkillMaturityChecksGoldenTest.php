<?php

/**
 * Golden tests for SkillMaturityService's level checks and its write guard.
 *
 * Pins the exact scorecard (every level's pass and reasons, in order) for payloads
 * that exercise every branch of the L1, L2 and L5 checks, frontmatter quoting and
 * the `files` name shapes, and the exact payload preserveComputedFields() returns,
 * so the checks can be split into named steps without changing a verdict.
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1-l3-mechanically-from-skill-content
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SkillMaturityService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the skill maturity checks.
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-skillmaturityservice-computes-l1-l3-mechanically-from-skill-content
 */
class SkillMaturityChecksGoldenTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @return SkillMaturityService
	 */
	private function service(): SkillMaturityService {
		return new SkillMaturityService($this->createMock(ObjectService::class));
	}//end service()

	/**
	 * Level => reasons for a payload, failed levels only, plus the folded level.
	 *
	 * @param array<string, mixed> $data The skill payload.
	 *
	 * @return array{0: int, 1: array<int, array<int, string>>}
	 */
	private function verdict(array $data): array {
		$result = $this->service()->computeScorecard($data);
		$failed = [];
		foreach ($result['scorecard'] as $row) {
			$this->assertSame($row['reasons'] === [], $row['passed']);
			if ($row['passed'] === false) {
				$failed[$row['level']] = $row['reasons'];
			}
		}

		return [$result['maturityLevel'], $failed];
	}//end verdict()

	/**
	 * L1: missing frontmatter skips the field checks; missing fields and an empty
	 * body each add their reason, in order.
	 *
	 * @return void
	 */
	public function testAnatomyReasons(): void {
		[$level, $failed] = $this->verdict(['frontmatter' => '  ', 'body' => '']);
		$this->assertSame(0, $level);
		$this->assertSame(['frontmatter is missing or empty', 'body is empty'], $failed[1]);

		[, $failed] = $this->verdict(['frontmatter' => "title: x\nname: \n", 'body' => 'b']);
		$this->assertSame(['frontmatter has no name', 'frontmatter has no description'], $failed[1]);

		[, $failed] = $this->verdict(['frontmatter' => ['not' => 'text'], 'body' => ['x']]);
		$this->assertSame(['frontmatter is missing or empty', 'body is empty'], $failed[1]);

		[, $failed] = $this->verdict(['frontmatter' => "name: \"\"\ndescription: ''", 'body' => 'b']);
		$this->assertSame(['frontmatter has no name', 'frontmatter has no description'], $failed[1]);

	}//end testAnatomyReasons()

	/**
	 * Frontmatter values lose one pair of matching quotes only, so a quoted empty
	 * value is empty while a lone or mismatched quote is a value; the description in
	 * the frontmatter wins over the payload's.
	 *
	 * @return void
	 */
	public function testFrontmatterQuotingDecidesTheFields(): void {
		$cases = [
			['""', true],
			["''", true],
			['"\'', false],
			["'\"", false],
			['"', false],
			['x', false],
			['"x"', false],
		];
		foreach ($cases as [$name, $empty]) {
			[, $failed] = $this->verdict(['frontmatter' => "name: " . $name . "\ndescription: d", 'body' => 'b']);
			$this->assertSame($empty, in_array('frontmatter has no name', ($failed[1] ?? []), true), $name);
		}

		[, $failed] = $this->verdict(['frontmatter' => 'name: n', 'description' => 'Use when the payload says so', 'body' => 'b']);
		$this->assertSame(['frontmatter has no description'], $failed[1]);
		$this->assertArrayNotHasKey(2, $failed);

		[, $failed] = $this->verdict(['frontmatter' => "name: n\ndescription: 'nothing'", 'description' => 'Use when the payload says so', 'body' => 'b']);
		$this->assertSame(['description does not start with trigger phrasing', 'description has no when-to-use phrasing'], $failed[2]);

	}//end testFrontmatterQuotingDecidesTheFields()

	/**
	 * L2: each triggering reason, in order, and the references/ escape for a large body.
	 *
	 * @return void
	 */
	public function testTriggeringReasons(): void {
		$long = implode("\n", array_fill(0, 600, 'line'));
		$mid = implode("\n", array_fill(0, 300, 'line'));
		$base = ['frontmatter' => 'name: n', 'body' => $long];

		[, $failed] = $this->verdict($base + ['description' => 'nothing']);
		$this->assertSame(
			[
				'description does not start with trigger phrasing',
				'description has no when-to-use phrasing',
				'body is 500 lines or more',
				'large body has no references/ entries (no progressive disclosure)',
			],
			$failed[2]
		);

		[, $failed] = $this->verdict(['frontmatter' => 'name: n', 'body' => $mid, 'description' => 'Use when x', 'files' => ['references/a.md' => 'x']]);
		$this->assertArrayNotHasKey(2, $failed);

		[, $failed] = $this->verdict(['frontmatter' => 'name: n', 'body' => $mid, 'description' => 'Use when x', 'files' => [['name' => 'references/a.md'], ['name' => ['x']], 7 => 'y']]);
		$this->assertArrayNotHasKey(2, $failed);

		[, $failed] = $this->verdict(['frontmatter' => 'name: n', 'body' => $mid, 'description' => 'Use when x', 'files' => 'references/a.md']);
		$this->assertSame(['large body has no references/ entries (no progressive disclosure)'], $failed[2]);

		[, $failed] = $this->verdict(['frontmatter' => 'name: n', 'body' => $mid, 'description' => 'Use when x', 'files' => [3 => ['path' => 'references/a.md'], 'examples/b.md' => []]]);
		$this->assertSame(['large body has no references/ entries (no progressive disclosure)'], $failed[2]);
		$this->assertArrayNotHasKey(3, $failed);

	}//end testTriggeringReasons()

	/**
	 * L5: empty, incomplete and complete eval evidence.
	 *
	 * @return void
	 */
	public function testEvalEvidence(): void {
		$complete = ['evalDatasetId' => 'd', 'passRate' => '0.9', 'baselineDelta' => -0.1, 'lastValidated' => '2026-01-01'];
		$cases = [
			[[], ['no eval evidence (levelEvidence.l5 empty)']],
			[$complete, null],
			[['evalDatasetId' => 7] + $complete, null],
			[['passRate' => 'high'] + $complete, ['incomplete eval evidence (levelEvidence.l5)']],
			[['baselineDelta' => null] + $complete, ['incomplete eval evidence (levelEvidence.l5)']],
			[['lastValidated' => ''] + $complete, ['incomplete eval evidence (levelEvidence.l5)']],
			[['evalDatasetId' => ['x']] + $complete, ['incomplete eval evidence (levelEvidence.l5)']],
			[array_diff_key($complete, ['passRate' => 1]), ['incomplete eval evidence (levelEvidence.l5)']],
		];
		foreach ($cases as $index => [$l5, $reasons]) {
			[, $failed] = $this->verdict(['levelEvidence' => ['l5' => $l5]]);
			$this->assertSame($reasons, ($failed[5] ?? null), 'case ' . $index);
		}

	}//end testEvalEvidence()

	/**
	 * The write guard drops client-set computed fields and restores the stored ones,
	 * and adds levelEvidence only when there is any or the client sent it.
	 *
	 * @return void
	 */
	public function testPreserveComputedFieldsRestoresTheStoredValues(): void {
		$service = $this->service();
		$stored = ['maturityLevel' => 3, 'levelEvidence' => ['l1' => 'S1', 'l4' => 'S4', 'l5' => 'S5', 'l6' => 'S6']];

		$this->assertSame(
			['name' => 'x', 'levelEvidence' => ['l5' => 'C5', 'l7' => 'C7', 'l1' => 'S1', 'l4' => 'S4', 'l6' => 'S6'], 'maturityLevel' => 3],
			$service->preserveComputedFields(
				['maturityLevel' => 7, 'name' => 'x', 'levelEvidence' => ['l1' => 'C1', 'l2' => 'C2', 'l5' => 'C5', 'l6' => 'C6', 'l7' => 'C7']],
				$stored
			)
		);
		$this->assertSame(['name' => 'x'], $service->preserveComputedFields(['name' => 'x', 'maturityLevel' => 5], []));
		$this->assertSame(['name' => 'x', 'levelEvidence' => []], $service->preserveComputedFields(['name' => 'x', 'levelEvidence' => ['l2' => 'C2']], []));
		$this->assertSame(['name' => 'x', 'levelEvidence' => []], $service->preserveComputedFields(['name' => 'x', 'levelEvidence' => 'junk'], ['levelEvidence' => 'junk']));
		$this->assertSame(['levelEvidence' => ['l3' => 'S3']], $service->preserveComputedFields([], ['levelEvidence' => ['l3' => 'S3']]));

	}//end testPreserveComputedFieldsRestoresTheStoredValues()
}//end class
