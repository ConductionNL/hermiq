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

        $subjectKey = $notification->getSubject();
        if (in_array($subjectKey, ['run_complete', 'approval_requested'], true) === false) {
            throw new UnknownNotificationException('Unknown Hermiq notification subject');
        }

        $l          = $this->l10nFactory->get(self::APP_ID, $languageCode);
        $subjectRaw = $notification->getSubjectParameters();
        $name       = (string) ($subjectRaw['name'] ?? '');

        // Default to the run-complete wording; the approval-request branch overrides it.
        $subject = $l->t('Scheduled agent run complete');
        $message = $l->t('Your scheduled agent run has produced output.');
        if ($name !== '') {
            $subject = $l->t('“%s” finished', [$name]);
        }

        if ($subjectKey === 'approval_requested') {
            $subject = $l->t('Approval needed for an agent run');
            if ($name !== '') {
                $subject = $l->t('Approval needed: “%s”', [$name]);
            }

            // Source-agnostic wording — the gated run may be a Schedule or a
            // flow-triggered run (OpenRegister AgentRunRequestedEvent, ADR-041);
            // both share this notification path.
            $message = $l->t('An agent run is waiting for your approval before it can execute.');
        }

        $notification->setParsedSubject($subject);
        $notification->setParsedMessage($message);
        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(self::APP_ID, 'app-dark.svg'))
        );

        return $notification;

    }//end prepare()
}//end class
