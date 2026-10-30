<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Records advisory human-oversight decisions as terminal Approvals.
 *
 * WHY NOT A METHOD ON ApprovalService. That class is the GATE: it resolves
 * reviewers, delivers notifications, drives pending -> approved/denied and
 * resumes whatever was blocked. An advisory record does none of that — nothing
 * was blocked, no reviewer was asked, and the decision already happened. Adding
 * it there would mean guarding half of a 1700-line service with "unless
 * advisory", which makes the gate harder to read for a variant that shares only
 * its storage.
 *
 * They share the SCHEMA on purpose (Approval.sourceType=advisory) so the Art. 14
 * audit trail stays in one place; they do not share the service, because they
 * are the same fact at different times and only one of them can say no.
 *
 * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md
 */
class AiOversightService {

    /**
     * OpenRegister register slug the Approval schema lives in.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for approvals.
     *
     * @var string
     */
    private const APPROVAL_SCHEMA = 'approval';

    /**
     * Human actions an advisory record may carry, mapped onto Approval.status.
     *
     * `overridden` is the branch a gate does not have: the human took the
     * suggestion but supplied a different value. Flattening it into `denied`
     * would erase the single most interesting fact in an oversight audit —
     * that a human corrected the model rather than ignoring it.
     *
     * @var array<string, string>
     */
    private const ACTION_STATUS = [
        'accepted'   => 'approved',
        'rejected'   => 'denied',
        'overridden' => 'overridden',
        'ignored'    => 'unused',
    ];

    /**
     * Why a suggestion that was shown went unused.
     *
     * `closed`: the person closed the surface it was shown on. `expired`: it
     * was left open past its lifetime. `superseded`: a newer suggestion
     * replaced it before anyone acted on it.
     *
     * @var string[]
     */
    private const UNUSED_BECAUSE = ['closed', 'expired', 'superseded'];

    /**
     * Largest page the subject listing returns.
     *
     * @var integer
     */
    private const MAX_LIMIT = 200;

    /**
     * Keys without which the record is evidence of nothing.
     *
     * @var string[]
     */
    private const REQUIRED = ['originApp', 'subjectType', 'subjectId', 'humanAction'];


    /**
     * Constructor.
     *
     * @param ObjectService    $objectService OpenRegister object service.
     * @param LoggerInterface  $logger        The logger.
     * @param OriginAppAliases $aliases       Every id an origin app has carried.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
        private readonly OriginAppAliases $aliases=new OriginAppAliases(),
    ) {

    }//end __construct()


    /**
     * Record one advisory decision.
     *
     * @param array<string, mixed> $record The decision record; see AiOversightRecordedEvent.
     *
     * @return string|null The written Approval's uuid, or null when the record was refused.
     *
     * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md
     */
    public function record(array $record): ?string {
        if ($this->accepts(record: $record) === false) {
            return null;
        }

        $decidedAt = (string) ($record['decidedAt'] ?? '');
        if ($decidedAt === '') {
            $decidedAt = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339);
        }

        try {
            $saved = $this->objectService->saveObject(
                object: $this->toApproval(record: $record, decidedAt: $decidedAt),
                register: self::REGISTER_SLUG,
                schema: self::APPROVAL_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            // Logged and swallowed BY CONTRACT: the origin app has already
            // completed the user's action, and failing its request after the
            // fact would turn an audit outage into a functional one. The
            // consumer sees isHandled() === false and can retry.
            $this->logger->error(
                'AI oversight: could not record advisory decision',
                ['error' => $e->getMessage(), 'originApp' => (string) $record['originApp']]
            );
            return null;
        }//end try

        return $saved->getUuid();

    }//end record()


