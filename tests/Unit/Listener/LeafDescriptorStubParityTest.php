<?php

/**
 * Parity test: the `LeafDescriptor` test stub against OpenRegister's real class.
 *
 * In this suite the stub IS the loaded class whenever OpenRegister is absent, so
 * a test that only reads the stub proves nothing about the real descriptor. This
 * reads the REAL source file as text and compares the load-strategy members, so
 * the stub can lag the real class but never lead it or disagree with it.
 *
 * The real source is found the way the bootstrap and RegisterImportVocabularyTest
 * find it: `HERMIQ_OPENREGISTER_PATH`, else `openregister` beside this app. When
 * neither resolves the tests are skipped with a message, not passed.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests that the LeafDescriptor stub carries the same load-strategy members as the real class.
 */
class LeafDescriptorStubParityTest extends TestCase {

	/**
	 * The load-strategy constants, by name.
	 *
	 * @var array<int, string>
	 */
	private const STRATEGY_CONSTANTS = [
		'LOADS_VIA_SHARED_ENTRY',
		'LOADS_VIA_OWN_SCRIPT',
		'LOADS_ALREADY_PRESENT',
	];

	/**
	 * Read OpenRegister's real LeafDescriptor source, or skip the test.
	 *
	 * @return string The file's text.
	 */
	private function realSource(): string {
		$roots = array_filter([getenv('HERMIQ_OPENREGISTER_PATH') ?: null, __DIR__ . '/../../../../openregister']);
		foreach ($roots as $root) {
			$file = rtrim((string)$root, '/') . '/lib/Service/Integration/LeafDescriptor.php';
			if (is_file($file) === true) {
				$source = file_get_contents($file);
				$this->assertIsString($source, 'OpenRegister LeafDescriptor.php must be readable.');
				return $source;
			}
		}

		$this->markTestSkipped(
			'OpenRegister source not found beside hermiq or at HERMIQ_OPENREGISTER_PATH; '
			. 'the LeafDescriptor stub was not compared with the real class.'
		);
	}//end realSource()

	/**
	 * The string value of each load-strategy constant in the real source.
	 *
	 * @param string $source The real LeafDescriptor source.
	 *
	 * @return array<string, string> Constant name to value.
	 */
	private function realConstants(string $source): array {
		$values = [];
		foreach (self::STRATEGY_CONSTANTS as $name) {
			$matched = preg_match('/const ' . $name . " = '([^']*)';/", $source, $matches);
			$this->assertSame(1, $matched, 'The real LeafDescriptor must declare ' . $name . '.');
			$values[$name] = $matches[1];
		}

		return $values;
	}//end realConstants()

	/**
	 * The three load-strategy constants have the same names and values as the real class.
	 *
	 * @return void
	 */
	public function testLoadStrategyConstantsMatchTheRealClass(): void {
		$real = $this->realConstants($this->realSource());

		$stub = (new ReflectionClass(LeafDescriptor::class))->getConstants();
		foreach ($real as $name => $value) {
			$this->assertArrayHasKey($name, $stub, 'The stub lacks ' . $name . '.');
			$this->assertSame($value, $stub[$name], 'The stub disagrees with the real class on ' . $name . '.');
		}
	}//end testLoadStrategyConstantsMatchTheRealClass()

	/**
	 * VALID_LOAD_STRATEGIES lists the same values in the same order as the real class.
	 *
	 * @return void
	 */
	public function testValidLoadStrategiesMatchTheRealClass(): void {
		$source = $this->realSource();
		$consts = $this->realConstants($source);

		$matched = preg_match('/const VALID_LOAD_STRATEGIES = \[(.*?)\];/s', $source, $matches);
		$this->assertSame(1, $matched, 'The real LeafDescriptor must declare VALID_LOAD_STRATEGIES.');

		$real = [];
		foreach (explode(',', $matches[1]) as $entry) {
			$entry = trim($entry);
			if ($entry === '') {
				continue;
			}

			$this->assertStringStartsWith('self::', $entry);
			$name = substr($entry, strlen('self::'));
			$this->assertArrayHasKey($name, $consts, 'VALID_LOAD_STRATEGIES names an unknown constant: ' . $name);
			$real[] = $consts[$name];
		}

		$this->assertNotEmpty($real);
		$this->assertSame($real, LeafDescriptor::VALID_LOAD_STRATEGIES);
	}//end testValidLoadStrategiesMatchTheRealClass()

	/**
	 * getLoadStrategy() exists on both and returns the same type.
	 *
	 * @return void
	 */
	public function testGetLoadStrategyHasTheSameReturnType(): void {
		$source = $this->realSource();

		$matched = preg_match('/function getLoadStrategy\(\)\s*:\s*([^\s{]+)/', $source, $matches);
		$this->assertSame(1, $matched, 'The real LeafDescriptor must declare getLoadStrategy().');

		$stub = new ReflectionClass(LeafDescriptor::class);
		$this->assertTrue($stub->hasMethod('getLoadStrategy'), 'The stub lacks getLoadStrategy().');
		$this->assertSame($matches[1], (string)$stub->getMethod('getLoadStrategy')->getReturnType());
	}//end testGetLoadStrategyHasTheSameReturnType()
}//end class
