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
    ];

    /**
     * Keys without which the record is evidence of nothing.
     *
     * @var string[]
     */
    private const REQUIRED = ['originApp', 'subjectType', 'subjectId', 'humanAction'];


    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object service.
     * @param LoggerInterface $logger        The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
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
            return null;
        }

        $action = (string) $record['humanAction'];
        if (isset(self::ACTION_STATUS[$action]) === false) {
            $this->logger->warning(
                'AI oversight: refusing advisory record, unknown humanAction',
                ['humanAction' => $action, 'allowed' => array_keys(self::ACTION_STATUS)]
            );
            return null;
        }

        $decidedAt = (string) ($record['decidedAt'] ?? '');
        if ($decidedAt === '') {
            $decidedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        }

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

        $object = [
            'status'      => self::ACTION_STATUS[$action],
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
            ],
        ];

        try {
            $saved = $this->objectService->saveObject(
                object: $object,
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
        }

        return $saved->getUuid();

    }//end record()


}//end class
