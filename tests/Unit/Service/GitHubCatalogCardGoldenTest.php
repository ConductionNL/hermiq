<?php

/**
 * Golden test of the store card a GitHub search hit becomes.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubCatalogPayload;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * Every card outcome, with its exact fields in their exact order.
 *
 * @covers \OCA\Hermiq\Service\GitHubCatalogPayload
 * @uses \OCA\Hermiq\Service\SkillSerializer
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */
class GitHubCatalogCardGoldenTest extends TestCase {

	/**
	 * The card outcomes.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$agent = GitHubTemplateCatalogService::KIND_AGENT_TEMPLATE;
		$skill = GitHubTemplateCatalogService::KIND_SKILL;
		$closed = ['installable' => false, 'unparseable' => true];
		$open = ['installable' => true, 'unparseable' => false];
		return [
			'agent unread' => [$agent, null, $closed],
			'skill unread' => [$skill, null, $closed],
			'agent not json' => [$agent, '{oops', $closed],
			'agent json scalar' => [$agent, '"text"', $closed],
			'agent full' => [
				$agent,
				'{"name":"Bot","description":"Helps","category":"ops","version":"1.2","extra":1}',
				$open + ['name' => 'Bot', 'description' => 'Helps', 'category' => 'ops', 'version' => '1.2'],
			],
			'agent empty object' => [$agent, '[]', $open + ['name' => 'repo', 'description' => '', 'category' => '', 'version' => '']],
			'agent numeric fields' => [
				$agent,
				'{"name":7,"version":2}',
				$open + ['name' => '7', 'description' => '', 'category' => '', 'version' => '2'],
			],
			'skill named' => [
				$skill,
				"---\nname: lint\ndescription: Lints code\n---\nBody",
				$open + ['name' => 'lint', 'description' => 'Lints code', 'category' => '', 'version' => ''],
			],
			'skill without fence' => [$skill, 'just text', $open + ['name' => 'repo', 'description' => '', 'category' => '', 'version' => '']],
		];

	}//end cases()

	/**
	 * Each outcome is the base fields followed by the recorded card fields.
	 *
	 * @param string               $kind     The hit's kind.
	 * @param string|null          $contents The package file.
	 * @param array<string, mixed> $expected The fields after the base.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testCardOutcome(string $kind, ?string $contents, array $expected): void {
		$base = ['owner' => 'acme', 'repo' => 'repo', 'stars' => 3, 'kind' => $kind];

		$card = (new GitHubCatalogPayload())->card(base: $base, contents: $contents);

		$this->assertSame(expected: $base + $expected, actual: $card);
		$this->assertSame(expected: array_merge(array_keys($base), array_keys($expected)), actual: array_keys($card));

	}//end testCardOutcome()
}//end class