    /**
     * List the advisory Approvals about one subject, newest decision first.
     *
     * Reads WITHOUT OpenRegister RBAC, because the schema keeps advisory rows
     * to the oversight role. The caller MUST have decided access first through
     * AiOversightAccess::maySeeSubject(); this method does no access check.
     * Every returned row is checked against the requested subject again, so a
     * filter OpenRegister ignored can never widen the answer. Records stored
     * under a former id of the origin app are included.
     *
     * @param string      $originApp   The origin app id.
     * @param string      $subjectId   The subject's id in that app.
     * @param string|null $subjectType The subject type, or null for any.
     * @param integer     $limit       Page size.
     * @param integer     $offset      Paging offset.
     *
     * @return array{results: array<int, array<string, mixed>>, total: integer}
     *
     * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
     */
    public function listForSubject(
        string $originApp,
        string $subjectId,
        ?string $subjectType=null,
        int $limit=25,
        int $offset=0
    ): array {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $origins = $this->aliases->idsOf(appId: $originApp);

        $results = [];
        $total   = 0;
        foreach ($origins as $origin) {
            $page = $this->searchSubject(
                origin: $origin,
                subjectId: $subjectId,
                subjectType: $subjectType,
                window: ($offset + $limit)
            );

            $kept = array_values(
                array_filter(
                    $page['rows'],
                    fn (array $data): bool => $this->isAbout(
                        data: $data,
                        origins: $origins,
                        subjectId: $subjectId,
                        subjectType: $subjectType
                    )
                )
            );

            $results = array_merge($results, $kept);
            $total  += count($kept);
            if (count($kept) === count($page['rows'])) {
                $total += max(0, ($page['total'] - count($kept)));
            }
        }//end foreach

        usort(
            $results,
            static fn (array $left, array $right): int => strcmp(
                (string) ($right['decidedAt'] ?? ''),
                (string) ($left['decidedAt'] ?? '')
            )
        );

        return ['results' => array_slice($results, $offset, $limit), 'total' => $total];

    }//end listForSubject()


