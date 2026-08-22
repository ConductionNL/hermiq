<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Listener
 * @package   OCA\Hermiq\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Event\AiOversightRecordedEvent;
use OCA\Hermiq\Service\AiOversightService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Records a consumer app's human-oversight decision as an advisory Approval.
 *
 * SYNCHRONOUS, unlike AgentRunRequestedListener which queues a job. That
 * listener starts work the user waits for; this one stores a fact the user has
 * already produced, and the consumer reads `isHandled()` straight after
 * dispatch to tell "recorded" from "hermiq is not installed". Deferring to a
 * background job would make that answer a lie.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md
 */
class AiOversightRecordedListener implements IEventListener {


    /**
     * Constructor.
     *
     * @param AiOversightService $oversight Writes the advisory Approval.
     *
     * @return void
     */
    public function __construct(
        private readonly AiOversightService $oversight,
    ) {

    }//end __construct()


    /**
     * Handle the oversight-recorded event.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     *
     * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md
     */
    public function handle(Event $event): void {
        if ($event instanceof AiOversightRecordedEvent === false) {
            return;
        }

        $approvalId = $this->oversight->record($event->getRecord());
        if ($approvalId !== null) {
            $event->setApprovalId($approvalId);
        }

        // No else: a refused or failed record leaves isHandled() false, which is
        // exactly what the consumer needs to see. The service has already logged
        // why.
    }//end handle()


}//end class
