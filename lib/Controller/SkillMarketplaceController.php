<?php

/**
 * Hermiq SkillMarketplaceController.
 *
 * The marketplace surface (skills-marketplace): install a skill from an external source
 * (into quarantine), approve a quarantined skill (the review gate), and publish a skill to
 * an external hub via OpenConnector. All reads/writes run in the caller's session context
 * through SkillMarketplaceService → OpenRegister ObjectService, so OR's native RBAC denies
 * cross-tenant access.
 *
 * Security (ADR-005 Rule 3 / OWASP A01): `@NoAdminRequired` opens the routes to any
 * authenticated user, so the two privileged mutations are NOT open to every caller — each
 * gates on ActionAuthService::requireAction() (ADR-023), mirroring AiFeatureController.
 * `approve()` requires `skill.approve-quarantined`; when the caller passes `force=true` it
 * additionally requires the stricter `skill.override-scan-verdict` (a caller who can approve
 * a clean scan cannot necessarily override a dangerous one). `publish()` requires
 * `skill.publish-hub`. All three actions seed to admin-only; an admin may broaden them via
 * the action matrix. A refused caller gets 403; an unauthenticated caller 401.
 * `installFromSource()` only ever produces `quarantined` output (never `active`), so it
 * remains open to any authenticated tenant member.
 *
 * hermiq-github-store adds `githubPublish()` on this SAME controller — the new PRIMARY
 * publish path (a `topic:hermiq-skill` GitHub repo in agentskills.io format via the
 * generalised `GitHubTemplatePushService`), gated by the SAME `skill.publish-hub` action as
 * the existing OpenConnector `publish()` (both are "publish this skill externally"
 * mutations — design.md does not introduce a distinct action for the GitHub path).
 * Tenant-scoped via `SkillService::exportSkill()` (404, never 403, for an out-of-visibility
 * skill) exactly as `AgentTemplateController::publishGithub()` scopes template publish. On
 * success, stamps `githubOwner`/`githubRepo`/`publishedAt` via
 * `SkillService::stampGithubPublish()` — provenance only, never round-tripped through the
 * exported package.
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
 * @spec openspec/changes/archive/2026-07-12-fix-skill-marketplace-action-auth/tasks.md#2-controller
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GitHubTemplatePushServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillMarketplaceServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
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
 * Tenant-scoped skills-marketplace endpoints (install-from-source / approve / publish).
 *
 * @spec openspec/changes/archive/2026-07-12-fix-skill-marketplace-action-auth/tasks.md#2-controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One injected collaborator per seam
 *   (marketplace service, action auth, skill service, GitHub push, user session,
 *   logger) plus the HTTP response/exception types every endpoint returns.
 */
