<?php

/**
 * Example data for DqZaakAssistent's AI oversight card: four advisory
 * records about the Lindelaan Woo case, one per thing a person can do with
 * a suggestion.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The advisory records on the Lindelaan case.
 */
class ExampleSetLindelaanAiOversightTest extends TestCase {

	/**
	 * Each record is advisory, about the Lindelaan case in dossiq, and its
	 * status is the one AiOversightService gives its human action.
	 *
	 * @return void
	 */
	public function testFourAdvisoryRecordsOnTheLindelaanCase(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertContains(needle: 'portaliq', haystack: $set['after']);

		$statusOf = ['accepted' => 'approved', 'rejected' => 'denied', 'overridden' => 'overridden', 'ignored' => 'unused'];
		$actions  = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] !== 'approval') {
				continue;
			}

			$context = $object['data']['advisoryContext'];
			$this->assertSame(expected: 'advisory', actual: $object['data']['sourceType']);
			$this->assertSame(
				expected: ['dossiq', 'case', '{{lookup:case}}'],
				actual: [$context['originApp'], $context['subjectType'], $context['subjectId']]
			);
			$this->assertSame(expected: ['externalReference' => 'MZ-ZDR-2026-0412'], actual: $object['lookups']['case']['where']);
			$this->assertSame(expected: $statusOf[$context['action']], actual: $object['data']['status']);
			$actions[] = $context['action'];
		}

		$this->assertSame(expected: ['accepted', 'overridden', 'ignored', 'rejected'], actual: $actions);
	}//end testFourAdvisoryRecordsOnTheLindelaanCase()
}//end class
