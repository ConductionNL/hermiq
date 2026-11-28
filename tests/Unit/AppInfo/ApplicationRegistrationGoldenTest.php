<?php

/**
 * Golden test: what Application::register() registers, in order.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\AppInfo;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Listener\AgentWaiverChangeListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Listeners on one event run in registration order, so the order is part of the contract.
 *
 * @covers \OCA\Hermiq\AppInfo\Application
 * @uses   \OCA\Hermiq\AppInfo\OpenRegisterAutoloader
 */
class ApplicationRegistrationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/application-registrations.json';

	/**
	 * Every registration and init script, in order, compared with the recorded list.
	 *
	 * The waiver listener's events are left out: they follow the audit's scope,
	 * not the shape of register().
	 *
	 * @return void
	 */
	public function testRegisterMakesTheRecordedRegistrations(): void {
		$calls = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		foreach ((new ReflectionClass(objectOrClass: IRegistrationContext::class))->getMethods() as $method) {
			$name = $method->getName();
			$context->method($name)->willReturnCallback(
				static function (mixed ...$args) use (&$calls, $name): void {
					$calls[] = [$name, ...array_values($args)];
				}
			);
		}

		$application = new class($calls) extends Application {
			/**
			 * Record init scripts beside the registrations, instead of booting the server's asset API.
			 *
			 * @param array<int, array<int, mixed>> $calls The shared call list.
			 */
			public function __construct(private array &$calls) {
			}//end __construct()

			/**
			 * Record one init script.
			 *
			 * @param string $suffix The bundle name after the app id.
			 *
			 * @return void
			 */
			protected function addInitScript(string $suffix): void {
				$this->calls[] = ['addInitScript', $suffix];
			}//end addInitScript()
		};
		$application->register($context);

		$calls = array_values(array_filter($calls, static fn (array $call): bool => in_array(AgentWaiverChangeListener::class, $call, true) === false));
		$recorded = ['registrations' => $calls];
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode((string)file_get_contents(self::FIXTURE), true), actual: $recorded);
	}//end testRegisterMakesTheRecordedRegistrations()
}//end class
