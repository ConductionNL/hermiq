<?php

/**
 * Hermiq Notifier.
 *
 * Renders Hermiq's Nextcloud notifications for the bell menu. DeliveryService raises
 * notifications for `deliver=notification` schedules and as the terminal fallback of
 * the Talk delivery chain (ADR-005); this INotifier turns the stored subject/message
 * keys into localised text with an icon and a deep link to the schedule.
 *
 * @category Notification
 * @package  OCA\Hermiq\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-delivery/tasks.md#2-notifier-registration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Parses Hermiq notifications into localised, rendered form.
 *
 * @spec openspec/changes/talk-delivery/tasks.md#task-2-1
 */
class Notifier implements INotifier
{

    /**
     * The app id this notifier serves.
     *
     * @var string
     */
    private const APP_ID = 'hermiq';

    /**
     * Constructor.
     *
     * @param IFactory      $l10nFactory  Resolves the localisation for the recipient's language.
     * @param IURLGenerator $urlGenerator Builds the notification icon URL.
     */
    public function __construct(
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Identifier of the notifier, only use [a-z0-9_].
     *
     * @return string
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-2-1
     */
    public function getID(): string
    {
        return self::APP_ID;

    }//end getID()

    /**
     * Human-readable name describing the notifier.
     *
     * @return string
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-2-1
     */
    public function getName(): string
    {
        return 'Hermiq';

    }//end getName()

    /**
     * Prepare a Hermiq notification for display.
     *
     * @param INotification $notification The raw notification.
     * @param string        $languageCode The recipient's language code.
     *
     * @return INotification The prepared notification.
     *
     * @throws UnknownNotificationException When the notification is not a Hermiq one.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-2-1
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== self::APP_ID) {
            throw new UnknownNotificationException('Notification not handled by Hermiq');
        }

        if ($notification->getSubject() !== 'run_complete') {
            throw new UnknownNotificationException('Unknown Hermiq notification subject');
        }

        $l          = $this->l10nFactory->get(self::APP_ID, $languageCode);
        $subjectRaw = $notification->getSubjectParameters();
        $name       = (string) ($subjectRaw['name'] ?? '');

        $subject = $l->t('Scheduled agent run complete');
        if ($name !== '') {
            $subject = $l->t('“%s” finished', [$name]);
        }

        $notification->setParsedSubject($subject);
        $notification->setParsedMessage($l->t('Your scheduled agent run has produced output.'));
        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(self::APP_ID, 'app-dark.svg'))
        );

        return $notification;

    }//end prepare()
}//end class
