<?php

/**
 * Hermiq ReportSubscriptionJob.
 *
 * Sends every due report subscription as a Nextcloud notification that opens
 * the report for the period it covers, then records when it was sent.
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Hermiq\Service\ReportSubscriptionSchedule;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly: every active subscription whose week or month has passed gets its report.
 *
 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
 */
class ReportSubscriptionJob extends TimedJob {

	private const REGISTER = 'hermiq';

	private const SCHEMA = 'reportsubscription';

	/**
	 * The page route of each report a person can subscribe to, by its card id on the Reports page.
	 *
	 * @var array<string, string>
	 */
	public const REPORT_ROUTES = [
		'AiOversight' => 'ai-oversight',
	];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory               $time          The clock.
	 * @param ObjectService              $objectService Reads the subscriptions and records each send.
	 * @param IManager                   $notifications Sends the notification.
	 * @param IURLGenerator              $urlGenerator  Builds the link to the report.
	 * @param LoggerInterface            $logger        Reports a subscription that could not be sent.
	 * @param ReportSubscriptionSchedule $schedule      When a subscription is due, and its period.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectService $objectService,
		private readonly IManager $notifications,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly ReportSubscriptionSchedule $schedule,
	) {
		parent::__construct(time: $time);

		// Hourly: a weekly or monthly report then goes out within the hour it is due.
		$this->setInterval(seconds: 3600);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Send every due subscription; one that fails is logged and the others still go.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
	 */
	protected function run($argument): void {
		$now = new DateTimeImmutable('@' . $this->time->getTime());
		foreach ($this->subscriptions() as $subscription) {
			try {
				$this->sendIfDue(subscription: $subscription, now: $now);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[hermiq] A report subscription could not be sent: ' . $e->getMessage(),
					context: ['subscription' => (string)$subscription->getUuid()]
				);
			}
		}
	}//end run()

	/**
	 * Send one subscription's report when it is due, and record the moment.
	 *
	 * A subscription counts only for the person who made it: one whose `person`
	 * is not its owner is skipped, so nobody can subscribe somebody else.
	 *
	 * @param ObjectEntity      $subscription The subscription.
	 * @param DateTimeImmutable $now          The moment of this run.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
	 */
	public function sendIfDue(ObjectEntity $subscription, DateTimeImmutable $now): bool {
		$data      = $subscription->getObject();
		$person    = (string)($data['person'] ?? '');
		$report    = (string)($data['report'] ?? '');
		$frequency = (string)($data['frequency'] ?? '');
		if ($person === '' || $person !== (string)$subscription->getOwner() || isset(self::REPORT_ROUTES[$report]) === false) {
			return false;
		}

		$due = $this->schedule->isDue(
			status: (string)($data['status'] ?? ReportSubscriptionSchedule::ACTIVE),
			frequency: $frequency,
			lastSent: $this->moment(value: $data['lastSent'] ?? null),
			now: $now
		);
		$period = $this->schedule->period(frequency: $frequency, now: $now);
		if ($due === false || $period === null) {
			return false;
		}

		$this->notify(subscription: $subscription, person: $person, report: $report, period: $period);

		$data['lastSent'] = $now->format(DateTimeInterface::ATOM);
		unset($data['@self'], $data['id']);
		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: (string)$subscription->getUuid(),
			_rbac: false,
			_multitenancy: false
		);

		return true;
	}//end sendIfDue()

	/**
	 * The link that opens the report for the period.
	 *
	 * @param string                                                   $report The report's card id.
	 * @param array{from: DateTimeImmutable, to: DateTimeImmutable} $period The period.
	 *
	 * @return string The absolute link.
	 *
	 * @spec openspec/changes/reports-on-a-schedule/specs/reports/spec.md#requirement-a-person-shall-receive-a-report-on-a-schedule-they-choose
	 */
	public function reportLink(string $report, array $period): string {
		$base = rtrim($this->urlGenerator->linkToRouteAbsolute('hermiq.dashboard.page'), '/');

		return $base . '/' . self::REPORT_ROUTES[$report] . '?' . http_build_query(
			['from' => $period['from']->format('Y-m-d'), 'to' => $period['to']->format('Y-m-d')]
		);
	}//end reportLink()

	/**
	 * Tell the person their report is ready.
	 *
	 * @param ObjectEntity                                              $subscription The subscription.
	 * @param string                                                   $person       The recipient.
	 * @param string                                                   $report       The report's card id.
	 * @param array{from: DateTimeImmutable, to: DateTimeImmutable} $period       The period it covers.
	 *
	 * @return void
	 */
	private function notify(ObjectEntity $subscription, string $person, string $report, array $period): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp('hermiq')
			->setUser($person)
			->setDateTime(DateTime::createFromImmutable($period['to']))
			->setObject(self::SCHEMA, (string)$subscription->getUuid())
			->setSubject(
				'report_due',
				[
					'report' => $report,
					'from'   => $period['from']->format('Y-m-d'),
					'to'     => $period['to']->format('Y-m-d'),
				]
			)
			->setLink($this->reportLink(report: $report, period: $period));
		$this->notifications->notify($notification);
	}//end notify()

	/**
	 * The active subscriptions, read as the system.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function subscriptions(): array {
		$found = $this->objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
			config: ['filters' => ['status' => ReportSubscriptionSchedule::ACTIVE], 'limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter($found, static fn (mixed $row): bool => $row instanceof ObjectEntity));
	}//end subscriptions()

	/**
	 * A stored moment, or null when it is absent or unreadable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}//end moment()
}//end class