class SkillMarketplaceController extends Controller {
	/**
	 * Safe GitHub owner/repo pattern (hermiq-github-store), validated before any
	 * path interpolation on the publish endpoint — mirrors
	 * `AgentTemplateController::OWNER_REPO_PATTERN` exactly.
	 *
	 * @var string
	 */
	private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param SkillMarketplaceServiceInterface $marketplaceService The marketplace service.
	 * @param ActionAuthServiceInterface $actionAuth The ADR-023 action-authorization service.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param SkillServiceInterface $skillService Export + provenance stamp (hermiq-github-store).
	 * @param GitHubTemplatePushServiceInterface $pushService GitHub publish (hermiq-github-store).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator, not a logic-bearing argument list.
	 *
	 * @spec openspec/changes/archive/2026-07-12-fix-skill-marketplace-action-auth/tasks.md#2-controller
	 */
	public function __construct(
		IRequest $request,
		private readonly SkillMarketplaceServiceInterface $marketplaceService,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly SkillServiceInterface $skillService,
		private readonly GitHubTemplatePushServiceInterface $pushService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Install a skill from an external source into quarantine.
	 *
	 * @return JSONResponse The quarantined skill, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-marketplace/tasks.md#4-controller-routes
	 */
	public function installFromSource(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$package = (string)$this->request->getParam('package', '');
		$source = (string)$this->request->getParam('source', 'hub');
		if (trim($package) === '') {
			return new JSONResponse(['error' => 'A non-empty package is required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->installWithFiles(user: $user, run: new SkillInstallRun(package: $package, source: $source));
	}//end installFromSource()

	/**
	 * Read the auxiliary files, then install.
	 *
	 * Skill-package-multifile: auxiliary files are OPTIONAL — omitting them reproduces
	 * the pre-change contract byte-for-byte. A non-array is a client error; an
	 * individual entry with an unsafe path is dropped by the serialiser rather than
	 * failing the request, so one bad path cannot deny a legitimate install.
	 *
	 * @param IUser $user The acting user.
	 * @param SkillInstallRun $run The install.
	 *
	 * @return JSONResponse The quarantined skill, or a 400/500.
	 */
	private function installWithFiles(IUser $user, SkillInstallRun $run): JSONResponse {
		$auxFiles = $this->request->getParam('files', []);
		if (is_array($auxFiles) === false) {
			return new JSONResponse(['error' => 'files must be an array'], Http::STATUS_BAD_REQUEST);
		}

		$run->auxFiles = $auxFiles;
		return $this->installQuarantined(user: $user, run: $run);
	}//end installWithFiles()

	/**
	 * Install the package into quarantine and answer with the shaped skill, or 500.
	 *
	 * @param IUser $user The acting user.
	 * @param SkillInstallRun $run The install.
	 *
	 * @return JSONResponse The quarantined skill, or 500.
	 */
	private function installQuarantined(IUser $user, SkillInstallRun $run): JSONResponse {
		try {
			$skill = $this->marketplaceService->installFromSource(
				package: $run->package,
				source: $run->installSource(),
				createdBy: $user->getUID(),
				auxFiles: $run->auxFiles
			);
			return new JSONResponse($this->shape(object: $skill));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq install-from-source failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Install failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end installQuarantined()

	/**
	 * Approve a quarantined skill (the review gate → active; action-auth-gated).
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The updated skill, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-approving-a-quarantined-skill-requires-action-authorization
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-overriding-a-dangerous-scan-verdict-requires-a-stricter-action
	 */
	public function approve(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$force = (bool)$this->request->getParam('force', false);

		return $this->approveForbidden(user: $user, force: $force) ?? $this->approvedAnswer(skillId: $id, force: $force);
	}//end approve()

	/**
	 * The 403 when the caller may not approve, or may not override a dangerous
	 * verdict on a forced approve.
	 *
	 * @param IUser $user The acting user.
	 * @param bool $force Whether the approve overrides the scan verdict.
	 *
	 * @return JSONResponse|null The refusal, or null when the approve may go ahead.
	 */
	private function approveForbidden(IUser $user, bool $force): ?JSONResponse {
		try {
			$this->actionAuth->requireAction(user: $user, action: 'skill.approve-quarantined');
			if ($force === true) {
				// A caller who can approve a clean scan cannot necessarily override a
				// dangerous verdict — gate BEFORE calling the service with force: true.
				$this->actionAuth->requireAction(user: $user, action: 'skill.override-scan-verdict');
			}
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end approveForbidden()

	/**
	 * Approve the quarantined skill and answer with it, 404, 409 or 500.
	 *
	 * @param string $skillId The Skill UUID.
	 * @param bool $force Whether the approve overrides the scan verdict.
	 *
	 * @return JSONResponse The answer.
	 */
	private function approvedAnswer(string $skillId, bool $force): JSONResponse {
		try {
			$skill = $this->marketplaceService->approveQuarantined(skillId: $skillId, force: $force);
			if ($skill === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return $this->approvalAnswer(shaped: $this->shape(object: $skill), force: $force);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill approve failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Approve failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end approvedAnswer()

	/**
	 * The approved skill, or 409 when a non-forced approve left it quarantined.
	 *
	 * A non-forced approve leaves the skill quarantined ONLY when the content scanner
	 * blocked it (a dangerous verdict). Signal 409 so the UI can present the findings
	 * (from the recorded scanReport, and always the quarantineReason) and offer an
	 * explicit override. State-based detection is robust even if the store drops the
	 * scanReport object.
	 *
	 * @param array<string, mixed> $shaped The shaped skill.
	 * @param bool $force Whether the approve overrode the scan verdict.
	 *
	 * @return JSONResponse The answer.
	 */
	private function approvalAnswer(array $shaped, bool $force): JSONResponse {
		if (($shaped['state'] ?? '') === 'quarantined' && $force === false) {
			return new JSONResponse(
				[
					'error' => 'Approval blocked: content scan flagged dangerous patterns.',
					'scanReport' => ($shaped['scanReport'] ?? []),
					'quarantineReason' => ($shaped['quarantineReason'] ?? ''),
					'skill' => $shaped,
				],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse($shaped);
	}//end approvalAnswer()

	/**
	 * Publish a skill to an external hub via OpenConnector (action-auth-gated; structured
	 * error when the hub is unavailable).
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The publish result, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-publishing-a-skill-to-a-hub-requires-action-authorization
	 */
	public function publish(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->publishHubForbidden(user: $user) ?? $this->publishedToHub(skillId: $id);
	}//end publish()

	/**
	 * The 403 when the caller may not run the `skill.publish-hub` action.
	 *
	 * @param IUser $user The acting user.
	 *
	 * @return JSONResponse|null The refusal, or null when the action is allowed.
	 */
	private function publishHubForbidden(IUser $user): ?JSONResponse {
		try {
			$this->actionAuth->requireAction(user: $user, action: 'skill.publish-hub');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end publishHubForbidden()

	/**
	 * Publish to the hub the request names (`default` when none), or 500.
	 *
	 * @param string $skillId The Skill UUID.
	 *
	 * @return JSONResponse The publish result, or 500.
	 */
	private function publishedToHub(string $skillId): JSONResponse {
		$hubId = (string)$this->request->getParam('hubId', 'default');

		try {
			return new JSONResponse($this->marketplaceService->publishToHub(skillId: $skillId, hubId: $hubId));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill publish failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Publish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end publishedToHub()

	/**
	 * Publish a skill to a NEW GitHub repository tagged `topic:hermiq-skill`, built
	 * from `SkillSerializer::toPackage()` output via `SkillService::exportSkill()`
	 * (hermiq-github-store — the new PRIMARY publish path; OpenConnector's
	 * `publish()` above remains the secondary route). Gated by the SAME
	 * `skill.publish-hub` action as `publish()` — both are "publish this skill
	 * externally" mutations. Tenant-scoped: resolves the skill through the same
	 * `SkillService::exportSkill()` path `export()` already uses, so a caller
	 * cannot publish a skill outside their organisation's visibility. On success,
	 * records `githubOwner`/`githubRepo`/`publishedAt` on the skill — provenance
	 * only, never round-tripped through the exported package.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse 201 with `{repoUrl, commitSha}`; 400/401/404/422/503 on failure.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function githubPublish(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$run = new SkillGithubPublishRun(skillId: $id, user: $user);

		return $this->githubPublishRefusal(run: $run) ?? $this->pushToGithub(run: $run);
	}//end githubPublish()

	/**
	 * The first reason this publish may not go ahead, in the spec's order: the
	 * publish action, the repo pattern, the credential, the skill's visibility,
	 * the broker.
	 *
	 * @param SkillGithubPublishRun $run The publish; its target and package are read here.
	 *
	 * @return JSONResponse|null The refusal, or null when the publish may go ahead.
	 */
	private function githubPublishRefusal(SkillGithubPublishRun $run): ?JSONResponse {
		return $this->publishHubForbidden(user: $run->user)
			?? $this->invalidRepoRefusal(run: $run)
			?? $this->missingCredentialRefusal(run: $run)
			?? $this->invisibleSkillRefusal(run: $run)
			?? $this->brokerUnavailableRefusal();
	}//end githubPublishRefusal()

	/**
	 * Read the requested target into the run; the 400 when owner or repo is not a valid name.
	 *
	 * @param SkillGithubPublishRun $run The publish.
	 *
	 * @return JSONResponse|null The refusal, or null when both names are valid.
	 */
	private function invalidRepoRefusal(SkillGithubPublishRun $run): ?JSONResponse {
		$run->owner = (string)($this->request->getParam('owner') ?? '');
		$run->repo = (string)($this->request->getParam('repo') ?? '');
		$run->visibility = (string)($this->request->getParam('visibility') ?? 'private');
		$run->credentialId = (string)($this->request->getParam('credentialId') ?? '');

		if (preg_match(self::OWNER_REPO_PATTERN, $run->owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $run->repo) !== 1) {
			return new JSONResponse(['error' => 'invalid_repo'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end invalidRepoRefusal()

	/**
	 * The 422 when no broker credential was given.
	 *
	 * @param SkillGithubPublishRun $run The publish.
	 *
	 * @return JSONResponse|null The refusal, or null when a credential was given.
	 */
	private function missingCredentialRefusal(SkillGithubPublishRun $run): ?JSONResponse {
		if ($run->credentialId === '') {
			return new JSONResponse(['error' => 'A broker credentialId is required to publish'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return null;
	}//end missingCredentialRefusal()

	/**
	 * Export the skill into the run; the 404 when it is outside the caller's visibility.
	 *
	 * Tenant-scoped read: a skill outside the caller's organisation's visibility
	 * is 404, identical to export()'s existing behaviour (never a 403 that would
	 * confirm existence) — mirrors AgentTemplateController::publishGithub().
	 *
	 * @param SkillGithubPublishRun $run The publish.
	 *
	 * @return JSONResponse|null The refusal, or null when the skill was exported.
	 */
	private function invisibleSkillRefusal(SkillGithubPublishRun $run): ?JSONResponse {
		$package = $this->skillService->exportSkill(skillId: $run->skillId);
		if ($package === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$run->package = $package;
		return null;
	}//end invisibleSkillRefusal()

	/**
	 * The 503 when the GitHub credential broker is absent.
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
	 * Push the package to the new repository, stamp the provenance and answer 201.
	 *
	 * @param SkillGithubPublishRun $run The publish.
	 *
	 * @return JSONResponse 201 with `{repoUrl, commitSha}`, or 422/500.
	 */
	private function pushToGithub(SkillGithubPublishRun $run): JSONResponse {
		$result = $this->pushedOrRefused(run: $run);
		if ($result instanceof JSONResponse) {
			return $result;
		}

		$this->skillService->stampGithubPublish(
			skillId: $run->skillId,
			owner: $run->owner,
			repo: $run->repo,
			publishedAt: (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')
		);

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end pushToGithub()

	/**
	 * Push, turning a refused push into a 422 and any other failure into a 500.
	 *
	 * @param SkillGithubPublishRun $run The publish.
	 *
	 * @return array<string, mixed>|JSONResponse The push result, or the error response.
	 */
	private function pushedOrRefused(SkillGithubPublishRun $run): array|JSONResponse {
		try {
			return $this->pushPackage(run: $run);
		} catch (RuntimeException $e) {
			$this->logger->warning('Hermiq skill github publish refused: ' . $e->getMessage());
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill github publish failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Publish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end pushedOrRefused()

	/**
	 * Push the package to a new repository tagged `topic:hermiq-skill`.
	 *
	 * @param SkillGithubPublishRun $run The publish.
	 *
	 * @return array<string, mixed> The push result.
	 */
	private function pushPackage(SkillGithubPublishRun $run): array {
		return $this->pushService->push(
			package: $run->package,
			owner: $run->owner,
			repo: $run->repo,
			visibility: $run->repositoryVisibility(),
			credentialId: $run->credentialId,
			actingUserId: $run->user->getUID(),
			kind: GitHubTemplatePushServiceInterface::KIND_SKILL,
			// Publish-time file SELECTION (skills-marketplace delta): learnings.md
			// ships, learning-candidates.md is stripped — same selection as republish.
			auxFiles: ($this->skillService->publishFileSelection(skillId: $run->skillId) ?? [])
		);
	}//end pushPackage()

	/**
	 * Shape a Skill ObjectEntity into a UUID + payload response map.
	 *
	 * @param ObjectEntity $object The skill object.
	 *
	 * @return array<string, mixed> The response payload.
	 */
	private function shape(ObjectEntity $object): array {
		$data = $object->getObject();
		$data['uuid'] = (string)$object->getUuid();
		return $data;
	}//end shape()
}//end class
