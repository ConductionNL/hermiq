<?php

/**
 * Tells a schedule's owner that a scheduled eval run fell below its regression threshold.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use DateTime;
use OCP\Notification\IManager;

/**
 * Sends the `eval_regression` notification; `Notifier` renders it.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
 */
class EvalRegressionNotifier {

	/**
	 * The notification subject.
	 */
	public const SUBJECT = 'eval_regression';

	/**
	 * Constructor.
	 *
	 * @param IManager $notifications Nextcloud's notification manager.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
	 */
	public function __construct(
		private readonly IManager $notifications,
	) {
	}//end __construct()

	/**
	 * Notify the owner about one run.
	 *
	 * @param string               $owner      The schedule's owner.
	 * @param string               $runId      The EvalRun uuid.
	 * @param array<string, mixed> $parameters The subject parameters: name, dataset, datasetId, previous, current.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
	 */
	public function notify(string $owner, string $runId, array $parameters): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(app: 'hermiq')
			->setUser(user: $owner)
			->setDateTime(dateTime: new DateTime())
			->setObject(type: 'evalrun', id: $runId)
			->setSubject(subject: self::SUBJECT, parameters: $parameters);
		$this->notifications->notify(notification: $notification);
	}//end notify()

	/**
	 * A 0–1 pass rate as a whole percentage, or null when there is none.
	 *
	 * @param mixed $passRate The pass rate.
	 *
	 * @return int|null The percentage.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
	 */
	public static function percent(mixed $passRate): ?int {
		if (is_numeric(value: $passRate) === false) {
			return null;
		}

		return (int)round(num: ((float)$passRate * 100));
	}//end percent()
}//end class
