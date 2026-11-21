<?php

/**
 * Golden test for SettingsService::loadConfiguration().
 *
 * Runs the register import over every outcome of OpenRegister and its
 * ConfigurationService and compares the result, the import call and the log
 * lines with a recorded fixture. Set GOLDEN_RECORD=1 to (re)write the
 * fixture; a run in record mode compares nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/settings-management/spec.md#req-cfg-003-reload-configuration-from-json-file-admin-only
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of the register configuration import.
 *
 * @covers \OCA\Hermiq\Service\SettingsService
 *
 * @spec openspec/specs/settings-management/spec.md#req-cfg-003-reload-configuration-from-json-file-admin-only
 */
class SettingsLoadConfigurationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/settings-load-configuration.json';

	public const REGISTER_FILE = __DIR__ . '/../../../lib/Settings/hermiq_register.json';

	/**
	 * The import outcomes: `absent` (OpenRegister not installed), `no-service`
	 * (the container throws), `throws` (the import throws), or the array the
	 * import returns.
	 *
	 * @var array<int, string|array<string, mixed>>
	 */
	private const OUTCOMES = ['absent', 'no-service', 'throws', [], ['version' => '9.9.9'], ['imported' => 3], ['version' => '']];

	/**
	 * Every outcome, with and without force, gives the recorded result, call and log lines.
	 *
	 * @return void
	 */
	public function testLoadConfigurationMatchesTheRecordedFixture(): void {
		$actual = [];
		foreach (self::OUTCOMES as $outcome) {
			foreach ([false, true] as $force) {
				$actual[] = $this->runCase(outcome: $outcome, force: $force);
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(expected: $expected, actual: $actual);

	}//end testLoadConfigurationMatchesTheRecordedFixture()

	/**
	 * Run loadConfiguration() under one outcome.
	 *
	 * @param string|array<string, mixed> $outcome The import outcome.
	 * @param bool                        $force   The force flag passed.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(string|array $outcome, bool $force): array {
		$importer = new class($outcome) {
			/**
			 * The recorded import calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Hold the outcome.
			 *
			 * @param string|array<string, mixed> $outcome The import outcome.
			 */
			public function __construct(private string|array $outcome) {
			}

			/**
			 * Record the call and answer with the outcome.
			 *
			 * @param string               $appId   The app id.
			 * @param array<string, mixed> $data    The register configuration.
			 * @param string               $version The configuration version.
			 * @param bool                 $force   The force flag.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$file = json_decode((string)file_get_contents(SettingsLoadConfigurationGoldenTest::REGISTER_FILE), true);
				$this->calls[] = [
					'appId' => $appId,
					'dataIsTheRegisterFile' => ($data === $file),
					'versionIsTheFileVersion' => ($version === $file['info']['version']),
					'force' => $force,
				];
				if ($this->outcome === 'throws') {
					throw new RuntimeException('import failed');
				}

				return $this->outcome;
			}
		};

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturn($outcome !== 'absent');

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($outcome, $importer): object {
				if ($outcome === 'no-service') {
					throw new RuntimeException('no ' . $id);
				}

				return $importer;
			}
		);

		$logger = $this->logger();
		$service = new SettingsService(
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			appManager: $appManager,
			container: $container,
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $logger
		);

		$result = $service->loadConfiguration(force: $force);
		$file = json_decode((string)file_get_contents(self::REGISTER_FILE), true);
		if (($result['version'] ?? null) === $file['info']['version']) {
			$result['version'] = '(the register file version)';
		}

		return [
			'outcome' => $outcome,
			'force' => $force,
			'result' => $result,
			'calls' => $importer->calls,
			'log' => $logger->lines,
		];

	}//end runCase()

	/**
	 * A logger that keeps each line's level, message and context.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * The recorded lines.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|Stringable    $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};

	}//end logger()
}//end class
