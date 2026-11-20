<?php

/**
 * The run-done notification names the agent and the result (personal-preferences).
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
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
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
 * @covers \OCA\Hermiq\Notification\Notifier
 */
class NotifierRunDoneTest extends TestCase {

	/**
	 * The parsed [subject, message] of a run_done notification.
	 *
	 * @param array<string, string> $parameters The stored subject parameters.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function parsed(array $parameters): array {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img');
		$urls->method('getAbsoluteURL')->willReturn('https://x/img');

		$subject = '';
		$message = '';
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('hermiq');
		$notification->method('getSubject')->willReturn('run_done');
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $text) use (&$subject, $notification): INotification {
				$subject = $text;
				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			function (string $text) use (&$message, $notification): INotification {
				$message = $text;
				return $notification;
			}
		);
		$notification->method('setIcon')->willReturnSelf();

		(new Notifier($factory, $urls))->prepare($notification, 'nl');

		return [$subject, $message];
	}//end parsed()

	/**
	 * A successful run names the agent and says it finished.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function testASuccessfulRunNamesTheAgentAndTheResult(): void {
		self::assertSame(
			['Hulp bij Woo-besluit is done', 'The run finished successfully.'],
			$this->parsed(['name' => 'Hulp bij Woo-besluit', 'result' => 'ok'])
		);
	}//end testASuccessfulRunNamesTheAgentAndTheResult()

	/**
	 * A failed run says so; without a name the subject still reads.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function testAFailedRunSaysItEndedWithAnError(): void {
		self::assertSame(
			['Hulp bij Woo-besluit is done', 'The run ended with an error.'],
			$this->parsed(['name' => 'Hulp bij Woo-besluit', 'result' => 'error'])
		);
		self::assertSame(['Your agent run is done', 'The run ended with an error.'], $this->parsed([]));
	}//end testAFailedRunSaysItEndedWithAnError()
}//end class
