<?php

/**
 * Unit tests for TalkSafeText.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
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
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkSafeText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which names may be put in a Talk message.
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
 */
class TalkSafeTextTest extends TestCase {

	/**
	 * Ids Talk would show as a link.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function linkedIds(): array {
		return [
			'www prefix' => ['www.example.com'],
			'upper case' => ['WWW.example.com'],
			'after a dash' => ['tool-www.example.com'],
			'after a dot' => ['tool.www.example.com'],
			'after an underscore' => ['tool_www.example.com'],
		];
	}//end linkedIds()

	/**
	 * An id that Talk would autolink is not plain.
	 *
	 * @param string $id The id.
	 *
	 * @return void
	 */
	#[DataProvider('linkedIds')]
	public function testAutolinkedIdIsRefused(string $id): void {
		$safe = new TalkSafeText();

		$this->assertNull(actual: $safe->identifier(identifier: $id));
		$this->assertSame(expected: 'an action', actual: $safe->quoted(name: $id, fallback: 'an action'));
	}//end testAutolinkedIdIsRefused()

	/**
	 * Plain ids pass, including ones that merely contain the letters www.
	 *
	 * @return void
	 */
	public function testPlainIdsPass(): void {
		$safe = new TalkSafeText();

		$this->assertSame(expected: 'openregister.objects.create', actual: $safe->identifier(identifier: 'openregister.objects.create'));
		$this->assertSame(expected: 'awww.tool', actual: $safe->identifier(identifier: 'awww.tool'));
		$this->assertSame(expected: 'www-tool', actual: $safe->identifier(identifier: 'www-tool'));
		$this->assertSame(expected: '“send_mail”', actual: $safe->quoted(name: 'send_mail', fallback: 'an action'));
	}//end testPlainIdsPass()

	/**
	 * Mentions and schemes stay refused.
	 *
	 * @return void
	 */
	public function testMentionAndSchemeAreRefused(): void {
		$safe = new TalkSafeText();

		$this->assertNull(actual: $safe->identifier(identifier: '@admin'));
		$this->assertNull(actual: $safe->identifier(identifier: 'https://example.com'));
	}//end testMentionAndSchemeAreRefused()
}//end class
