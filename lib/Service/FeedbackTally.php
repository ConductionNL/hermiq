<?php

/**
 * Hermiq FeedbackTally
 *
 * The running count of one analytics read's answer ratings: the overall
 * helpful/unhelpful totals and each rated agent's own counts.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Counts ratings into the totals and the per-agent rows.
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
 */
final class FeedbackTally
{

    /**
     * The overall counts so far.
     *
     * @var array{positive: int, negative: int}
     */
    private array $feedback = ['positive' => 0, 'negative' => 0];

    /**
     * Start a tally.
     *
     * @param array<string, string>               $visibleAgents Map of visible agent UUID to name.
     * @param array<string, array<string, mixed>> $perAgent      The run totals per agent, extended as ratings arrive.
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
     */
    public function __construct(
        private readonly array $visibleAgents,
        private array $perAgent,
    ) {
    }//end __construct()

    /**
     * Count one feedback row; a row that is neither positive nor negative is skipped.
     *
     * @param array<string, mixed> $row The feedback payload.
     *
     * @return void
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
     */
    public function add(array $row): void
    {
        $ratedAgent = (string) ($row['agentId'] ?? '');
        $type       = (string) ($row['type'] ?? '');
        if ($type !== 'positive' && $type !== 'negative') {
            return;
        }

        $this->feedback[$type]++;
        $this->ensureAgent(ratedAgent: $ratedAgent);
        $this->perAgent[$ratedAgent][$type] = (($this->perAgent[$ratedAgent][$type] ?? 0) + 1);

    }//end add()

    /**
     * The totals with the helpful rate, and every agent row with both counts.
     *
     * @return array{feedback: array<string, int|float|null>, perAgent: array<string, array<string, mixed>>}
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
     */
    public function result(): array
    {
        $perAgent = $this->perAgent;
        foreach (array_keys($perAgent) as $key) {
            $perAgent[$key]['positive'] = ($perAgent[$key]['positive'] ?? 0);
            $perAgent[$key]['negative'] = ($perAgent[$key]['negative'] ?? 0);
        }

        $feedback = $this->feedback;
        $feedback['helpfulRate'] = $this->helpfulRate();

        return ['feedback' => $feedback, 'perAgent' => $perAgent];

    }//end result()

    /**
     * Start the row of an agent that has no run totals yet, named after the agent or its id.
     *
     * @param string $ratedAgent The rated agent's UUID.
     *
     * @return void
     */
    private function ensureAgent(string $ratedAgent): void
    {
        if (isset($this->perAgent[$ratedAgent]) === true) {
            return;
        }

        $label = $this->visibleAgents[$ratedAgent];
        if ($label === '') {
            $label = $ratedAgent;
        }

        $this->perAgent[$ratedAgent] = ['agentId' => $ratedAgent, 'name' => $label, 'runs' => 0, 'success' => 0];

    }//end ensureAgent()

    /**
     * The share of positive ratings, to four decimals, or null when nothing was rated.
     *
     * @return float|null
     */
    private function helpfulRate(): ?float
    {
        $rated = ($this->feedback['positive'] + $this->feedback['negative']);
        if ($rated === 0) {
            return null;
        }

        return round(($this->feedback['positive'] / $rated), 4);

    }//end helpfulRate()
}//end class
