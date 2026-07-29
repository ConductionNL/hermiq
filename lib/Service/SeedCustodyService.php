<?php

/**
 * Hermiq SeedCustodyService.
 *
 * Decides whether a caller may act as the OWNER of a skill object for the
 * owner-guarded skill endpoints (qualify, version history/diff/rollback,
 * manual propose). Two cases pass:
 *
 * - the caller IS the stored owner (the plain IDOR rule, ADR-005 Rule 3); or
 * - the object is system-seeded (owner `__system__`) and the caller is an
 *   instance admin. Seeded example skills are installed by a repair step with
 *   no human owner, so WITHOUT this custodianship rule no user could EVER
 *   qualify, review or roll back a seeded skill — the seeds would ship with
 *   permanently dead owner-guarded surfaces. This mirrors the deliberately
 *   scoped admin-oversight bypass in `ToolOversightController::canAccessAgent()`
 *   (system-owned seeded agents that no human owns and OpenRegister lets
 *   admins read anyway).
 *
 * The rule never widens access between two human users: a non-owner,
 * non-admin caller still fails, and a HUMAN-owned skill is never opened to
 * admins here (owner mismatch stays a mismatch).
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCP\IGroupManager;

/**
 * Owner-or-seed-custodian check shared by the owner-guarded skill endpoints.
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */
class SeedCustodyService
{

    /**
     * The owner uid OpenRegister repair-step imports stamp on seeded objects.
     *
     * @var string
     */
    public const SYSTEM_OWNER = '__system__';

    /**
     * Constructor.
     *
     * @param IGroupManager $groupManager Instance-admin check for the seed-custodian case.
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Whether $uid may act as the owner of an object whose stored owner is $owner.
     *
     * True when $uid IS the owner, or when the object is system-seeded
     * (`__system__`) and $uid is an instance admin (seed custodianship — see the
     * class docblock). Everything else is a mismatch and MUST 404 upstream
     * (never 403) so existence is not confirmed to a non-owner.
     *
     * @param string|null $owner The object's stored owner uid (null-safe).
     * @param string      $uid   The requesting user's uid.
     *
     * @return bool True when the caller may act as the owner.
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
     */
    public function actsAsOwner(?string $owner, string $uid): bool
    {
        $stored = (string) ($owner ?? '');

        if ($stored === $uid && $uid !== '') {
            return true;
        }

        if ($stored === self::SYSTEM_OWNER) {
            return $this->groupManager->isAdmin($uid);
        }

        return false;

    }//end actsAsOwner()
}//end class
