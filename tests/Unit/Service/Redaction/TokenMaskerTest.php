<?php

/**
 * Unit tests for TokenMasker: the head/tail mask every redactor shares.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Redaction;

use OCA\Hermiq\Service\Redaction\TokenMasker;
use PHPUnit\Framework\TestCase;

/**
 * A long token keeps six characters of head and four of tail; a short one keeps nothing.
 *
 * @covers \OCA\Hermiq\Service\Redaction\TokenMasker
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */
class TokenMaskerTest extends TestCase {

	/**
	 * A token of eighteen characters or more keeps its head and tail.
	 *
	 * @return void
	 */
	public function testALongTokenKeepsItsHeadAndTail(): void {
		$masker = new TokenMasker();

		$this->assertSame('abcdef...4567', $masker->mask(token: 'abcdefghijkl1234567'));
		$this->assertSame('ABCDEF...WXYZ', $masker->mask(token: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
	}//end testALongTokenKeepsItsHeadAndTail()

	/**
	 * Exactly eighteen characters is long enough to keep head and tail.
	 *
	 * @return void
	 */
	public function testEighteenCharactersIsTheBoundary(): void {
		$masker = new TokenMasker();

		$this->assertSame('123456...5678', $masker->mask(token: '123456789012345678'));
		$this->assertSame('***', $masker->mask(token: '12345678901234567'));
	}//end testEighteenCharactersIsTheBoundary()

	/**
	 * A short or empty token is masked whole, so no fragment of it leaks.
	 *
	 * @return void
	 */
	public function testAShortOrEmptyTokenIsMaskedWhole(): void {
		$masker = new TokenMasker();

		$this->assertSame('***', $masker->mask(token: ''));
		$this->assertSame('***', $masker->mask(token: 'hunter2'));
	}//end testAShortOrEmptyTokenIsMaskedWhole()
}//end class
