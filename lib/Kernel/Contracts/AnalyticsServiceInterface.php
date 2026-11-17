<?php

/**
 * Contract of AnalyticsService
 *
 * The public surface of AnalyticsService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of AnalyticsService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AnalyticsServiceInterface {

	/**
	 * Compare two runs the caller may see, on the run list's own boundary.
	 *
	 * The visible agent set is the one `listRuns()` filters on; RunCompareService
	 * answers a run outside it exactly like a run that does not exist.
	 *
	 * @param string $leftId The left run's audit entry uuid.
	 * @param string $rightId The right run's audit entry uuid.
	 *
	 * @return array{left: ?array, right: ?array, sameAgent: bool, comparison: ?array}
	 *
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
	 */
	public function compareRuns(string $leftId, string $rightId): array;

	/**
	 * Compute run analytics for the caller's tenant, optionally scoped to one agent.
	 *
	 * @param string|null $agentId Optional agent UUID to scope the metrics to.
	 *
	 * @return array<string, mixed> The metrics payload.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-analytics/tasks.md#task-1-3
	 */
	public function computeAnalytics(?string $agentId = null): array;

	/**
	 * The latest thumbs-down ratings with a comment on one agent, newest first.
	 *
	 * The caller checks read access on the agent first; see AgentFeedbackStats.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return array<int, array{comment: string, date: string|null, conversationId: string}> At most ten rows.
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-reads-the-latest-low-ratings-req-fbstat-002
	 */
	public function latestLowRatings(string $agentId): array;

	/**
	 * List the caller's runs across every agent, newest first.
	 *
	 * 🔴 THIS IS THE ONLY CROSS-AGENT RUN LIST. `RunHistoryController::index()` reads
	 * `object_uuid = <schedule>`, so it can answer "what did THIS schedule do" and
	 * nothing else. Two consequences followed from that being the only listing:
	 *
	 *   1. An operator whose success rate dropped overnight could see the number move
	 *      on the dashboard and could not open the runs behind it. Scheduling is the
	 *      app's headline promise, and the failures it produces had no surface.
	 *   2. A flow-triggered run was invisible to EVERY list. `agent-run` entries hang
	 *      on the flow's triggering object, which routinely lives in another register,
	 *      so no schedule's `object_uuid` ever matches one. The dashboard KPIs counted
	 *      them (they read this service) while every list denied they existed.
	 *
	 * The tenant boundary is `loadVisibleAgents()`, exactly as `computeAnalytics()`
	 * uses it, and for the same reason: the run's AGENT decides whether a run is the
	 * caller's to see, because `object_uuid` cannot serve as the scope key for both
	 * run channels. Sharing the boundary rather than writing a second one is the
	 * point — a list and a metric that disagree about what the caller may see is a
	 * worse bug than either being wrong alone.
	 *
	 * Dry-runs and replays are excluded on the same rule `computeAnalytics()` applies,
	 * so this list and the KPIs above it count the same set.
	 *
	 * @param string|null $agentId Optional agent UUID to scope the list to.
	 * @param string|null $status Optional status to filter on (exact match).
	 * @param int $limit Max rows to return.
	 * @param int $offset Rows to skip.
	 *
	 * @return array<string, mixed> The page of run rows plus the unpaged total.
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
	 */
	public function listRuns(
		?string $agentId = null,
		?string $status = null,
		int $limit = 50,
		int $offset = 0,
	): array;
}//end interface
