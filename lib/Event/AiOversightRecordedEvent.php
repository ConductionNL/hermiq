<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Event
 * @package   OCA\Hermiq\Event
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Event;

use OCP\EventDispatcher\Event;

/**
 * A consumer app recorded a human decision on an AI suggestion (EU AI Act Art. 14).
 *
 * Hermiq owns human oversight; the apps that run AI against their own domain
 * objects own the moment the human decides. This event is how the second tells
 * the first, so the evidence lands in one register instead of one per app.
 *
 * ADVISORY, NOT A GATE. Nothing is blocked and nothing resumes: by the time this
 * fires the human has already acted. It is the non-blocking sibling of the
 * Approval flow, and it is why `Approval.sourceType` gained an `advisory` value
 * rather than a new schema — a gate and a record of a decision are the same fact
 * at different times, and splitting them would have split the Art. 14 audit
 * trail in two.
 *
 * PAYLOAD IS ONE ARRAY, ON PURPOSE. decidesk's DecisionRequestedEvent is the
 * precedent for cross-app contracts here, and its own docblock records the trap:
 * ten positional scalars became a published contract that could not be
 * regrouped without silently breaking every consumer at runtime. Consumers
 * construct this through a class-string (`new $cls($record)`) so they stay
 * installable without hermiq, so ONE associative argument keeps that property
 * while letting the key set grow without shifting a position.
 *
 * Recognised keys (all optional except the four marked required — a record
 * missing those is evidence of nothing and the listener refuses it):
 *
 *   originApp       string  REQUIRED  app id whose surface produced the suggestion
 *   subjectType     string  REQUIRED  origin app's object type ('case', 'document')
 *   subjectId       string  REQUIRED  that object's id in the origin app
 *   humanAction     string  REQUIRED  'accepted' | 'rejected' | 'overridden'
 *   userId          string            NC uid of the human who decided
 *   decidedAt       string            RFC3339 timestamp of the decision
 *   suggestionType  string            classification | extraction | summarisation | routing | answer
 *   action          string            the origin app's own name for the operation
 *   model           string            model that produced the suggestion
 *   prompt          string            prompt sent to the model
 *   suggestion      string            what the AI proposed
 *   confidence      float             0..1, when the model reported one
 *   actualValue     string            what the human actually used (status=overridden)
 *   reason          string            why, when the human gave one
 *   responseTimeMs  int               how long the model took
 *   externalRef     string            consumer's own reference, for idempotency
 *
 * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md
 */
class AiOversightRecordedEvent extends Event {

    /**
     * The id of the Approval hermiq wrote, or null when nothing handled it.
     *
     * @var string|null
     */
    private ?string $approvalId = null;

    /**
     * Whether hermiq's listener recorded this decision.
     *
     * A consumer reads this after dispatch to tell "hermiq recorded it" from
     * "hermiq is not installed" — the two look identical on the bus, and an
     * audit trail that silently records nothing is worse than none.
     *
     * @var boolean
     */
    private bool $handled = false;


    /**
     * Construct the event.
     *
     * @param array<string, mixed> $record The decision record; see the class docblock for the key set.
     *
     * @return void
     */
    public function __construct(
        private readonly array $record,
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * Get the decision record.
     *
     * @return array<string, mixed> The record as the consumer supplied it.
     *
     * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md#requirement-hermiq-records-advisory-human-oversight-decisions-from-consumer-apps
     */
    public function getRecord(): array {
        return $this->record;

    }//end getRecord()


    /**
     * Get the id of the Approval hermiq wrote.
     *
     * @return string|null The Approval id, or null when unhandled.
     *
     * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md#requirement-hermiq-records-advisory-human-oversight-decisions-from-consumer-apps
     */
    public function getApprovalId(): ?string {
        return $this->approvalId;

    }//end getApprovalId()


    /**
     * Record the Approval hermiq wrote. Called by hermiq's listener only.
     *
     * @param string $approvalId The written Approval's id.
     *
     * @return void
     *
     * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md#requirement-hermiq-records-advisory-human-oversight-decisions-from-consumer-apps
     */
    public function setApprovalId(string $approvalId): void {
        $this->approvalId = $approvalId;
        $this->handled    = true;

    }//end setApprovalId()


    /**
     * Whether hermiq recorded this decision.
     *
     * @return boolean True when hermiq's listener wrote an Approval.
     *
     * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md#requirement-hermiq-records-advisory-human-oversight-decisions-from-consumer-apps
     */
    public function isHandled(): bool {
        return $this->handled;

    }//end isHandled()


}//end class
