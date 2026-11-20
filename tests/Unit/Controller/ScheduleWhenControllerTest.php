<?php

/**
 * POST /api/schedules/parse-when (scheduling-overview-and-start-choice, REQ-SCHED-003).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Hermiq\Controller\ScheduleWhenController;
use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Service\ScheduleWhenParser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ScheduleWhenControllerTest extends TestCase {

	private ProviderFactoryInterface&MockObject $providers;

	/** @var array<int, string> */
	private array $prompts = [];

	/**
	 * The controller for a signed-in team lead in Amsterdam, on Friday 13 November 2026 at 10:00.
	 *
	 * @param string|null $uid       The caller, null for none.
	 * @param mixed       $answer    What the model answers; a Throwable is thrown.
	 * @param string      $userZone  The caller's timezone setting.
	 *
	 * @return ScheduleWhenController
	 */
	private function controller(?string $uid = 'lead', mixed $answer = '{"kind":"none"}', string $userZone = 'Europe/Amsterdam'): ScheduleWhenController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($userZone);
		$config->method('getSystemValueString')->willReturn('Europe/London');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-13T09:00:00+00:00'));

		$this->providers = $this->createMock(ProviderFactoryInterface::class);
		$this->providers->method('generateText')->willReturnCallback(
			function (string $prompt, ?string $userId = null) use ($answer): string {
				$this->prompts[] = $prompt;
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return (string)$answer;
			}
		);

		return new ScheduleWhenController(
			appName: 'hermiq',
			request: $this->createMock(IRequest::class),
			parser: new ScheduleWhenParser(),
			providers: $this->providers,
			userSession: $session,
			config: $config,
			time: $time,
			logger: new NullLogger()
		);
	}//end controller()

	public function testAWeekdayDigestReadsBackWithItsNextThreeRuns(): void {
		$response = $this->controller()->parse(text: 'every weekday at eight', locale: 'en');

		self::assertSame(200, $response->getStatus());
		self::assertSame(
			[
				'kind' => 'cron',
				'cronExpr' => '0 8 * * 1-5',
				'readback' => 'Every weekday at 08:00, Europe/Amsterdam',
				'nextRuns' => ['2026-11-16T08:00:00+01:00', '2026-11-17T08:00:00+01:00', '2026-11-18T08:00:00+01:00'],
				'source' => 'rules',
			],
			$response->getData()
		);
		self::assertSame([], $this->prompts, 'The model is not asked when the rules read the text.');
	}//end testAWeekdayDigestReadsBackWithItsNextThreeRuns()

	public function testADutchPhraseAndAnInterval(): void {
		$dutch = $this->controller()->parse(text: 'elke maandag om negen uur', locale: 'nl')->getData();
		self::assertSame('0 9 * * 1', $dutch['cronExpr']);
		self::assertSame('Elke maandag om 09:00, Europe/Amsterdam', $dutch['readback']);

		$interval = $this->controller()->parse(text: 'every 2 hours', locale: 'en')->getData();
		self::assertSame(['2026-11-13T12:00:00+01:00', '2026-11-13T14:00:00+01:00', '2026-11-13T16:00:00+01:00'], $interval['nextRuns']);

		$once = $this->controller()->parse(text: 'tomorrow at 7:00', locale: 'en')->getData();
		self::assertSame(['2026-11-14T07:00:00+01:00'], $once['nextRuns']);
	}//end testADutchPhraseAndAnInterval()

	public function testTheInstanceTimezoneIsUsedWhenTheCallerHasNone(): void {
		$data = $this->controller(userZone: '')->parse(text: 'every day at 8', locale: 'en')->getData();

		self::assertSame('Every day at 08:00, Europe/London', $data['readback']);
		self::assertSame('2026-11-14T08:00:00+00:00', $data['nextRuns'][0]);
	}//end testTheInstanceTimezoneIsUsedWhenTheCallerHasNone()

	public function testTheModelReadsWhatTheRulesCannotAndOnlyAValidRuleIsAccepted(): void {
		$data = $this->controller(answer: "Sure:\n{\"kind\":\"cron\",\"cronExpr\":\"0 9 15 * *\"}")->parse(text: 'on the fifteenth of every month at nine', locale: 'en')->getData();
		self::assertSame('0 9 15 * *', $data['cronExpr']);
		self::assertSame('model', $data['source']);
		self::assertSame('On the rule 0 9 15 * *, Europe/Amsterdam', $data['readback']);
		self::assertStringContainsString('"on the fifteenth of every month at nine"', $this->prompts[0]);

		$interval = $this->controller(answer: '{"kind":"interval","intervalMinutes":45}')->parse(text: 'three quarters of an hour apart', locale: 'nl')->getData();
		self::assertSame(45, $interval['intervalMinutes']);
		self::assertSame('Elke 45 minuten, Europe/Amsterdam', $interval['readback']);

		$refused = [
			'{"kind":"none"}',
			'{"kind":"cron","cronExpr":"every day"}',
			'{"kind":"cron","cronExpr":"0 0 9 * * *"}',
			'{"kind":"cron","cronExpr":"61 25 * * *"}',
			'{"kind":"interval","intervalMinutes":0}',
			'{"kind":"interval","intervalMinutes":"15"}',
			'{"kind":"once","runAt":"2020-01-01T09:00:00+01:00"}',
			'{"kind":"once","runAt":"not a date"}',
			'no json at all',
			new RuntimeException('no model configured'),
		];
		foreach ($refused as $answer) {
			$response = $this->controller(answer: $answer)->parse(text: 'whenever the moon is full', locale: 'en');
			self::assertSame(422, $response->getStatus(), is_string($answer) ? $answer : 'exception');
			self::assertSame(['message' => 'I could not read that. Try for example: every weekday at 8:00.'], $response->getData());
		}
	}//end testTheModelReadsWhatTheRulesCannotAndOnlyAValidRuleIsAccepted()

	public function testEmptyOrOverlongTextIsRefusedWithoutAskingTheModel(): void {
		self::assertSame(422, $this->controller()->parse(text: '  ', locale: 'nl')->getStatus());
		self::assertSame(
			['message' => 'Dat kon ik niet lezen. Probeer bijvoorbeeld: elke werkdag om 8:00.'],
			$this->controller()->parse(text: str_repeat('a', ScheduleWhenController::MAX_TEXT + 1), locale: 'nl')->getData()
		);
		self::assertSame([], $this->prompts);
	}//end testEmptyOrOverlongTextIsRefusedWithoutAskingTheModel()

	public function testWithoutASessionNothingIsRead(): void {
		self::assertSame(401, $this->controller(uid: null)->parse(text: 'every day at 8', locale: 'en')->getStatus());
	}//end testWithoutASessionNothingIsRead()
}//end class
