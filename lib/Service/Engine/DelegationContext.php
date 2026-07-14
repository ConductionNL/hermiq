<?php

/**
 * Hermiq DelegationContext (sub-agent-delegation).
 *
 * A plain, request-scoped delegation call-stack — the ONE piece of trusted
 * server-side state `DelegationService::delegate()` reads to decide depth,
 * fan-out, ancestry and the shared budget anchor for a `hermiq.delegateAgent`
 * call. Nextcloud's DI container hands out one shared instance of an
 * autowired class per HTTP/cron request (the same request-scope sharing
 * `IUserSession`/`ISession` already rely on), so `ScheduleService` (which
 * pushes/pops a frame around every `Engine::processMessage()` call, delegated
 * or not) and `DelegationService` (which reads `current()` mid-turn) see the
 * SAME stack without any explicit method-parameter threading — no special DI
 * registration needed.
 *
 * Deliberately never reads/writes any LLM tool-call argument: `depth()`,
 * `ancestorAgentIds()` and `fanOutCount()` are derived ENTIRELY from prior
 * `push()`/`incrementFanOut()` calls made by ScheduleService/DelegationService
 * themselves — the design.md "Decision 3" invariant that a delegating agent's
 * own tool-call arguments can never inflate/shrink its position in the tree.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One stack frame: the identity and position of one currently-running agent
 * turn in the delegation tree. Every field is fixed at push() time except
 * `$fanOutCount`, which the OWNING turn increments each time it successfully
 * makes a further `hermiq.delegateAgent` call.
 *
 * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
 */
final class DelegationFrame
{

    /**
     * Number of delegate calls this frame's OWN turn has successfully made so
     * far (a refused call never increments this — see `DelegationContext`).
     *
     * @var integer
     */
    public int $fanOutCount = 0;

    /**
     * Constructor.
     *
     * @param string            $runId            This run's own fresh run identifier.
     * @param string            $agentId          The Agent UUID running this turn.
     * @param string            $organisation     This run's organisation (resolved from the
     *                                            Agent entity).
     * @param ObjectEntity|null $anchor           The top-level trigger object
     *                                            (Schedule/flow/webhook subject) every
     *                                            delegated run in this tree anchors its own
     *                                            `AuditTrail` entry to, so `BudgetService`'s
     *                                            existing aggregation counts the whole tree
     *                                            against the SAME budget. Null when the
     *                                            top-level caller passed none (e.g. a
     *                                            dry-run preview).
     * @param string|null       $parentRunId      The calling run's own `runId`, or null for a
     *                                            top-level (non-delegated) run.
     * @param array<int,string> $ancestorAgentIds Every agent id strictly ABOVE this frame in
     *                                            the delegation chain, oldest first (never
     *                                            includes this frame's own `agentId`).
     * @param int               $depth            This frame's 1-based depth (1 = a top-level
     *                                            run; 2 = a first-level delegation; …).
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly string $organisation,
        public readonly ?ObjectEntity $anchor,
        public readonly ?string $parentRunId,
        public readonly array $ancestorAgentIds,
        public readonly int $depth,
    ) {
    }//end __construct()
}//end class

/**
 * DelegationContext
 *
 * Request-scoped delegation call-stack. Pure-PHP value holder — no DI-visible
 * I/O of its own, mirroring `RunTraceCollector`'s "plain value object" shape,
 * but shared (not explicitly threaded) precisely because two DIFFERENT
 * services (`ScheduleService`, `DelegationService`) both need to see the
 * SAME current frame.
 *
 * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
 */
class DelegationContext
{

    /**
     * The call stack, oldest (top-level) frame first.
     *
     * @var array<int, DelegationFrame>
     */
    private array $stack = [];

    /**
     * Push a new frame for a turn about to start (`ScheduleService::runAgentViaEngine()`,
     * for EVERY Engine-path run — top-level or delegated). Depth, ancestry and
     * the parent run id are all DERIVED from the current frame (if any), never
     * supplied by the caller.
     *
     * @param string            $runId        A fresh run identifier for this turn.
     * @param string            $agentId      The Agent UUID running this turn.
     * @param string            $organisation This run's organisation.
     * @param ObjectEntity|null $anchor       The budget-rollup anchor object, passed down
     *                                        verbatim from the caller (`runAgentAsOwner()`'s
     *                                        own `$anchor` parameter) — never re-derived here.
     *
     * @return DelegationFrame The newly-pushed frame.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
     */
    public function push(string $runId, string $agentId, string $organisation, ?ObjectEntity $anchor): DelegationFrame
    {
        $previous = $this->current();

        $ancestorAgentIds = [];
        $depth            = 1;
        $parentRunId      = null;
        if ($previous !== null) {
            $ancestorAgentIds = array_merge($previous->ancestorAgentIds, [$previous->agentId]);
            $depth            = ($previous->depth + 1);
            $parentRunId      = $previous->runId;
        }

        $frame = new DelegationFrame(
            runId: $runId,
            agentId: $agentId,
            organisation: $organisation,
            anchor: $anchor,
            parentRunId: $parentRunId,
            ancestorAgentIds: $ancestorAgentIds,
            depth: $depth
        );

        $this->stack[] = $frame;

        return $frame;

    }//end push()

    /**
     * Pop the current frame — MUST be called in a `finally` around the turn
     * `push()` was called for, so a thrown exception never leaves a stale
     * frame behind for a later, unrelated run in the same process.
     *
     * @return void
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
     */
    public function pop(): void
    {
        array_pop($this->stack);

    }//end pop()

    /**
     * The current (innermost, still-running) frame, or null when no turn is
     * in progress.
     *
     * @return DelegationFrame|null
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
     */
    public function current(): ?DelegationFrame
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[(count($this->stack) - 1)];

    }//end current()

    /**
     * The current frame's depth, or 0 when no turn is in progress.
     *
     * @return int
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
     */
    public function depth(): int
    {
        return ($this->current()?->depth ?? 0);

    }//end depth()

    /**
     * The current frame's ancestor agent ids, or an empty array when no turn
     * is in progress.
     *
     * @return array<int,string>
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
     */
    public function ancestorAgentIds(): array
    {
        return ($this->current()?->ancestorAgentIds ?? []);

    }//end ancestorAgentIds()

    /**
     * Increment the current frame's fan-out counter — called ONLY once a
     * delegate call has passed every gate and is about to actually invoke the
     * target (a refused call never reaches this, so it never counts).
     *
     * @return void
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
     */
    public function incrementFanOut(): void
    {
        $frame = $this->current();
        if ($frame === null) {
            return;
        }

        $frame->fanOutCount++;

    }//end incrementFanOut()

    /**
     * The current frame's fan-out count, or 0 when no turn is in progress.
     *
     * @return int
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
     */
    public function fanOutCount(): int
    {
        return ($this->current()?->fanOutCount ?? 0);

    }//end fanOutCount()
}//end class