    /**
     * Read the first rows about a subject under one origin app id, newest first.
     *
     * @param string      $origin      One origin app id.
     * @param string      $subjectId   The subject id.
     * @param string|null $subjectType The subject type, or null for any.
     * @param integer     $window      How many rows from the top to read.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: integer}
     */
    private function searchSubject(string $origin, string $subjectId, ?string $subjectType, int $window): array {
        $query = [
            'sourceType'                => 'advisory',
            'advisoryContext.originApp' => $origin,
            'advisoryContext.subjectId' => $subjectId,
            '_limit'                    => $window,
            '_offset'                   => 0,
            '_order'                    => ['decidedAt' => 'DESC'],
        ];
        if ($subjectType !== null && $subjectType !== '') {
            $query['advisoryContext.subjectType'] = $subjectType;
        }

        $page = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::APPROVAL_SCHEMA)
            ->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);

        $rows = [];
        foreach (($page['results'] ?? []) as $row) {
            $rows[] = $this->toArray(row: $row);
        }

        return ['rows' => $rows, 'total' => (int) ($page['total'] ?? count($rows))];

    }//end searchSubject()


    /**
     * Normalise one search row to an array.
     *
     * @param mixed $row An ObjectEntity or an already rendered array.
     *
     * @return array<string, mixed> The row's data.
     */
    private function toArray(mixed $row): array {
        if ($row instanceof ObjectEntity) {
            return $row->jsonSerialize();
        }

        if (is_array($row) === true) {
            return $row;
        }

        return [];

    }//end toArray()


    /**
     * Whether a row is an advisory Approval about exactly the requested subject.
     *
     * @param array<string, mixed> $data        The row.
     * @param string[]             $origins     Every id of the origin app.
     * @param string               $subjectId   The subject id.
     * @param string|null          $subjectType The subject type, or null for any.
     *
     * @return boolean True when the row belongs in the answer.
     */
    private function isAbout(array $data, array $origins, string $subjectId, ?string $subjectType): bool {
        $context = ($data['advisoryContext'] ?? null);
        if (($data['sourceType'] ?? null) !== 'advisory' || is_array($context) === false) {
            return false;
        }

        if (in_array(($context['originApp'] ?? null), $origins, true) === false || ($context['subjectId'] ?? null) !== $subjectId) {
            return false;
        }

        return $subjectType === null || $subjectType === '' || ($context['subjectType'] ?? null) === $subjectType;

    }//end isAbout()


    /**
     * Whether this record can be stored as evidence at all.
     *
     * Split out of record() so the write path reads as one statement and the
     * refusal rules can be read without it.
     *
     * @param array<string, mixed> $record The decision record.
     *
     * @return boolean True when the record may be written.
     */
    private function accepts(array $record): bool {
        $missing = [];
        foreach (self::REQUIRED as $key) {
            if (isset($record[$key]) === false || (string) $record[$key] === '') {
                $missing[] = $key;
            }
        }

        if (empty($missing) === false) {
            // Refused, not silently stored half-formed: an oversight entry that
            // cannot say WHICH decision it records is worse than no entry,
            // because it makes the trail look complete.
            $this->logger->warning(
                'AI oversight: refusing advisory record, missing required key(s)',
                ['missing' => $missing, 'originApp' => ($record['originApp'] ?? '?')]
            );
            return false;
        }

        $action = (string) $record['humanAction'];
        if (isset(self::ACTION_STATUS[$action]) === false) {
            $this->logger->warning(
                'AI oversight: refusing advisory record, unknown humanAction',
                ['humanAction' => $action, 'allowed' => array_keys(self::ACTION_STATUS)]
            );
            return false;
        }

        $because = (string) ($record['unusedBecause'] ?? '');
        if ($because !== '' && in_array($because, self::UNUSED_BECAUSE, true) === false) {
            $this->logger->warning(
                'AI oversight: refusing advisory record, unknown unusedBecause',
                ['unusedBecause' => $because, 'allowed' => self::UNUSED_BECAUSE]
            );
            return false;
        }

        return true;

    }//end accepts()


    /**
     * Build the advisory Approval object from an accepted record.
     *
     * @param array<string, mixed> $record    The decision record.
     * @param string               $decidedAt The resolved decision timestamp.
     *
     * @return array<string, mixed> The object to persist.
     */
    private function toApproval(array $record, string $decidedAt): array {
        // Both numbers stay NULL when the caller did not report them: a
        // confidence of 0.0 and "no confidence reported" are different facts,
        // and coercing the second into the first would invent certainty the
        // model never claimed.
        $confidence = null;
        if (isset($record['confidence']) === true) {
            $confidence = (float) $record['confidence'];
        }

        $responseTimeMs = null;
        if (isset($record['responseTimeMs']) === true) {
            $responseTimeMs = (int) $record['responseTimeMs'];
        }

        return [
            'status'      => self::ACTION_STATUS[(string) $record['humanAction']],
            'sourceType'  => 'advisory',
            // Both stamps carry the decision time. An advisory Approval is
            // terminal at creation — there is no interval between "asked" and
            // "decided", and leaving requestedAt empty would break every query
            // that orders the inbox by it.
            'requestedAt' => $decidedAt,
            'decidedAt'   => $decidedAt,
            'decidedBy'   => (string) ($record['userId'] ?? ''),
            'decidedVia'  => 'origin-app',
            'prompt'      => (string) ($record['prompt'] ?? ''),
            'reason'      => (string) ($record['reason'] ?? ''),
            'correlationId'   => (string) ($record['externalRef'] ?? ''),
            'advisoryContext' => [
                'originApp'      => (string) $record['originApp'],
                'suggestionType' => (string) ($record['suggestionType'] ?? ''),
                'action'         => (string) ($record['action'] ?? ''),
                'subjectType'    => (string) $record['subjectType'],
                'subjectId'      => (string) $record['subjectId'],
                'model'          => (string) ($record['model'] ?? ''),
                'suggestion'     => (string) ($record['suggestion'] ?? ''),
                'confidence'     => $confidence,
                'actualValue'    => (string) ($record['actualValue'] ?? ''),
                'responseTimeMs' => $responseTimeMs,
                'unusedBecause'  => (string) ($record['unusedBecause'] ?? ''),
            ],
        ];

    }//end toApproval()


}//end class
