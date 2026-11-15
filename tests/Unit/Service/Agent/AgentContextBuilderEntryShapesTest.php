<?php

/**
 * AgentContextBuilder allowlist entry shapes and caps that do not apply.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentContextBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins every allowlist entry shape, the entries that name nothing, and the
 * `maxLength` caps that leave a value alone.
 *
 * @covers \OCA\Hermiq\Service\Agent\AgentContextBuilder
 */
class AgentContextBuilderEntryShapesTest extends TestCase {

	/**
	 * Map, bare-name and `{property}` entries all allow their property; nameless ones allow nothing.
	 *
	 * @return void
	 */
	public function testEveryEntryShapeAndTheOnesThatNameNothing(): void {
		$object = ['title' => 'T', 'status' => 'open', 'summary' => 'abcdef', 'secret' => 'x', '' => 'blank'];
		$config = [
			AgentContextBuilder::KEYWORD => [
				'title' => 'not-an-array',
				0 => 'status',
				1 => ['property' => 'summary', 'maxLength' => 3],
				2 => ['maxLength' => 2],
				3 => 42,
				4 => null,
			],
		];

		$this->assertSame(
			['title' => 'T', 'status' => 'open', 'summary' => 'abc…'],
			(new AgentContextBuilder())->build(objectData: $object, schemaConfiguration: $config)
		);
	}//end testEveryEntryShapeAndTheOnesThatNameNothing()

	/**
	 * A cap that is not a positive integer, or a value that is not a string, leaves the value whole.
	 *
	 * @return void
	 */
	public function testCapsThatDoNotApplyLeaveTheValueAlone(): void {
		$object = ['a' => 'abcdef', 'b' => 'abcdef', 'c' => 'abcdef', 'd' => ['x' => 'abcdef'], 'e' => 1234567, 'f' => 'abc'];
		$config = [
			AgentContextBuilder::KEYWORD => [
				'a' => ['maxLength' => 0],
				'b' => ['maxLength' => '3'],
				'c' => ['maxLength' => -1],
				'd' => ['maxLength' => 2],
				'e' => ['maxLength' => 2],
				'f' => ['maxLength' => 3],
			],
		];

		$this->assertSame($object, (new AgentContextBuilder())->build(objectData: $object, schemaConfiguration: $config));
	}//end testCapsThatDoNotApplyLeaveTheValueAlone()

	/**
	 * A listed property holding null or an empty string is left out.
	 *
	 * @return void
	 */
	public function testEmptyValuesAreLeftOut(): void {
		$config = [AgentContextBuilder::KEYWORD => ['a', 'b', 'c']];

		$this->assertSame(
			['c' => 0],
			(new AgentContextBuilder())->build(objectData: ['a' => null, 'b' => '', 'c' => 0], schemaConfiguration: $config)
		);
	}//end testEmptyValuesAreLeftOut()
}//end class
