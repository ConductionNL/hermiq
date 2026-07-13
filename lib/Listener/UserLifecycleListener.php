<?php

/**
 * Hermiq UserLifecycleListener.
 *
 * Listens for a Nextcloud user being deleted or disabled and delegates the
 * offboarding pause mechanic to ScheduleService::pauseForUser() (agent-lifecycle-
 * governance): every Schedule owned by, or whose Agent's actingUser resolves to,
 * the affected user is auto-paused and the owning Agent(s) are flagged for
 * reassignment — closing the EU AI Act Art. 14 "orphaned agent" gap.
 *
 * Nextcloud (this server's `OCP\User\Events` at HEAD) has NO dedicated
 * `DisableUserEvent`: disabling a user fires `UserChangedEvent` with
 * `feature === 'enabled'` and `value === false` (see `User::setEnabled()`).
 * This listener reacts to both `UserDeletedEvent` and that specific
 * `UserChangedEvent` shape — the design.md reference to a `DisableUserEvent`
 * predates this verification against HEAD and does not exist as a class.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\ScheduleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a Nextcloud user deletion/disable into an offboarding pause.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
 */
class UserLifecycleListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param ScheduleService $scheduleService The offboarding pause mechanic.
     * @param LoggerInterface $logger          Logs the outcome / any failure.
     */
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a Nextcloud user-deleted or user-disabled event.
     *
     * Never throws: a failure here MUST NOT abort the underlying Nextcloud user
     * deletion/disable operation it is reacting to — it is logged and swallowed.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
     */
    public function handle(Event $event): void
    {
        try {
            $uid = $this->resolveOffboardedUid(event: $event);
            if ($uid === null) {
                return;
            }

            $paused = $this->scheduleService->pauseForUser(uid: $uid);
            $this->logger->info(
                sprintf('Hermiq offboarding: paused %d schedule(s) for user %s.', $paused, $uid)
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq offboarding listener failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end handle()

    /**
     * Resolve the offboarded user id from a supported event, or null when the
     * event is not one this listener reacts to (e.g. a `UserChangedEvent` for
     * a different feature, or one that RE-ENABLES a user).
     *
     * @param Event $event The dispatched event.
     *
     * @return string|null The offboarded uid, or null when not applicable.
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
     */
    private function resolveOffboardedUid(Event $event): ?string
    {
        if ($event instanceof UserDeletedEvent) {
            return $event->getUser()->getUID();
        }

        if ($event instanceof UserChangedEvent
            && $event->getFeature() === 'enabled'
            && $event->getValue() === false
        ) {
            return $event->getUser()->getUID();
        }

        return null;

    }//end resolveOffboardedUid()
}//end class
