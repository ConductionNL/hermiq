<?php
/**
 * Hermiq: a notification when a run is done (personal-preferences, task 2.4).
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTime;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the person who started a run a Nextcloud notification naming the
 * agent and the result, when they switched "Melding als een run klaar is" on.
 *
 * Never throws: a notification that cannot be sent is logged and the run's
 * own outcome stands.
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */
class RunDoneNotifier {

	/**
	 * The notification subject the Notifier renders.
	 *
	 * @var string
	 */
	public const SUBJECT = 'run_done';

	/**
	 * Constructor.
	 *
	 * @param PersonalPreferences  $preferences         The person's own preferences.
	 * @param INotificationManager $notificationManager Nextcloud notifications.
	 * @param IURLGenerator        $urlGenerator        Builds the link to the run list.
	 * @param LoggerInterface      $logger              Logger.
	 *
	 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function __construct(
		private readonly PersonalPreferences $preferences,
		private readonly INotificationManager $notificationManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Notify the person a scheduled run finished, if they asked for it.
	 *
	 * A successful run whose output was itself delivered as a notification is
	 * skipped: that person already got one for the same run.
	 *
	 * @param string $userId       The person who started the run.
	 * @param string $agentName    The agent's name ('' when unknown).
	 * @param string $scheduleUuid The schedule that ran.
	 * @param bool   $succeeded    Whether the run succeeded.
	 * @param string $channel      The channel the run's output was delivered on.
	 *
	 * @return bool Whether a notification was sent.
	 *
	 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function notifyScheduledRun(
		string $userId,
		string $agentName,
		string $scheduleUuid,
		bool $succeeded,
		string $channel,
	): bool {
		if ($this->preferences->isOn(userId: $userId, key: PersonalPreferences::NOTIFY_RUN_DONE) === false) {
			return false;
		}

		if ($succeeded === true && $channel === 'notification') {
			return false;
		}

		$result = 'error';
		if ($succeeded === true) {
			$result = 'ok';
		}

		return $this->sendRunDone(
			userId: $userId,
			run: ['agentName' => $agentName, 'scheduleUuid' => $scheduleUuid, 'result' => $result]
		);
	}//end notifyScheduledRun()

	/**
	 * Send the run-done notification. A failure is logged, never raised.
	 *
	 * @param string                                                         $userId The person to notify.
	 * @param array{agentName: string, scheduleUuid: string, result: string} $run    The agent's name, the schedule and `ok`|`error`.
	 *
	 * @return bool Whether the notification was sent.
	 *
	 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	private function sendRunDone(string $userId, array $run): bool {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('hermiq')
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject('schedule', $run['scheduleUuid'])
				->setSubject(self::SUBJECT, ['name' => $run['agentName'], 'result' => $run['result']])
				->setLink(
					$this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/runs?schedule=' . rawurlencode($run['scheduleUuid']))
				);
			$this->notificationManager->notify($notification);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Hermiq could not send the run-done notification for %s: %s', $run['scheduleUuid'], $e->getMessage()),
				['exception' => $e]
			);
			return false;
		}//end try
	}//end sendRunDone()
}//end class
