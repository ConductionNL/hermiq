<?php

/**
 * EvalRegressionNotifier (reuse-scheduled-bias-evals, task 3).
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
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\EvalRegressionNotifier;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * EvalRegressionNotifier.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
 */
class EvalRegressionNotifierTest extends TestCase {

	/**
	 * The notification goes to the owner, about the run, under the eval_regression subject.
	 *
	 * @return void
	 */
	public function testTheOwnerIsToldAboutTheRun(): void {
		$recorded = [];
		$notification = $this->createMock(originalClassName: INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use (&$recorded, $setter, $notification): INotification {
					$recorded[$setter] = $args;
					return $notification;
				}
			);
		}

		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->once())->method('notify')->with($notification);

		(new EvalRegressionNotifier(notifications: $manager))->notify(
			owner: 'fenna',
			runId: 'run-7',
			parameters: ['name' => 'Helper', 'previous' => 0.95, 'current' => 2 / 3]
		);

		$this->assertSame(expected: ['hermiq'], actual: $recorded['setApp']);
		$this->assertSame(expected: ['fenna'], actual: $recorded['setUser']);
		$this->assertSame(expected: ['evalrun', 'run-7'], actual: $recorded['setObject']);
		$this->assertSame(expected: ['eval_regression', ['name' => 'Helper', 'previous' => 95, 'current' => 67]], actual: $recorded['setSubject']);
	}//end testTheOwnerIsToldAboutTheRun()

	/**
	 * Without a previous run there is no previous percentage.
	 *
	 * @return void
	 */
	public function testWithoutAPreviousRunThereIsNoPreviousPercentage(): void {
		$recorded = [];
		$notification = $this->createMock(originalClassName: INotification::class);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use (&$recorded, $notification): INotification {
				$recorded = $parameters;
				return $notification;
			}
		);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('createNotification')->willReturn($notification);

		(new EvalRegressionNotifier(notifications: $manager))->notify(owner: 'fenna', runId: 'run-7', parameters: ['previous' => null, 'current' => 0.4]);

		$this->assertSame(expected: ['previous' => null, 'current' => 40], actual: $recorded);
	}//end testWithoutAPreviousRunThereIsNoPreviousPercentage()
}//end class
