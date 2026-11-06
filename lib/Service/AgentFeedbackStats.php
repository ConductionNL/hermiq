<?php

/**
 * Hermiq AgentFeedbackStats.
 *
 * The thumbs-up and thumbs-down ratings per agent, on the run analytics' tenant
 * boundary: a rating counts only when its agent is in the caller's visible agent set,
 * which AnalyticsService resolves and hands in. Split out of AnalyticsService so that
 * class stays the run read; this one is the feedback read.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Counts and lists the ratings on the agents a caller may see.
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
 */
class AgentFeedbackStats
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Feedback schema slug (thumbs up or down on one answer).
     *
     * @var string
     */
    private const FEEDBACK_SCHEMA = 'feedback';

    /**
     * Page size for the feedback scan (paged to exhaustion, never a cap).
     *
     * @var integer
     */
    private const PAGE_SIZE = 500;

    /**
     * How many low ratings the agent page lists.
     *
     * @var integer
     */
    private const LOW_RATINGS_LIMIT = 10;

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OpenRegister object read (multitenancy on).
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Count the ratings and add them to the per-agent rows.
     *
     * @param array<string, string>               $visibleAgents Map of visible agent UUID to name.
     * @param string|null                         $agentId       Optional agent UUID to narrow to.
     * @param array<string, array<string, mixed>> $perAgent      The per-agent run rows, keyed by agent UUID.
     *
     * @return array{feedback: array{positive: int, negative: int, helpfulRate: float|null}, perAgent: array<string, array<string, mixed>>}
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
     */
    public function tally(array $visibleAgents, ?string $agentId, array $perAgent): array
    {
        $feedback = ['positive' => 0, 'negative' => 0];
        foreach ($this->loadFeedback(visibleAgents: $visibleAgents, agentId: $agentId) as $row) {
            $ratedAgent = (string) ($row['agentId'] ?? '');
            $type       = (string) ($row['type'] ?? '');
            if ($type !== 'positive' && $type !== 'negative') {
                continue;
            }

            $feedback[$type]++;
            if (isset($perAgent[$ratedAgent]) === false) {
                $label = $visibleAgents[$ratedAgent];
                if ($label === '') {
                    $label = $ratedAgent;
                }

                $perAgent[$ratedAgent] = ['agentId' => $ratedAgent, 'name' => $label, 'runs' => 0, 'success' => 0];
            }

            $perAgent[$ratedAgent][$type] = (($perAgent[$ratedAgent][$type] ?? 0) + 1);
        }

        foreach (array_keys($perAgent) as $key) {
            $perAgent[$key]['positive'] = ($perAgent[$key]['positive'] ?? 0);
            $perAgent[$key]['negative'] = ($perAgent[$key]['negative'] ?? 0);
        }

        $rated = ($feedback['positive'] + $feedback['negative']);
        $feedback['helpfulRate'] = null;
        if ($rated > 0) {
            $feedback['helpfulRate'] = round(($feedback['positive'] / $rated), 4);
        }

        return ['feedback' => $feedback, 'perAgent' => $perAgent];

    }//end tally()

    /**
     * The latest thumbs-down ratings with a comment on one agent, newest first.
     *
     * The caller checks read access on the agent first. The rater's name is left
     * out: the rating is about the answer, not the person.
     *
     * @param string $agentId The agent UUID.
     *
     * @return array<int, array{comment: string, date: string|null, conversationId: string}> At most ten rows.
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-reads-the-latest-low-ratings-req-fbstat-002
     */
    public function latestLowRatings(string $agentId): array
    {
        $rows = [];
        foreach ($this->loadFeedback(visibleAgents: [$agentId => ''], agentId: $agentId) as $row) {
            $comment = trim((string) ($row['comment'] ?? ''));
            if (($row['type'] ?? '') !== 'negative' || $comment === '') {
                continue;
            }

            $rows[] = [
                'comment'        => $comment,
                'date'           => $row['@date'],
                'conversationId' => (string) ($row['conversationId'] ?? ''),
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                return strcmp((string) $b['date'], (string) $a['date']);
            }
        );

        return array_slice($rows, 0, self::LOW_RATINGS_LIMIT);

    }//end latestLowRatings()

    /**
     * Feedback rows on the visible agents, paged to exhaustion.
     *
     * Read through ObjectService with multitenancy on, like the agent map, then
     * narrowed to the visible agents so a rating on an agent the caller may not
     * see is never counted.
     *
     * @param array<string, string> $visibleAgents Map of visible agent UUID to name.
     * @param string|null           $agentId       Optional agent UUID to narrow to.
     *
     * @return array<int, array<string, mixed>> The feedback payloads, each with `@date` (created, ISO 8601 or null).
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001
     */
    private function loadFeedback(array $visibleAgents, ?string $agentId): array
    {
        if ($visibleAgents === []) {
            return [];
        }

        $scoped = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::FEEDBACK_SCHEMA);

        $query = ['_limit' => self::PAGE_SIZE];
        if ($agentId !== null && $agentId !== '') {
            $query['agentId'] = $agentId;
        }

        $rows   = [];
        $offset = 0;
        do {
            $query['_offset'] = $offset;
            $result           = $scoped->searchObjectsPaginated(query: $query);
            $page             = ($result['results'] ?? []);
            foreach ($page as $object) {
                $row = $this->visibleRow(object: $object, visibleAgents: $visibleAgents);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            $offset += self::PAGE_SIZE;
            $total   = (int) ($result['total'] ?? 0);
        } while ($page !== [] && $offset < $total);

        return $rows;

    }//end loadFeedback()

    /**
     * One feedback object as a row, or null when it is not on a visible agent.
     *
     * @param mixed                 $object        A search result entry.
     * @param array<string, string> $visibleAgents Map of visible agent UUID to name.
     *
     * @return array<string, mixed>|null The payload with `@date`, or null.
     */
    private function visibleRow(mixed $object, array $visibleAgents): ?array
    {
        if (($object instanceof ObjectEntity) === false) {
            return null;
        }

        $data = $object->getObject();
        if (isset($visibleAgents[(string) ($data['agentId'] ?? '')]) === false) {
            return null;
        }

        $created       = $object->getCreated();
        $data['@date'] = null;
        if ($created !== null) {
            $data['@date'] = $created->format('c');
        }

        return $data;

    }//end visibleRow()
}//end class
