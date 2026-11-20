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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
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

		(new EvalRegressionNotifier(notifications: $manager))->notify(owner: 'fenna', runId: 'run-7', parameters: ['current' => 80]);

		$this->assertSame(expected: ['hermiq'], actual: $recorded['setApp']);
		$this->assertSame(expected: ['fenna'], actual: $recorded['setUser']);
		$this->assertSame(expected: ['evalrun', 'run-7'], actual: $recorded['setObject']);
		$this->assertSame(expected: ['eval_regression', ['current' => 80]], actual: $recorded['setSubject']);
	}//end testTheOwnerIsToldAboutTheRun()

	/**
	 * A 0–1 pass rate becomes a whole percentage; no rate stays null.
	 *
	 * @return void
	 */
	public function testAPassRateBecomesAWholePercentage(): void {
		$this->assertSame(expected: 95, actual: EvalRegressionNotifier::percent(passRate: 0.95));
		$this->assertSame(expected: 67, actual: EvalRegressionNotifier::percent(passRate: 2 / 3));
		$this->assertNull(actual: EvalRegressionNotifier::percent(passRate: null));
	}//end testAPassRateBecomesAWholePercentage()
}//end class
