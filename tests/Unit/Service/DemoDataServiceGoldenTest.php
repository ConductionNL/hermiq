<?php

/**
 * Golden tests for DemoDataService's descriptor reading, choices and install.
 *
 * Runs the real service over a temporary app directory and pins: the choices for a
 * missing, unreadable-as-JSON, object-less and populated descriptor; the exact
 * refusal of install for each unusable descriptor and for a missing OpenRegister;
 * and the import call, the counts and the log line of a successful install.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/specs/configuration-initialization/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the demo dataset.
 *
 * @spec openspec/specs/configuration-initialization/spec.md
 */
class DemoDataServiceGoldenTest extends TestCase {

	/**
	 * The temporary app directory.
	 *
	 * @var string
	 */
	private string $appDir = '';

	/**
	 * Every import call and log line.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * Make the temporary app directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appDir = sys_get_temp_dir() . '/hermiq-demo-' . bin2hex(random_bytes(4));
		mkdir($this->appDir . '/lib/Settings', 0777, true);
	}//end setUp()

	/**
	 * Remove it.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->appDir . '/lib/Settings/hermiq_mock_register.json');
		@rmdir($this->appDir . '/lib/Settings');
		@rmdir($this->appDir . '/lib');
		@rmdir($this->appDir);
	}//end tearDown()

	/**
	 * The service over the temporary app, with or without OpenRegister.
	 *
	 * @param string|null $descriptor The descriptor's contents, or null for none.
	 * @param bool $withOpenRegister Whether OpenRegister is installed.
	 *
	 * @return DemoDataService
	 */
	private function service(?string $descriptor, bool $withOpenRegister = true): DemoDataService {
		$this->log = [];
		$file = $this->appDir . '/lib/Settings/hermiq_mock_register.json';
		@unlink($file);
		if ($descriptor !== null) {
			file_put_contents($file, $descriptor);
		}

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($this->appDir);
		$apps->method('getAppVersion')->willReturn('1.2.3');
		$apps->method('getInstalledApps')->willReturn($withOpenRegister === true ? ['openregister', 'hermiq'] : ['hermiq']);

		$importer = new class($this->log) {
			/**
			 * @param array $log The call log, by reference.
			 */
			public function __construct(private array &$log) {
			}

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->log[] = ['import', $appId, array_keys($data), $version, $force];
				return ['registers' => ['r1'], 'schemas' => ['s1', 's2']];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(fn (string $m, array $c = []) => $this->log[] = $m . ' ' . json_encode($c));

		return new DemoDataService($apps, $container, $logger);
	}//end service()

	/**
	 * The message install refuses with.
	 *
	 * @param DemoDataService $service The service.
	 *
	 * @return string
	 */
	private function refusal(DemoDataService $service): string {
		try {
			$service->install();
		} catch (RuntimeException $e) {
			return str_replace($this->appDir, '<app>', $e->getMessage());
		}

		return 'no refusal';
	}//end refusal()

	/**
	 * The demo choice's object count for each descriptor shape.
	 *
	 * @return void
	 */
	public function testTheChoices(): void {
		$counts = [];
		foreach ([null, 'nope', '{"components":{"objects":"x"}}', '{"components":"x"}', '{"components":{"objects":[{},{},{}]}}'] as $descriptor) {
			$choices = $this->service($descriptor)->listChoices();
			$counts[] = [count($choices), ($choices[1]['objectCount'] ?? null)];
		}

		$this->assertSame([[1, null], [1, null], [2, 0], [2, 0], [2, 3]], $counts);
	}//end testTheChoices()

	/**
	 * Each refusal of install, and a successful install.
	 *
	 * @return void
	 */
	public function testInstall(): void {
		$this->assertSame('No demo dataset ships with this app (/lib/Settings/hermiq_mock_register.json not found).', $this->refusal($this->service(null)));
		$this->assertSame('The demo dataset is not valid JSON: <app>/lib/Settings/hermiq_mock_register.json', $this->refusal($this->service('"text"')));
		$this->assertSame('Demo data needs OpenRegister, which is not installed.', $this->refusal($this->service('{}', false)));

		$this->assertSame(['objects' => 0, 'registers' => 1, 'schemas' => 2], $this->service('{"components":[]}')->install());
		$result = $this->service('{"info":{},"components":{"objects":[{},{}]}}')->install();
		$this->assertSame(['objects' => 2, 'registers' => 1, 'schemas' => 2], $result);
		$this->assertSame(
			[
				['import', 'hermiq.demo', ['info', 'components'], '1.2.3', true],
				'[DemoDataService] imported demo data: 2 object(s), 1 register(s), 2 schema(s). {"app":"hermiq"}',
			],
			$this->log
		);
	}//end testInstall()
}//end class
