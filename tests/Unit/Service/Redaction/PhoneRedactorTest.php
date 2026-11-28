<?php

/**
 * Unit tests for PhoneRedactor: E.164 numbers keep only a head and a tail.
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

use OCA\Hermiq\Service\Redaction\PhoneRedactor;
use PHPUnit\Framework\TestCase;

/**
 * An international number is masked in the middle; anything that is not one is left alone.
 *
 * @covers \OCA\Hermiq\Service\Redaction\PhoneRedactor
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */
class PhoneRedactorTest extends TestCase {

	/**
	 * A long number keeps four characters of head and four of tail.
	 *
	 * @return void
	 */
	public function testALongNumberKeepsFourAndFour(): void {
		$this->assertSame(
			'call +316****5678 today',
			(new PhoneRedactor())->redact(text: 'call +31612345678 today')
		);
	}//end testALongNumberKeepsFourAndFour()

	/**
	 * A number of eight characters or fewer keeps two and two; one longer keeps four and four.
	 *
	 * @return void
	 */
	public function testAShortNumberKeepsTwoAndTwo(): void {
		$this->assertSame('+1****67', (new PhoneRedactor())->redact(text: '+1234567'));
		$this->assertSame('+123****5678', (new PhoneRedactor())->redact(text: '+12345678'));
		$this->assertSame('+123****6789', (new PhoneRedactor())->redact(text: '+123456789'));
	}//end testAShortNumberKeepsTwoAndTwo()

	/**
	 * Every number in the text is masked.
	 *
	 * @return void
	 */
	public function testEveryNumberInTheTextIsMasked(): void {
		$this->assertSame(
			'+316****5678, +442****0000',
			(new PhoneRedactor())->redact(text: '+31612345678, +442071230000')
		);
	}//end testEveryNumberInTheTextIsMasked()

	/**
	 * Text without a plus, a number too short, one starting with zero or one
	 * running into letters is not a phone number.
	 *
	 * @return void
	 */
	public function testWhatIsNotAnInternationalNumberIsLeftAlone(): void {
		$redactor = new PhoneRedactor();

		$this->assertSame('call 0612345678', $redactor->redact(text: 'call 0612345678'));
		$this->assertSame('1 + 123456 = x', $redactor->redact(text: '1 + 123456 = x'));
		$this->assertSame('+0612345678', $redactor->redact(text: '+0612345678'));
		$this->assertSame('id +31612345678abc', $redactor->redact(text: 'id +31612345678abc'));
	}//end testWhatIsNotAnInternationalNumberIsLeftAlone()
}//end class
