<?php

/**
 * Unit tests for AssignmentRedactor: secrets assigned in env, config, JSON and YAML are masked.
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

use OCA\Hermiq\Service\Redaction\AssignmentRedactor;
use OCA\Hermiq\Service\Redaction\TokenMasker;
use PHPUnit\Framework\TestCase;

/**
 * Each assignment shape masks the value and keeps the name it was assigned to.
 *
 * @covers \OCA\Hermiq\Service\Redaction\AssignmentRedactor
 * @uses   \OCA\Hermiq\Service\Redaction\TokenMasker
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */
class AssignmentRedactorTest extends TestCase {

	/**
	 * The redactor under test, on the real masker.
	 *
	 * @return AssignmentRedactor
	 */
	private function redactor(): AssignmentRedactor {
		return new AssignmentRedactor(masker: new TokenMasker());
	}//end redactor()

	/**
	 * An upper-case env assignment is masked; on a line that also holds a URL
	 * only the env pass runs, so its head/tail mask is what remains.
	 *
	 * @return void
	 */
	public function testAnEnvAssignmentIsMasked(): void {
		$this->assertSame(
			'API_TOKEN=abcdef...stuv url=https://example.org',
			$this->redactor()->redact(text: 'API_TOKEN=abcdefghijklmnopqrstuv url=https://example.org')
		);
	}//end testAnEnvAssignmentIsMasked()

	/**
	 * A line-anchored assignment is masked again by the anchored pass, so an
	 * env line at the start of a line, quoted or exported, keeps nothing.
	 *
	 * @return void
	 */
	public function testALineAnchoredAssignmentKeepsNothing(): void {
		$redactor = $this->redactor();

		$this->assertSame('OPENAI_API_KEY=***', $redactor->redact(text: 'OPENAI_API_KEY=sk1234567890abcdefgh'));
		$this->assertSame('export GITHUB_TOKEN="***"', $redactor->redact(text: 'export GITHUB_TOKEN="abcdefghijklmnopqrstuv"'));
	}//end testALineAnchoredAssignmentKeepsNothing()

	/**
	 * A dotted config key holding a secret name is masked head and tail.
	 *
	 * @return void
	 */
	public function testADottedConfigKeyIsMasked(): void {
		$this->assertSame(
			'db.password=abcdef...wxyz',
			$this->redactor()->redact(text: 'db.password=abcdefghijklmnopqrstuvwxyz')
		);
	}//end testADottedConfigKeyIsMasked()

	/**
	 * A JSON field with a secret name is masked; other fields are kept.
	 *
	 * @return void
	 */
	public function testAJsonSecretFieldIsMasked(): void {
		$this->assertSame(
			'{"api_key": "abcdef...rstu", "name": "x"}',
			$this->redactor()->redact(text: '{"api_key": "abcdefghijklmnopqrstu", "name": "x"}')
		);
	}//end testAJsonSecretFieldIsMasked()

	/**
	 * An unquoted YAML secret is masked, a quoted one is left to other passes,
	 * and the neighbouring keys are untouched.
	 *
	 * @return void
	 */
	public function testAnUnquotedYamlSecretIsMasked(): void {
		$redactor = $this->redactor();

		$this->assertSame(
			"db:\n  password: supers...1234\n  host: localhost",
			$redactor->redact(text: "db:\n  password: supersecretvalue1234\n  host: localhost")
		);
		$this->assertSame("password: 'quotedvalue123456789'", $redactor->redact(text: "password: 'quotedvalue123456789'"));
	}//end testAnUnquotedYamlSecretIsMasked()

	/**
	 * Text holding a URL skips the YAML pass, so a URL's colon is never read
	 * as a key; text with no secret names is unchanged.
	 *
	 * @return void
	 */
	public function testTextWithAUrlOrNoSecretNameIsUnchanged(): void {
		$redactor = $this->redactor();
		$withUrl = "see https://example.org\npassword: abcdefghijklmnopqrstuv";

		$this->assertSame($withUrl, $redactor->redact(text: $withUrl));
		$this->assertSame('DEBUG=true', $redactor->redact(text: 'DEBUG=true'));
		$this->assertSame('nothing here', $redactor->redact(text: 'nothing here'));
	}//end testTextWithAUrlOrNoSecretNameIsUnchanged()
}//end class
