<?php

/**
 * Hermiq SkillVersionController.
 *
 * The skill versioning + republish endpoints (skill-self-improvement):
 *
 * - `index()` / `diff()` / `rollback()` — AuditTrail-backed version history, a
 *   field-level diff over the versioned content plane (`frontmatter`/`body`/`files`),
 *   and rollback-as-a-new-version. All OWNER-guarded (agent-evals IDOR rule: a
 *   missing skill and a non-owner are indistinguishable — both 404, never 403).
 * - `republish()` — the one-click, NEVER-automatic republish to the skill's OWN
 *   provenance-stamped repository, behind the SAME `skill.publish-hub` action
 *   authorization the skills-marketplace publish requirement defines. The target
 *   coordinates are read from the skill's stamped `githubOwner`/`githubRepo` and
 *   never from the client, so republishing to any other repository is structurally
 *   impossible; broker unavailable → 503, no token fallback. The committed selection
 *   ships `learnings.md` but strips `learning-candidates.md`.
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GitHubTemplatePushServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillVersionServiceInterface;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Version history / diff / rollback / republish endpoints for skills.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator per seam
 *   (visibility, version store, publish path, action matrix, audit) — the
 *   controller only guards and delegates.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */
class SkillVersionController extends Controller {

	/**
	 * The publish action authorization republish REUSES (skills-marketplace:
	 * "republish MUST require the same publish action authorization as first
	 * publish").
	 *
	 * @var string
	 */
	private const PUBLISH_ACTION = 'skill.publish-hub';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param SkillServiceInterface $skillService Tenant-scoped skill reads + provenance stamp.
	 * @param SkillVersionServiceInterface $versionService AuditTrail-backed version store.
	 * @param GitHubTemplatePushServiceInterface $pushService Fail-closed broker-mediated GitHub push.
	 * @param ActionAuthServiceInterface $actionAuth ADR-023 action authorization.
	 * @param AuditTrailMapper $auditTrailMapper Rollback/republish audit entries.
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
		private readonly SkillVersionServiceInterface $versionService,
		private readonly GitHubTemplatePushServiceInterface $pushService,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly SeedCustodyServiceInterface $seedCustody,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The skill's version history, newest-first (owner-guarded).
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The versions, or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
	 */
	public function index(string $id): JSONResponse {
		$guarded = $this->loadOwnedSkillOr404(skillId: $id);
		if (($guarded instanceof JSONResponse) === true) {
			return $guarded;
		}

		try {
			return new JSONResponse(['results' => $this->versionService->listVersions(skillUuid: $id)]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill versions failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Versions failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end index()

	/**
	 * Field-level diff between two versions, limited to the versioned content plane
	 * (`frontmatter`/`body`/`files`) — a `state` or provenance change never appears.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The diff, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
	 */
	public function diff(string $id): JSONResponse {
		$guarded = $this->loadOwnedSkillOr404(skillId: $id);
		if (($guarded instanceof JSONResponse) === true) {
			return $guarded;
		}

		return $this->diffAnswer(skillId: $id);
	}//end diff()

	/**
	 * The diff between the `from` and `to` versions the request names.
	 *
	 * @param string $skillId The Skill UUID.
	 *
	 * @return JSONResponse The diff, or a 400/404/500.
	 */
	private function diffAnswer(string $skillId): JSONResponse {
		$from = (string)$this->request->getParam('from', '');
		$to = (string)$this->request->getParam('to', '');
		if ($from === '' || $to === '') {
			return new JSONResponse(['error' => 'Both from and to version ids are required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse(['diff' => $this->versionService->diff(skillUuid: $skillId, fromId: $from, toId: $to)]);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => 'Version not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill diff failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Diff failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end diffAnswer()

	/**
	 * Roll the skill back to a previous version's content — as a brand-NEW version
	 * (history never mutated; identity/lifecycle/provenance/maturity fields keep
	 * their CURRENT values). Always an explicit human action — the regression
	 * watch's suggestion is advisory only. Audited.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The new version id, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
	 */
	public function rollback(string $id): JSONResponse {
		$guarded = $this->loadOwnedSkillOr404(skillId: $id);
		if (($guarded instanceof JSONResponse) === true) {
			return $guarded;
		}

		return $this->rollbackTo(skillId: $id, versionId: (string)$this->request->getParam('versionId', ''));
	}//end rollback()

	/**
	 * Roll the skill back to `$versionId`, audit it and answer the new version id.
	 *
	 * @param string $skillId The Skill UUID.
	 * @param string $versionId The version to roll back to ('' when none was given).
	 *
	 * @return JSONResponse The new version id, or a 400/404/500.
	 */
	private function rollbackTo(string $skillId, string $versionId): JSONResponse {
		if ($versionId === '') {
			return new JSONResponse(['error' => 'A versionId is required'], Http::STATUS_BAD_REQUEST);
		}

		$updated = $this->rolledBack(skillId: $skillId, versionId: $versionId);
		if ($updated instanceof JSONResponse) {
			return $updated;
		}

		$newVersionId = (string)($this->versionService->currentVersionId(skillUuid: $skillId) ?? '');
		$this->writeAudit(
			skill: $updated,
			context: $this->rollbackContext(targetVersionId: $versionId, newVersionId: $newVersionId)
		);

		return new JSONResponse(
			[
				'status' => 'rolled-back',
				'versionId' => $newVersionId,
			]
		);
	}//end rollbackTo()

	/**
	 * Run the rollback, turning an unknown version into a 404 and any other failure into a 500.
	 *
	 * @param string $skillId The Skill UUID.
	 * @param string $versionId The version to roll back to.
	 *
	 * @return ObjectEntity|JSONResponse The updated skill, or the error response.
	 */
	private function rolledBack(string $skillId, string $versionId): ObjectEntity|JSONResponse {
		try {
			return $this->versionService->rollback(skillUuid: $skillId, versionId: $versionId);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => 'Version not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill rollback failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Rollback failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end rolledBack()

	/**
	 * The audit evidence of a rollback.
	 *
	 * @param string $targetVersionId The version rolled back to.
	 * @param string $newVersionId The version the rollback created.
	 *
	 * @return array<string, string> The audit context.
	 */
	private function rollbackContext(string $targetVersionId, string $newVersionId): array {
		return [
			'transition' => 'rollback',
			'targetVersionId' => $targetVersionId,
			'newVersionId' => $newVersionId,
		];
	}//end rollbackContext()

	/**
	 * One-click republish to the skill's OWN provenance repo (the exactly-one
	 * carve-out from "refuse to overwrite an existing repository"): target
	 * coordinates come from the skill's stamped `githubOwner`/`githubRepo` — never
	 * from the client — behind the SAME publish action authorization as first
	 * publish. NEVER automatic: reachable only through this explicit user action.
	 * Ships `learnings.md`, strips `learning-candidates.md`, restamps `publishedAt`
	 * on success (clearing the behind-badge). Audited.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The push result, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
	 */
	public function republish(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Visibility first (404 before the action check, matching template publish).
		$skill = $this->loadVisibleSkill(skillId: $id);
		if ($skill === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$run = new SkillRepublishRun(skillId: $id, skill: $skill);

		return $this->republishRefusal(user: $user, run: $run) ?? $this->pushRepublish(user: $user, run: $run);
	}//end republish()

	/**
	 * The first reason this republish may not go ahead, in the spec's order:
	 * the publish action, the provenance stamp, the credential, the broker.
	 *
	 * @param IUser $user The acting user.
	 * @param SkillRepublishRun $run The republish; its credential is read here.
	 *
	 * @return JSONResponse|null The refusal, or null when the republish may go ahead.
	 */
	private function republishRefusal(IUser $user, SkillRepublishRun $run): ?JSONResponse {
		return $this->publishForbidden(user: $user)
			?? $this->notPublishedRefusal(run: $run)
			?? $this->missingCredentialRefusal(run: $run)
			?? $this->brokerUnavailableRefusal();
	}//end republishRefusal()

	/**
	 * The 403 when the acting user may not run the publish action.
	 *
	 * @param IUser $user The acting user.
	 *
	 * @return JSONResponse|null The refusal, or null when the action is allowed.
	 */
	private function publishForbidden(IUser $user): ?JSONResponse {
		try {
			$this->actionAuth->requireAction(user: $user, action: self::PUBLISH_ACTION);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end publishForbidden()

	/**
	 * The 400 for a skill that was never published.
	 *
	 * Never published — there is nothing to republish and no carve-out: first
	 * publish goes through the normal refuse-existing publish path.
	 *
	 * @param SkillRepublishRun $run The republish.
	 *
	 * @return JSONResponse|null The refusal, or null when both provenance coordinates are stamped.
	 */
	private function notPublishedRefusal(SkillRepublishRun $run): ?JSONResponse {
		if ($run->isPublished() === false) {
			return new JSONResponse(['error' => 'not_published'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end notPublishedRefusal()

	/**
	 * Read the broker credential into the run; the 422 when there is none.
	 *
	 * @param SkillRepublishRun $run The republish.
	 *
	 * @return JSONResponse|null The refusal, or null when a credential was given.
	 */
	private function missingCredentialRefusal(SkillRepublishRun $run): ?JSONResponse {
		$run->credentialId = (string)($this->request->getParam('credentialId') ?? '');
		if ($run->credentialId === '') {
			return new JSONResponse(['error' => 'A broker credentialId is required to republish'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return null;
	}//end missingCredentialRefusal()

	/**
	 * The 503 when the GitHub credential broker is absent — fail closed, no
	 * token-bearing fallback exists.
	 *
	 * @return JSONResponse|null The refusal, or null when the broker is available.
	 */
	private function brokerUnavailableRefusal(): ?JSONResponse {
		if ($this->pushService->isBrokerAvailable() === false) {
			return new JSONResponse(['error' => 'The GitHub credential broker is not available'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return null;
	}//end brokerUnavailableRefusal()

	/**
	 * Export the skill, push it to its provenance repo, then restamp and audit.
	 *
	 * @param IUser $user The acting user.
	 * @param SkillRepublishRun $run The republish.
	 *
	 * @return JSONResponse The push result, or an error status.
	 */
	private function pushRepublish(IUser $user, SkillRepublishRun $run): JSONResponse {
		$package = $this->skillService->exportSkill(skillId: $run->skillId);
		if ($package === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$run->package = $package;
		$result = $this->pushedOrRefused(user: $user, run: $run);
		if ($result instanceof JSONResponse) {
			return $result;
		}

		$this->recordRepublish(run: $run, result: $result);

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end pushRepublish()

	/**
	 * Push the package, turning a refused push into a 422 and any other failure into a 500.
	 *
	 * @param IUser $user The acting user.
	 * @param SkillRepublishRun $run The republish, with its credential and package.
	 *
	 * @return array<string, mixed>|JSONResponse The push result, or the error response.
	 */
	private function pushedOrRefused(IUser $user, SkillRepublishRun $run): array|JSONResponse {
		try {
			return $this->pushPackage(user: $user, run: $run);
		} catch (RuntimeException $e) {
			$this->logger->warning('Hermiq skill republish refused: ' . $e->getMessage());
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill republish failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Republish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end pushedOrRefused()

	/**
	 * Push the exported package to the skill's provenance repo, with the stripped file selection.
	 *
	 * @param IUser $user The acting user.
	 * @param SkillRepublishRun $run The republish, with its credential and package.
	 *
	 * @return array<string, mixed> The push result.
	 */
	private function pushPackage(IUser $user, SkillRepublishRun $run): array {
		return $this->pushService->pushUpdate(
			package: $run->package,
			owner: $run->owner(),
			repo: $run->repo(),
			credentialId: $run->credentialId,
			actingUserId: $user->getUID(),
			kind: GitHubTemplatePushServiceInterface::KIND_SKILL,
			auxFiles: ($this->skillService->publishFileSelection(skillId: $run->skillId) ?? [])
		);
	}//end pushPackage()

	/**
	 * Restamp publishedAt — this clears the behind-badge client-side — and audit the republish.
	 *
	 * @param SkillRepublishRun $run The republish.
	 * @param array<string, mixed> $result The push result.
	 *
	 * @return void
	 */
	private function recordRepublish(SkillRepublishRun $run, array $result): void {
		$this->restampPublishedAt(run: $run);

		$this->writeAudit(
			skill: $run->skill,
			context: [
				'transition' => 'republish',
				'owner' => $run->owner(),
				'repo' => $run->repo(),
				'commitSha' => $result['commitSha'],
			]
		);
	}//end recordRepublish()

	/**
	 * Restamp the skill's provenance with the current time as `publishedAt`.
	 *
	 * @param SkillRepublishRun $run The republish.
	 *
	 * @return void
	 */
	private function restampPublishedAt(SkillRepublishRun $run): void {
		$this->skillService->stampGithubPublish(
			skillId: $run->skillId,
			owner: $run->owner(),
			repo: $run->repo(),
			publishedAt: (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')
		);
	}//end restampPublishedAt()

	/**
	 * Owner guard shared by the version endpoints: the caller must OWN the skill; a
	 * missing skill and a non-owner both 404 (never 403), mirroring
	 * `SkillMaturityController::loadOwnedSkill()`. An instance admin acts as
	 * custodian-owner of system-seeded skills (owner `__system__`; see
	 * `SeedCustodyService`).
	 *
	 * @param string $skillId The Skill UUID.
	 *
	 * @return JSONResponse|ObjectEntity The 401/404 response, or the owned skill.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
	 */
	private function loadOwnedSkillOr404(string $skillId): JSONResponse|ObjectEntity {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$skill = $this->loadVisibleSkill(skillId: $skillId);
		if ($skill === null || $this->seedCustody->actsAsOwner(owner: $skill->getOwner(), uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Skill not found'], Http::STATUS_NOT_FOUND);
		}

		return $skill;
	}//end loadOwnedSkillOr404()

	/**
	 * Load the skill within the caller's RBAC visibility.
	 *
	 * @param string $skillId The Skill UUID.
	 *
	 * @return ObjectEntity|null The visible skill, or null when absent/invisible.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
	 */
	private function loadVisibleSkill(string $skillId): ?ObjectEntity {
		try {
			return $this->skillService->getSkill(skillId: $skillId);
		} catch (Throwable $e) {
			return null;
		}

	}//end loadVisibleSkill()

	/**
	 * Write the rollback/republish AuditTrail entry (run-audit-log seam) — never
	 * fatal to the operation it records.
	 *
	 * @param ObjectEntity $skill The skill the operation acted on.
	 * @param array<string, mixed> $context The operation evidence.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-state-transition-is-audited
	 */
	private function writeAudit(ObjectEntity $skill, array $context): void {
		try {
			$context['at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
			$this->auditTrailMapper->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $skill,
					action: 'skill-version',
					context: $context
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not write skill-version audit for '
				. ((string)$skill->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end writeAudit()
}//end class
