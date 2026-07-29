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

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Parses Hermiq notifications into localised, rendered form.
 *
 * @spec openspec/changes/talk-delivery/tasks.md#2-notifier-registration
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
     * Every subject key this notifier can render — one per DeliveryService alert
     * shape. Extending this list (run-reliability added the last two) also fixes a
     * pre-existing gap: `budget_soft_threshold` (cost-guardrails) was raised by
     * DeliveryService but never recognised here, so the bell menu threw
     * UnknownNotificationException whenever it tried to render one.
     *
     * @var array<int, string>
     */
    private const KNOWN_SUBJECTS = [
        'run_complete',
        'approval_requested',
        'budget_soft_threshold',
        'run_dead_letter',
        'schedule_paused_circuit_breaker',
        'skill_published_behind',
        'skill_rollback_suggested',
    ];

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
     * @spec openspec/changes/talk-delivery/tasks.md#2-notifier-registration
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
     * @spec openspec/changes/talk-delivery/tasks.md#2-notifier-registration
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
     * @spec openspec/changes/talk-delivery/tasks.md#2-notifier-registration
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== self::APP_ID) {
            throw new UnknownNotificationException('Notification not handled by Hermiq');
        }

        $subjectKey = $notification->getSubject();
        if (in_array($subjectKey, self::KNOWN_SUBJECTS, true) === false) {
            throw new UnknownNotificationException('Unknown Hermiq notification subject');
        }

        $l          = $this->l10nFactory->get(self::APP_ID, $languageCode);
        $subjectRaw = $notification->getSubjectParameters();

        [$subject, $message] = $this->resolveSubjectAndMessage(subjectKey: $subjectKey, subjectRaw: $subjectRaw, l: $l);

        $notification->setParsedSubject($subject);
        $notification->setParsedMessage($message);
        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(self::APP_ID, 'app-dark.svg'))
        );

        return $notification;

    }//end prepare()

    /**
     * Resolve the localised [subject, message] pair for one known subject key.
     *
     * Dispatches to a small per-subject helper so each stays simple (one
     * name-substitution branch) rather than a single long if/elseif chain.
     *
     * @param string              $subjectKey The notification's stored subject key.
     * @param array<string,mixed> $subjectRaw The stored subject parameters.
     * @param IL10N               $l          The recipient-language localisation.
     *
     * @return array{0:string,1:string} The [subject, message] pair.
     *
     * @spec exclude Pure dispatch extracted from prepare(); each branch's own spec
     *   tag lives on the per-subject helper it delegates to.
     */
    private function resolveSubjectAndMessage(string $subjectKey, array $subjectRaw, IL10N $l): array
    {
        $name = (string) ($subjectRaw['name'] ?? '');

        if ($subjectKey === 'approval_requested') {
            return $this->approvalRequestedText(name: $name, l: $l);
        }

        if ($subjectKey === 'budget_soft_threshold') {
            return $this->budgetSoftThresholdText(subjectRaw: $subjectRaw, l: $l);
        }

        if ($subjectKey === 'run_dead_letter') {
            return $this->runDeadLetterText(name: $name, l: $l);
        }

        if ($subjectKey === 'schedule_paused_circuit_breaker') {
            return $this->circuitBreakerPausedText(name: $name, l: $l);
        }

        if ($subjectKey === 'skill_published_behind') {
            return $this->skillPublishedBehindText(name: $name, l: $l);
        }

        if ($subjectKey === 'skill_rollback_suggested') {
            return $this->skillRollbackSuggestedText(name: $name, l: $l);
        }

        return $this->runCompleteText(name: $name, l: $l);

    }//end resolveSubjectAndMessage()

    /**
     * The default `run_complete` wording (also the fallback for any subject key
     * whose own branch does not override it).
     *
     * @param string $name The schedule's display name, when known.
     * @param IL10N  $l    The recipient-language localisation.
     *
     * @return array{0:string,1:string} The [subject, message] pair.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#2-notifier-registration
     */
    private function runCompleteText(string $name, IL10N $l): array
    {
        $subject = $l->t('Scheduled agent run complete');
        if ($name !== '') {
            $subject = $l->t('“%s” finished', [$name]);
        }

        return [$subject, $l->t('Your scheduled agent run has produced output.')];

    }//end runCompleteText()

    /**
     * The `approval_requested` wording (human-approval-gate-enforcement).
     *
     * @param string $name The gated schedule/flow's display name, when known.
     * @param IL10N  $l    The recipient-language localisation.
     *
     * @return array{0:string,1:string} The [subject, message] pair.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#2-dispatcher-approval-gate-scheduleservice
     */
    private function approvalRequestedText(string $name, IL10N $l): array
    {
        $subject = $l->t('Approval needed for an agent run');
        if ($name !== '') {
            $subject = $l->t('Approval needed: “%s”', [$name]);
        }

        // Source-agnostic wording — the gated run may be a Schedule or a
        // flow-triggered run (OpenRegister AgentRunRequestedEvent, ADR-041); both
        // share this notification path.
        return [$subject, $l->t('An agent run is waiting for your approval before it can execute.')];

    }//end approvalRequestedText()

    /**
     * The `budget_soft_threshold` wording (cost-guardrails).
     *
     * @param array<string,mixed> $subjectRaw The stored subject parameters (`label`).
     * @param IL10N               $l          The recipient-language localisation.
     *
     * @return array{0:string,1:string} The [subject, message] pair.
     *
     * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-3-wire-the-budget-gate-into-the-dispatch-path-soft-threshold-delivery
     */
    private function budgetSoftThresholdText(array $subjectRaw, IL10N $l): array
    {
        $label   = (string) ($subjectRaw['label'] ?? '');
        $subject = $l->t('Budget threshold reached');
        if ($label !== '') {
            $subject = $l->t('Budget threshold reached: “%s”', [$label]);
        }

        return [$subject, $l->t('A budget has crossed its soft threshold for the current period.')];

    }//end budgetSoftThresholdText()

    /**
     * The `run_dead_letter` wording (run-reliability): a retry-enabled occurrence
     * exhausted its retry budget.
     *
     * @param string $name The schedule's display name, when known.
     * @param IL10N  $l    The recipient-language localisation.
     *
     * @return array{0:string,1:string} The [subject, message] pair.
     *
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    private function runDeadLetterText(string $name, IL10N $l): array
    {
        $subject = $l->t('A scheduled run failed permanently');
        if ($name !== '') {
            $subject = $l->t('“%s” failed permanently', [$name]);
        }

        return [$subject, $l->t('All retries have been exhausted. Review the run history and re-run manually if needed.')];

    }//end runDeadLetterText()

    /**
     * The `schedule_paused_circuit_breaker` wording (run-reliability): the
     * consecutive-dead-letter circuit breaker auto-paused the schedule.
     *
     * @param string $name The schedule's display name, when known.
     * @param IL10N  $l    The recipient-language localisation.
     *
     * @return array{0:string,1:string} The [subject, message] pair.
     *
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    private function circuitBreakerPausedText(string $name, IL10N $l): array
    {
        $subject = $l->t('A schedule was paused automatically');
        if ($name !== '') {
            $subject = $l->t('“%s” was paused automatically', [$name]);
        }

        return [$subject, $l->t('It was disabled after repeated failures. Review it and re-enable when ready.')];

    }//end circuitBreakerPausedText()

    /**
     * The `skill_published_behind` wording (skill-self-improvement): an accepted
     * skill version postdates the GitHub publish — an explicit, never-automatic
     * republish is available.
     *
     * @param string $name The skill's display name, when known.
     * @param IL10N  $l    The recipient-language localisation.
     *
     * @return array{0:string,1:string} The [subject, message] pair.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
     */
    private function skillPublishedBehindText(string $name, IL10N $l): array
    {
        $subject = $l->t('A published skill is behind its accepted version');
        if ($name !== '') {
            $subject = $l->t('Published copy of “%s” is behind', [$name]);
        }

        $message = $l->t('A newer version was accepted locally. Republish it to GitHub when you are ready — nothing is pushed automatically.');

        return [$subject, $message];

    }//end skillPublishedBehindText()

    /**
     * The `skill_rollback_suggested` wording (skill-self-improvement): the next eval
     * run after an accepted draft regressed — an advisory rollback suggestion.
     *
     * @param string $name The skill's display name, when known.
     * @param IL10N  $l    The recipient-language localisation.
     *
     * @return array{0:string,1:string} The [subject, message] pair.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
     */
    private function skillRollbackSuggestedText(string $name, IL10N $l): array
    {
        $subject = $l->t('A skill may need a rollback');
        if ($name !== '') {
            $subject = $l->t('“%s” regressed after its last accepted version', [$name]);
        }

        $message = $l->t('The first eval run after acceptance failed the regression gate. Review the versions and roll back if you agree.');

        return [$subject, $message];

    }//end skillRollbackSuggestedText()
}//end class
