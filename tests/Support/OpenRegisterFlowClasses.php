<?php

/**
 * Make OpenRegister's flow VALUE and CONTROL-FLOW classes loadable in a pure-unit run.
 *
 * `GitHubAwaitLabelNode` names seven OpenRegister symbols that `tests/bootstrap.php`
 * cannot supply: `FlowItems`, `FlowNodeResumeState`, `FlowStop`, `FlowSuspension`,
 * `IFlowNodeConfigKeys`, `IFlowNodeConfigForm` and `IFlowNodeTaxonomy`. The blanket
 * `OCA\OpenRegister\` → `tests/Stubs/` mapping resolves them to files that do not
 * exist, so the class cannot even be autoloaded and the node had no test at all.
 *
 * 🔴 THERE IS DELIBERATELY NO STUB, for the reason the bootstrap already gives for
 * the capability grammar: `FlowStop` and `FlowSuspension` are the difference between
 * "this gate failed" and "this gate is still waiting", and a stubbed copy of that
 * distinction is a second copy of the rule. A test that suspended against a local
 * fake of `FlowSuspension` would prove nothing about the engine that has to read it.
 * The real files carry no dependencies at all — two exception classes, two value
 * classes and three empty interfaces — so loading the source costs nothing.
 *
 * WHY A CLASSMAP AND NOT A PSR-4 PREFIX. Registering
 * `OCA\OpenRegister\Service\Flow\` as PSR-4 would be a LONGER prefix than the
 * bootstrap's blanket stub mapping and would therefore win for the whole namespace,
 * silently replacing the five flow stubs that other test classes are written
 * against. An explicit classmap of exactly these seven names can shadow nothing
 * else. It is prepended so it beats the stub mapping, and it is lazy, so an
 * absent OpenRegister checkout leaves the classes genuinely missing and the tests
 * ERROR loudly rather than passing against nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Support;

use Composer\Autoload\ClassLoader;

/**
 * Registers the real flow classes, once per process.
 */
final class OpenRegisterFlowClasses {

	/**
	 * The classes taken from real source, as short name => file basename.
	 *
	 * `IFlowNode` is NOT in this list on purpose: a stub for it already exists and
	 * every other node test is written against that stub.
	 *
	 * @var array<int,string>
	 */
	private const CLASSES = [
		'FlowItems',
		'FlowNodeResumeState',
		'FlowStop',
		'FlowSuspension',
		'IFlowNodeConfigForm',
		'IFlowNodeConfigKeys',
		'IFlowNodeTaxonomy',
	];

	/**
	 * Whether this process has already registered.
	 *
	 * @var bool
	 */
	private static bool $done = false;

	/**
	 * Point the autoloader at the real flow classes.
	 *
	 * @return void
	 */
	public static function register(): void {
		if (self::$done === true) {
			return;
		}

		self::$done = true;

		$root = (getenv('HERMIQ_OPENREGISTER_PATH') ?: (__DIR__ . '/../../../openregister'));
		$dir = rtrim((string)$root, '/') . '/lib/Service/Flow';
		if (is_dir($dir) === false) {
			return;
		}

		$map = [];
		foreach (self::CLASSES as $class) {
			$file = $dir . '/' . $class . '.php';
			if (is_file($file) === true) {
				$map['OCA\\OpenRegister\\Service\\Flow\\' . $class] = $file;
			}
		}

		$loader = new ClassLoader();
		$loader->addClassMap($map);
		$loader->register(true);

	}//end register()
}//end class
