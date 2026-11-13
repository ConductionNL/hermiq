<?php

/**
 * Every seeded action has a label and a description, in English and Dutch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ActionSeed;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\ActionSeed
 */
final class ActionSeedTest extends TestCase {

	public function testEverySeededActionHasALabelAndADescription(): void {
		$seed = new ActionSeed();
		$actions = $seed->actions();

		$this->assertGreaterThanOrEqual(23, count($actions));
		foreach (array_keys($actions) as $action) {
			$label = $seed->label($action);
			$this->assertNotSame($action, $label['label'], $action . ' has no label');
			$this->assertNotSame('', $label['description'], $action . ' has no description');
		}
	}//end testEverySeededActionHasALabelAndADescription()

	public function testEveryLabelIsTranslatedIntoDutch(): void {
		$seed = new ActionSeed();
		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];

		foreach (array_keys($seed->actions()) as $action) {
			$label = $seed->label($action);
			$this->assertArrayHasKey($label['label'], $nl, $action);
			$this->assertArrayHasKey($label['description'], $nl, $action);
			$this->assertNotSame($label['label'], $nl[$label['label']], $action);
		}
	}//end testEveryLabelIsTranslatedIntoDutch()

	public function testTheSeedStillGrantsOnlyAdministratorsByDefault(): void {
		foreach ((new ActionSeed())->actions() as $action => $groups) {
			$this->assertSame(['admin'], $groups, $action);
		}
	}//end testTheSeedStillGrantsOnlyAdministratorsByDefault()

	public function testAMissingOrMalformedSeedListsNothing(): void {
		$file = tempnam(sys_get_temp_dir(), 'seed');
		file_put_contents($file, '{"actions": {"a.b": ["admin", 3, ""], "c": "x"}, "labels": {"a.b": {"label": ""}}}');
		$seed = ActionSeed::fromFile($file);

		$this->assertSame(['a.b' => ['admin']], $seed->actions());
		$this->assertSame(['label' => 'a.b', 'description' => ''], $seed->label('a.b'));
		$this->assertSame(['label' => 'x.y', 'description' => ''], $seed->label('x.y'));
		$this->assertSame([], ActionSeed::fromFile($file . '.missing')->actions());

		file_put_contents($file, '{broken');
		$this->assertSame([], ActionSeed::fromFile($file)->actions());
		unlink($file);
	}//end testAMissingOrMalformedSeedListsNothing()
}//end class
