<?php

/**
 * The `eval_regression` notification (reuse-scheduled-bias-evals, task 3).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Notification
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

namespace OCA\Hermiq\Tests\Unit\Notification;

use OCA\Hermiq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * A dropped scheduled eval reads "<dataset> for <agent> dropped from 95% to 80%" and links to the run.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
 */
class NotifierEvalRegressionTest extends TestCase {

	/**
	 * Prepare one `eval_regression` notification.
	 *
	 * @param array<string, mixed> $parameters The stored subject parameters.
	 * @param string               $objectId   The stored object id (the run).
	 *
	 * @return array{subject: string, message: string, link: string}
	 */
	private function prepared(array $parameters, string $objectId = 'run-7'): array {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img');
		$urls->method('linkToRoute')->willReturn('/apps/hermiq/');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$seen = ['subject' => '', 'message' => '', 'link' => ''];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('hermiq');
		$notification->method('getSubject')->willReturn('eval_regression');
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('getObjectId')->willReturn($objectId);
		foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setLink' => 'link'] as $setter => $key) {
			$notification->method($setter)->willReturnCallback(
				function (string $text) use (&$seen, $key, $notification): INotification {
					$seen[$key] = $text;
					return $notification;
				}
			);
		}

		$notification->method('setIcon')->willReturnSelf();

		(new Notifier($factory, $urls))->prepare($notification, 'en');

		return $seen;
	}//end prepared()

	/**
	 * The drop names the dataset, the agent and both pass rates, and links to the run.
	 *
	 * @return void
	 */
	public function testTheDropNamesBothPassRatesAndLinksToTheRun(): void {
		$seen = $this->prepared(
			['name' => 'Permit intake helper', 'dataset' => 'Bias check', 'datasetId' => 'ds-1', 'previous' => 95, 'current' => 80]
		);

		$this->assertSame('Bias check for Permit intake helper dropped from 95% to 80%', $seen['subject']);
		$this->assertSame('Open the run to see which cases failed.', $seen['message']);
		$this->assertSame('https://cloud.example/apps/hermiq/evals/ds-1?run=run-7', $seen['link']);
	}//end testTheDropNamesBothPassRatesAndLinksToTheRun()

	/**
	 * Without a previous pass rate the subject states the current one only.
	 *
	 * @return void
	 */
	public function testWithoutAPreviousRateTheCurrentOneIsStated(): void {
		$seen = $this->prepared(['name' => 'Helper', 'dataset' => 'Set', 'datasetId' => 'ds-1', 'previous' => null, 'current' => 40]);

		$this->assertSame('Set for Helper fell below its threshold: 40%', $seen['subject']);
	}//end testWithoutAPreviousRateTheCurrentOneIsStated()

	/**
	 * Without a dataset id there is no link to build.
	 *
	 * @return void
	 */
	public function testWithoutADatasetThereIsNoLink(): void {
		$seen = $this->prepared(['name' => 'Helper', 'dataset' => 'Set', 'previous' => 90, 'current' => 70]);

		$this->assertSame('', $seen['link']);
	}//end testWithoutADatasetThereIsNoLink()
}//end class
