<?php

/**
 * Every schema in hermiq's register files passes OpenRegister's real property
 * validator, the one its import runs. A union type such as `["string","null"]`
 * is refused there and the whole schema is left out of the register, so the
 * app reads "schema not carried by register" at runtime (Newman on #1043).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Runs OpenRegister's import validator over every schema hermiq ships.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
 */
class RegisterImportVocabularyTest extends TestCase {

	/**
	 * The namespaces loaded from OpenRegister's real source for this test.
	 *
	 * @var string[]
	 */
	private const REAL_PREFIXES = [
		'OCA\\OpenRegister\\Service\\Schemas\\',
		'OCA\\OpenRegister\\Service\\Search\\',
		'OCA\\OpenRegister\\Service\\Vocabulary\\',
		'OCA\\OpenRegister\\Exception\\',
	];

	/**
	 * Load the validator from OpenRegister's source, the same roots the
	 * bootstrap uses. An absent source is an error, never a skip.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$roots = array_filter([getenv('HERMIQ_OPENREGISTER_PATH') ?: null, __DIR__ . '/../../../../openregister']);
		$root  = null;
		foreach ($roots as $candidate) {
			if (is_file(rtrim((string)$candidate, '/') . '/lib/Service/Schemas/PropertyValidatorHandler.php') === true) {
				$root = rtrim((string)$candidate, '/');
				break;
			}
		}

		if ($root === null) {
			self::fail('OpenRegister source not found beside hermiq or at HERMIQ_OPENREGISTER_PATH.');
		}

		spl_autoload_register(
			static function (string $class) use ($root): void {
				foreach (self::REAL_PREFIXES as $prefix) {
					if (str_starts_with($class, $prefix) === true) {
						$file = $root . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\OpenRegister\\'))) . '.php';
						if (is_file($file) === true) {
							include_once $file;
						}

						return;
					}
				}
			},
			true,
			true
		);

	}//end setUpBeforeClass()

	/**
	 * The schemas of each register file hermiq imports.
	 *
	 * @return array<string, array{string}>
	 */
	public static function registerFiles(): array {
		return [
			'register' => ['hermiq_register.json'],
		];

	}//end registerFiles()

	/**
	 * Every schema's properties pass the validator OpenRegister's import runs.
	 *
	 * @param string $file The register file under lib/Settings.
	 *
	 * @return void
	 *
	 * @dataProvider registerFiles
	 */
	public function testEverySchemaPassesTheImportValidator(string $file): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file),
			true
		);
		$schemas  = $register['components']['schemas'] ?? [];
		$this->assertNotEmpty($schemas);

		$validator = new PropertyValidatorHandler();
		$refused   = [];
		foreach ($schemas as $name => $schema) {
			try {
				$validator->validateProperties($schema['properties'] ?? []);
			} catch (\Exception $e) {
				$refused[] = $name . ': ' . $e->getMessage();
			}
		}

		$this->assertSame([], $refused);

	}//end testEverySchemaPassesTheImportValidator()
}//end class
