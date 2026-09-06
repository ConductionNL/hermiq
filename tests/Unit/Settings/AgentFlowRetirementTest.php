<?php

declare(strict_types=1);

/**
 * Agentflow retirement guard (finish-agentflow-retirement).
 *
 * The agentflow object store is retired: hermiq authors flows in
 * OpenRegister's native flow store (REQ-FA-002), and the store's runner,
 * resolver and frontend are gone. This test pins the two declarative halves
 * of that retirement, because both fail silently when they regress:
 *
 *  - a schema quietly re-added to `hermiq_register.json` would be imported
 *    on the next version bump and re-open the retired store with no error
 *    anywhere;
 *  - a mock object for a retired schema would make the demo import write
 *    rows no code can read.
 *
 * It also pins the prune's registration in `info.xml`: the import unions
 * schema ids and never removes one, so the descriptor edit without the
 * repair step leaves every existing install carrying both schemas forever.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Guards declarative register files and info.xml, not a PHP class.
 *
 * @spec openspec/specs/flow-authoring/spec.md#requirement-a-flow-is-stored-once-by-openregister-req-fa-002
 */
class AgentFlowRetirementTest extends TestCase {

	/**
	 * The retired slugs, compared case-insensitively: a slug rename hides in
	 * casing, so `AgentFlow` and `agentflow` are the same claim.
	 *
	 * @var string[]
	 */
	private const RETIRED_SLUGS = [
		'agentflow',
		'agentflowrun',
	];

	/**
	 * A parsed JSON file from lib/Settings.
	 *
	 * @param string $name The file name.
	 *
	 * @return array<string,mixed> The parsed document.
	 */
	private function settingsJson(string $name): array {
		$path = __DIR__ . '/../../../lib/Settings/' . $name;
		$this->assertFileExists($path);

		$document = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($document, $name . ' must be valid JSON.');

		return $document;
	}//end settingsJson()

	/**
	 * The register descriptor declares neither retired schema: not as a
	 * component and not in the register's schema list.
	 *
	 * @return void
	 */
	public function testRegisterDescriptorCarriesNoAgentFlowSchema(): void {
		$register = $this->settingsJson('hermiq_register.json');

		foreach (array_keys((array)($register['components']['schemas'] ?? [])) as $componentName) {
			$this->assertNotContains(
				strtolower((string)$componentName),
				self::RETIRED_SLUGS,
				'The retired ' . $componentName . ' schema must stay out of the descriptor: '
				. 'the next version bump would import it and re-open the retired store.'
			);
		}

		$slugs = array_map(
			static fn ($slug): string => strtolower((string)$slug),
			(array)($register['components']['registers']['hermiq']['schemas'] ?? [])
		);
		foreach (self::RETIRED_SLUGS as $retired) {
			$this->assertNotContains($retired, $slugs, 'The register list must not claim "' . $retired . '".');
		}
	}//end testRegisterDescriptorCarriesNoAgentFlowSchema()

	/**
	 * The demo data seeds no objects into the retired schemas: a row written
	 * there is unreadable — the store has no runner, resolver or frontend.
	 *
	 * @return void
	 */
	public function testMockRegisterSeedsNoAgentFlowObjects(): void {
		$mock = $this->settingsJson('hermiq_mock_register.json');

		$objects = (array)($mock['components']['objects'] ?? []);
		$this->assertNotEmpty($objects, 'The demo data must still seed objects for the live schemas.');

		foreach ($objects as $object) {
			$schema = strtolower((string)($object['@self']['schema'] ?? ''));
			$this->assertNotContains(
				$schema,
				self::RETIRED_SLUGS,
				'Demo object "' . (string)($object['@self']['slug'] ?? '?') . '" seeds the retired "'
				. $schema . '" schema, which no code can read.'
			);
		}
	}//end testMockRegisterSeedsNoAgentFlowObjects()

	/**
	 * The prune runs on upgrade. Without this registration the descriptor
	 * edit is only half the retirement: the import unions schema ids and
	 * never removes one, so existing installs keep both schemas forever.
	 *
	 * @return void
	 */
	public function testThePruneStepIsRegisteredForUpgrades(): void {
		$path = __DIR__ . '/../../../appinfo/info.xml';
		$this->assertFileExists($path);

		$info = (string)file_get_contents($path);
		$this->assertStringContainsString(
			'<step>OCA\Hermiq\Repair\PruneRetiredAgentFlowSchemas</step>',
			$info,
			'PruneRetiredAgentFlowSchemas must be registered as a repair step.'
		);
	}//end testThePruneStepIsRegisteredForUpgrades()
}//end class
