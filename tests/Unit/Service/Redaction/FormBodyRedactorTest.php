<?php

/**
 * Unit tests for FormBodyRedactor: sensitive fields in a form-encoded body are masked.
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

use OCA\Hermiq\Service\Redaction\FormBodyRedactor;
use PHPUnit\Framework\TestCase;

/**
 * A whole-line form body has its sensitive values masked and the rest kept.
 *
 * @covers \OCA\Hermiq\Service\Redaction\FormBodyRedactor
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */
class FormBodyRedactorTest extends TestCase {

	/**
	 * Sensitive keys are masked regardless of case; others are kept as written.
	 *
	 * @return void
	 */
	public function testSensitiveFieldsAreMaskedAndTheRestKept(): void {
		$this->assertSame(
			'grant_type=password&username=alice&Password=***&client_secret=***&scope=openid',
			(new FormBodyRedactor())->redact(
				text: 'grant_type=password&username=alice&Password=s3cret&client_secret=abc123&scope=openid'
			)
		);
	}//end testSensitiveFieldsAreMaskedAndTheRestKept()

	/**
	 * Surrounding whitespace is trimmed from a body that gets redacted.
	 *
	 * @return void
	 */
	public function testARedactedBodyIsTrimmed(): void {
		$this->assertSame('code=***&state=xyz', (new FormBodyRedactor())->redact(text: "  code=abc&state=xyz \t"));
	}//end testARedactedBodyIsTrimmed()

	/**
	 * An empty value on a sensitive key is still masked.
	 *
	 * @return void
	 */
	public function testAnEmptySensitiveValueIsStillMasked(): void {
		$this->assertSame('token=***&a=1', (new FormBodyRedactor())->redact(text: 'token=&a=1'));
	}//end testAnEmptySensitiveValueIsStillMasked()

	/**
	 * Text that is not a single-line form body is returned unchanged.
	 *
	 * @param string $text The input.
	 *
	 * @return void
	 *
	 * @dataProvider notAFormBody
	 */
	public function testTextThatIsNotAFormBodyIsUnchanged(string $text): void {
		$this->assertSame($text, (new FormBodyRedactor())->redact(text: $text));
	}//end testTextThatIsNotAFormBodyIsUnchanged()

	/**
	 * Inputs that must pass through untouched.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function notAFormBody(): array {
		return [
			'no ampersand' => ['password=secret'],
			'no equals' => ['this & that'],
			'multi-line' => ["password=a&b=c\nmore"],
			'prose around it' => ['see password=a&b=c for details'],
			'a single pair' => ['password=abc&'],
		];
	}//end notAFormBody()
}//end class
