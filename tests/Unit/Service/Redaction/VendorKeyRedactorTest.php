<?php

/**
 * Unit tests for VendorKeyRedactor: known vendor key prefixes never leave verbatim.
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
use OCA\Hermiq\Service\Redaction\VendorKeyRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Tool output keeps a head/tail; a file read keeps only the vendor prefix.
 *
 * @covers \OCA\Hermiq\Service\Redaction\VendorKeyRedactor
 * @uses   \OCA\Hermiq\Service\Redaction\TokenMasker
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */
class VendorKeyRedactorTest extends TestCase {

	/**
	 * The redactor under test, on the real masker.
	 *
	 * @return VendorKeyRedactor
	 */
	private function redactor(): VendorKeyRedactor {
		return new VendorKeyRedactor(masker: new TokenMasker());
	}//end redactor()

	/**
	 * Outside a file read, a key is masked head and tail in place.
	 *
	 * @return void
	 */
	public function testAKeyInToolOutputKeepsHeadAndTail(): void {
		$redactor = $this->redactor();

		$this->assertSame('key sk-ABC...cdef end', $redactor->redact(text: 'key sk-' . 'ABCDEF1234567890abcdef end', fileRead: false));
		$this->assertSame('AKIAAB...MNOP', $redactor->redact(text: 'AKIA' . 'ABCDEFGHIJKLMNOP', fileRead: false));
		$this->assertSame('SG.ABC...cdef', $redactor->redact(text: 'SG.' . 'ABCDEF1234567890abcdef', fileRead: false));
	}//end testAKeyInToolOutputKeepsHeadAndTail()

	/**
	 * In a file read the key is replaced by a marker naming only its vendor
	 * prefix, so no reusable fragment survives; the most specific prefix wins.
	 *
	 * @return void
	 */
	public function testAKeyInAFileReadKeepsOnlyItsPrefix(): void {
		$redactor = $this->redactor();

		$this->assertSame('key «redacted:sk-…» end', $redactor->redact(text: 'key sk-' . 'ABCDEF1234567890abcdef end', fileRead: true));
		$this->assertSame('«redacted:sk_live_…»', $redactor->redact(text: 'sk_live_' . 'ABCDEF1234567890', fileRead: true));
		$this->assertSame('«redacted:xox…»', $redactor->redact(text: 'xoxb-' . 'ABCDEF1234567890', fileRead: true));
		$this->assertSame('«redacted:SG…»', $redactor->redact(text: 'SG.' . 'ABCDEF1234567890abcdef', fileRead: true));
	}//end testAKeyInAFileReadKeepsOnlyItsPrefix()

	/**
	 * Every key in the text is masked, not just the first.
	 *
	 * @return void
	 */
	public function testEveryKeyInTheTextIsMasked(): void {
		$text = 'a=sk-' . 'ABCDEF1234567890abcdef b=AKIA' . 'ABCDEFGHIJKLMNOP';

		$this->assertSame('a=«redacted:sk-…» b=«redacted:AKIA…»', $this->redactor()->redact(text: $text, fileRead: true));
	}//end testEveryKeyInTheTextIsMasked()

	/**
	 * A prefix inside a longer word, a key too short to be one, and text
	 * without any known prefix are left alone.
	 *
	 * @return void
	 */
	public function testWhatIsNotAKeyIsLeftAlone(): void {
		$redactor = $this->redactor();
		$embedded = 'mysk-' . 'ABCDEF1234567890abcdef';

		$this->assertSame($embedded, $redactor->redact(text: $embedded, fileRead: false));
		$this->assertSame('sk-short', $redactor->redact(text: 'sk-short', fileRead: true));
		$this->assertSame('plain text', $redactor->redact(text: 'plain text', fileRead: true));
	}//end testWhatIsNotAKeyIsLeftAlone()
}//end class
