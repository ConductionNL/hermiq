<?php

/**
 * Hermiq SkillMaturityController.
 *
 * The two skill-maturity write actions (skill-maturity, ADR-068):
 *
 * - `qualify()` — owner-guarded recompute of a skill's L1–L7 maturity + scorecard. The
 *   guard copies the agent-evals IDOR rule (ADR-005 Rule 3 / OWASP A01): the caller must
 *   OWN the skill; a missing skill and a non-owner are indistinguishable — both 404,
 *   never 403, so existence is never confirmed to a non-owner.
 * - `attestL4()` — the ONLY path that stamps the human L4 attestation, gated by
 *   `ActionAuthService::requireAction('skill.attest-maturity')` (ADR-023 — attestation
 *   is a curator act, not an owner right, mirroring `skill.approve-quarantined`). An
 *   invisible skill 404s BEFORE the action check so the 403 never leaks existence; an
 *   unauthorized caller gets 403 with the skill unchanged.
 *
 * Both routes are standard-CSRF POSTs (no `@NoCSRFRequired` — design.md Security
 * Considerations); `@NoAdminRequired` opens them to authenticated users, the guards
 * above do the authorization.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillMaturityServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Qualify + attest-L4 endpoints for the skill maturity model.
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One injected collaborator per seam
 *   (skill service, maturity service, action auth, seed custody, user session,
 *   logger) plus the HTTP response/exception types every endpoint returns.
 */
class SkillMaturityController extends Controller {

	/**
	 * The ADR-023 action gating the human L4 attestation.
	 *
	 * @var string
	 */
	private const ATTEST_ACTION = 'skill.attest-maturity';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param SkillServiceInterface $skillService The tenant-scoped skill read path.
	 * @param SkillMaturityServiceInterface $maturityService Computes + persists maturity.
	 * @param ActionAuthServiceInterface $actionAuth ADR-023 action authorization (attest gate).
	 * @param SeedCustodyServiceInterface $seedCustody Owner-or-seed-custodian check.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator, not a logic-bearing argument list.
	 */
	public function __construct(
		IRequest $request,
		private readonly SkillServiceInterface $skillService,
		private readonly SkillMaturityServiceInterface $maturityService,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly SeedCustodyServiceInterface $seedCustody,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Qualify a skill: recompute its maturity level from content + evidence, persist
	 * `maturityLevel` + refreshed `levelEvidence.l1`–`l3`, and return the seven-level
	 * scorecard. Owner-guarded — allowed in EVERY lifecycle state (maturity ⊥
	 * lifecycle) and never touches `state`.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The scorecard payload, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
	 */
	public function qualify(string $id): JSONResponse {
		// 404 (not 403) so a non-owner cannot even confirm the skill exists.
		$skill = $this->skills()->ownedBy(user: $this->userSession->getUser(), skillId: $id);
		if ($skill instanceof JSONResponse) {
			return $skill;
		}

		try {
			$result = $this->maturityService->qualify(skill: $skill);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill qualify failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Qualify failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end qualify()

	/**
	 * Attest a skill's L4 (personalization) level — the curator act that stamps
	 * `levelEvidence.l4 = {attestedBy, attestedAt, note}` and recomputes. Gated by the
	 * `skill.attest-maturity` action (ADR-023); an invisible skill 404s BEFORE the
	 * action check; an unauthorized caller gets 403 with the skill unchanged.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The refreshed scorecard payload, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
	 */
	public function attestL4(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Visibility first: a skill outside the caller's RBAC scope must 404 before the
		// action check so a 403 never confirms its existence.
		$skill = $this->skills()->visible(skillId: $id);
		if ($skill === null) {
			return new JSONResponse(['error' => 'Skill not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ATTEST_ACTION);
		} catch (OCSForbiddenException $e) {
			// The skill is untouched — nothing was written before this gate.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return $this->attest(skill: $skill, attestedBy: $user->getUID());

	}//end attestL4()

	/**
	 * Stamp the L4 attestation with the request's note and answer the refreshed
	 * scorecard, or the 500 when it cannot be written.
	 *
	 * @param ObjectEntity $skill The visible skill.
	 * @param string $attestedBy The attesting curator.
	 *
	 * @return JSONResponse
	 */
	private function attest(ObjectEntity $skill, string $attestedBy): JSONResponse {
		$note = trim((string)$this->request->getParam('note', ''));

		try {
			$result = $this->maturityService->attestL4(
				skill: $skill,
				attestedBy: $attestedBy,
				note: $note
			);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill attest-l4 failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Attest failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end attest()

	/**
	 * The visibility and ownership guard over this controller's skill reads.
	 *
	 * @return SkillOwnership
	 */
	private function skills(): SkillOwnership {
		return new SkillOwnership(skillService: $this->skillService, seedCustody: $this->seedCustody);
	}//end skills()
}//end class
