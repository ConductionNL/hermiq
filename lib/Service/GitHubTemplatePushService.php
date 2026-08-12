<?php

/**
 * Hermiq GitHubTemplatePushService.
 *
 * Publishes a portable `AgentTemplate` package (`AgentTemplateSerializer::toPackage()`
 * output) to a NEW GitHub repository tagged `topic:hermiq-agent-template`, over the
 * GitHub REST + Git Data API (create-repo → set-topics → blob/tree/commit → update-ref).
 *
 * Hermiq holds NO GitHub token. Mirrors OpenBuild's `GitHubPushService` (verified at
 * HEAD in `openbuild/lib/Service/GitHubPushService.php`) exactly: every call sends
 * `{method, path, body}` plus a credential UUID to OpenRegister's credential broker,
 * which injects the token server-side. The token never enters this process. When the
 * broker is absent we fail closed — there is no token-bearing fallback.
 *
 * Unlike OpenBuild's exporter (which pushes a generated app tree to a bootstrap
 * branch and opens a PR), this service commits a SINGLE FILE — the serialized
 * template package — directly onto the freshly created repo's default branch (no
 * PR: there is nothing to review before merging, the repo did not exist a moment
 * ago). Re-publishing to an existing `owner/repo` is refused (`assertRepoAbsent()`,
 * mirroring OpenBuild's `assertRepoAbsent()`) rather than force-pushing over content
 * a maintainer may have edited on GitHub directly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCP\Server;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GitHub delivery target for a published AgentTemplate package. Broker-only.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Same rationale as the complexity
 *   suppression below: the token-never-here invariant holds only because every
 *   GitHub call goes through this one broker seam, so splitting the publish path
 *   across classes would weaken the guarantee it exists to hold.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One class owns the whole broker-only
 *   publish path (repo create, topics, git-data commit chain, scrubbed logging) so the
 *   token-never-here invariant stays in a single place.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */
class GitHubTemplatePushService {
	/**
	 * OpenRegister's credential broker (resolved lazily; may be absent).
	 *
	 * @var string
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The broker `appId` Hermiq identifies itself with.
	 *
	 * @var string
	 */
	private const APP_ID = 'hermiq';

	/**
	 * The `AgentTemplate` kind — the original (and default) publish kind.
	 *
	 * @var string
	 */
	public const KIND_AGENT_TEMPLATE = 'agent-template';

	/**
	 * The `Skill` kind (hermiq-github-store) — agentskills.io packages.
	 *
	 * @var string
	 */
	public const KIND_SKILL = 'skill';

	/**
	 * Per-kind discovery topic (hermiq-github-store: generalised from the single
	 * agent-template-only `DISCOVERY_TOPIC`) every published repo of a kind
	 * carries. NOTE: unlike `GitHubTemplateCatalogService::DISCOVERY_TOPICS`,
	 * these are bare topic names (no `topic:` search-qualifier prefix) — this is
	 * the `repos/.../topics` write API, not the search API.
	 *
	 * @var array<string,string>
	 */
	private const DISCOVERY_TOPICS = [
		self::KIND_AGENT_TEMPLATE => 'hermiq-agent-template',
		self::KIND_SKILL => 'hermiq-skill',
		self::KIND_SKILL_BUNDLE => 'hermiq-skill-bundle',
	];

	/**
	 * The skill-BUNDLE kind (skill-bundle-publish) — many skills in one repo.
	 *
	 * Carries its own discovery topic so the single-skill catalogue never returns
	 * a bundle repo as an installable single skill: a bundle has no
	 * `hermiq-skill.md` at its root and would otherwise parse as an empty skill.
	 *
	 * @var string
	 */
	public const KIND_SKILL_BUNDLE = 'skill-bundle';

	/**
	 * Per-kind repo-root file name the committed package is written to (mirrors
	 * `GitHubTemplateCatalogService::PACKAGE_FILES` — the same convention on both
	 * sides of the publish/install round-trip).
	 *
	 * @var array<string,string>
	 */
	private const PACKAGE_FILES = [
		self::KIND_AGENT_TEMPLATE => 'hermiq-agent-template.json',
		self::KIND_SKILL => 'hermiq-skill.md',
	];

	/**
	 * Safe owner/repo pattern (GitHub allows alnum, `-`, `_`, `.`).
	 *
	 * @var string
	 */
	private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Constructor.
	 *
	 * No HTTP client: this service makes no outbound call of its own — every
	 * GitHub call goes through the broker.
	 *
	 * @param LoggerInterface $logger Logger (secret-free diagnostics only).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister's credential broker is installed.
	 *
	 * @return bool True when the broker class can be resolved.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	public function isBrokerAvailable(): bool {
		return class_exists(self::BROKER_CLASS) === true;
	}//end isBrokerAvailable()

	/**
	 * Publish a serialized AgentTemplate package to a new, tagged GitHub repository.
	 *
	 * @param string $package The JSON package string (`AgentTemplateSerializer::toPackage()` output).
	 * @param string $owner Target GitHub owner (user or organisation).
	 * @param string $repo Target repository name.
	 * @param string $visibility Repository visibility (`public`|`private`).
	 * @param string $credentialId Broker credential UUID for a `github` provider credential.
	 *                             Not a token: this service cannot read the secret behind
	 *                             it.
	 * @param string|null $actingUserId Credential owner. Required when there is no user
	 *                                  session for the broker's ownership guard to read.
	 * @param string $kind The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`,
	 *                     hermiq-github-store). Defaults to `KIND_AGENT_TEMPLATE` —
	 *                     every existing caller that omits `$kind` gets EXACTLY the
	 *                     prior agent-template-only behaviour.
	 * @param array $auxFiles Additional repo-root files (`{name, content}` entries) to
	 *                        commit alongside the package (skill-self-improvement:
	 *                        the ALREADY-SELECTED skill files — the caller applies the
	 *                        `learning-candidates.md` strip BEFORE this boundary).
	 *                        Empty (every pre-existing caller) is byte-identical to
	 *                        before.
	 *
	 * @return array{repoUrl:string,commitSha:string} The repo URL and the commit SHA the package landed in.
	 *
	 * @throws RuntimeException On any GitHub API failure (broker absent, no credential,
	 *                          repo already exists, auth failure, …).
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-refuse-to-overwrite-an-existing-github-repository
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function push(
		string $package,
		string $owner,
		string $repo,
		string $visibility,
		string $credentialId,
		?string $actingUserId = null,
		string $kind = self::KIND_AGENT_TEMPLATE,
		array $auxFiles = [],
	): array {
		// Audit log names only owner/repo — never the credential, never the package contents
		// (a template's systemPrompt/a skill's body may carry anything the author typed).
		$this->logger->info(
			'Hermiq GitHub publish: creating repository',
			['owner' => $owner, 'repo' => $repo, 'kind' => $kind]
		);

		if ($this->isBrokerAvailable() === false) {
			// Fail closed. There is deliberately no token-bearing fallback.
			throw new RuntimeException(
				'GitHub publish requires the OpenRegister credential broker, which is not available.'
			);
		}

		if ($credentialId === '') {
			throw new RuntimeException('GitHub publish requires a broker credential.');
		}

		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			throw new RuntimeException('GitHub publish requires a valid owner and repository name.');
		}

		$this->assertRepoAbsent(owner: $owner, repo: $repo, credentialId: $credentialId, actingUserId: $actingUserId);
		$repoData = $this->createRepo(
			owner: $owner,
			repo: $repo,
			visibility: $visibility,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			kind: $kind
		);

		$defaultBranch = (string)($repoData['default_branch'] ?? 'main');
		$this->setTopics(owner: $owner, repo: $repo, credentialId: $credentialId, actingUserId: $actingUserId, kind: $kind);

		$commitSha = $this->commitPackage(
			owner: $owner,
			repo: $repo,
			branch: $defaultBranch,
			package: $package,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			kind: $kind,
			auxFiles: $auxFiles
		);

		return [
			'repoUrl' => (string)($repoData['html_url'] ?? ('https://github.com/' . $owner . '/' . $repo)),
			'commitSha' => $commitSha,
		];
	}//end push()

	/**
	 * REPUBLISH: update-mode push to an EXISTING repository (skill-self-improvement) —
	 * the exactly-one carve-out from "refuse to overwrite an existing repository".
	 * Reachable ONLY for the repo already stamped on the SAME skill's provenance:
	 * the CALLER (SkillVersionController::republish()) derives owner/repo from the
	 * skill's own `githubOwner`/`githubRepo` and never accepts client coordinates,
	 * so publishing to any OTHER existing repository still refuses through the
	 * normal `push()` path. Same fail-closed broker chain, token never held or
	 * logged. The repo MUST already exist — the inverse of `assertRepoAbsent()`.
	 *
	 * @param string $package The serialized package string.
	 * @param string $owner The provenance-stamped GitHub owner.
	 * @param string $repo The provenance-stamped repository name.
	 * @param string $credentialId Broker credential UUID for a `github` provider credential.
	 * @param string|null $actingUserId Credential owner.
	 * @param string $kind The publish kind (`KIND_SKILL` for skills).
	 * @param array $auxFiles Additional repo-root files (already selection-filtered).
	 *
	 * @return array{repoUrl:string,commitSha:string} The repo URL and the update commit SHA.
	 *
	 * @throws RuntimeException On any GitHub API failure (broker absent, no credential,
	 *                          repo missing, auth failure, …).
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
	 */
	public function pushUpdate(
		string $package,
		string $owner,
		string $repo,
		string $credentialId,
		?string $actingUserId = null,
		string $kind = self::KIND_SKILL,
		array $auxFiles = [],
	): array {
		$this->logger->info(
			'Hermiq GitHub republish: updating repository',
			['owner' => $owner, 'repo' => $repo, 'kind' => $kind]
		);

		if ($this->isBrokerAvailable() === false) {
			// Fail closed. There is deliberately no token-bearing fallback.
			throw new RuntimeException(
				'GitHub republish requires the OpenRegister credential broker, which is not available.'
			);
		}

		if ($credentialId === '') {
			throw new RuntimeException('GitHub republish requires a broker credential.');
		}

		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			throw new RuntimeException('GitHub republish requires a valid owner and repository name.');
		}

		// The provenance repo must EXIST — a vanished repo is a refusal, never a
		// silent re-create (that would be a first publish, which has its own path).
		$repoData = $this->brokerCall(
			method: 'GET',
			path: '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo),
			body: null,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		if ($repoData === null) {
			throw new RuntimeException(sprintf('Repository %s/%s does not exist — cannot republish.', $owner, $repo));
		}

		$defaultBranch = (string)($repoData['default_branch'] ?? 'main');

		$commitSha = $this->commitPackage(
			owner: $owner,
			repo: $repo,
			branch: $defaultBranch,
			package: $package,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			kind: $kind,
			auxFiles: $auxFiles
		);

		return [
			'repoUrl' => (string)($repoData['html_url'] ?? ('https://github.com/' . $owner . '/' . $repo)),
			'commitSha' => $commitSha,
		];
	}//end pushUpdate()

	/**
	 * BUNDLE PUBLISH (skill-bundle-publish): commit a whole `path => contents` tree
	 * to a repository, CREATING it when absent and UPDATING it when present.
	 *
	 * This is the deliberate, narrow second carve-out from "refuse to overwrite an
	 * existing repository". The original refusal protects a single-skill publish
	 * from clobbering an unrelated repo; a bundle repo is by definition re-synced,
	 * so refusing it would make the feature useless. The safeguards that make the
	 * carve-out safe are:
	 *
	 *   - the commit rides `base_tree`, so paths OUTSIDE the bundle's own
	 *     `skills/` + manifest are preserved rather than truncated;
	 *   - the ref is PATCHed forward, never force-pushed;
	 *   - the caller supplies the tree from SkillBundleSerializer, which has
	 *     already validated every name and path.
	 *
	 * @param array<string,string> $files The `path => contents` tree to commit.
	 * @param string $owner Target GitHub owner (user or organisation).
	 * @param string $repo Target repository name.
	 * @param string $visibility Visibility for a freshly created repo.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Credential owner.
	 *
	 * @return array{repoUrl:string,commitSha:string,created:bool} The publish outcome.
	 *
	 * @throws RuntimeException On a missing broker, bad coordinates, or API failure.
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function publishBundle(
		array $files,
		string $owner,
		string $repo,
		string $visibility,
		string $credentialId,
		?string $actingUserId = null,
	): array {
		if ($this->isBrokerAvailable() === false) {
			// Fail closed — there is deliberately no token-bearing fallback.
			throw new RuntimeException(
				'GitHub bundle publish requires the OpenRegister credential broker, which is not available.'
			);
		}

		if ($credentialId === '') {
			throw new RuntimeException('GitHub bundle publish requires a broker credential.');
		}

		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			throw new RuntimeException('GitHub bundle publish requires a valid owner and repository name.');
		}

		if ($files === []) {
			throw new RuntimeException('GitHub bundle publish requires a non-empty tree.');
		}

		// Absent → create; present → update. A broker denial/404 here means absent,
		// exactly as assertRepoAbsent() treats it.
		$repoData = $this->brokerCall(
			method: 'GET',
			path: '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo),
			body: null,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			failQuietly: true
		);

		$created = false;
		if ($repoData === null) {
			$repoData = $this->createRepo(
				owner: $owner,
				repo: $repo,
				visibility: $visibility,
				credentialId: $credentialId,
				actingUserId: $actingUserId,
				kind: self::KIND_SKILL_BUNDLE
			);
			$created = true;
		}

		$this->setTopics(
			owner: $owner,
			repo: $repo,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			kind: self::KIND_SKILL_BUNDLE
		);

		$commitSha = $this->commitTree(
			owner: $owner,
			repo: $repo,
			branch: (string)($repoData['default_branch'] ?? 'main'),
			files: $files,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		$this->logger->info(
			'Hermiq GitHub bundle publish complete',
			['owner' => $owner, 'repo' => $repo, 'created' => $created, 'entries' => count($files)]
		);

		return [
			'repoUrl' => (string)($repoData['html_url'] ?? ('https://github.com/' . $owner . '/' . $repo)),
			'commitSha' => $commitSha,
			'created' => $created,
		];

	}//end publishBundle()

	/**
	 * Commit an arbitrary `path => contents` tree in ONE commit.
	 *
	 * The map-based sibling of commitPackage(): same Git-Data sequence (ref → base
	 * commit → blobs → tree → commit → ref update), but the caller supplies the
	 * whole tree rather than a package plus auxiliaries. Kept as a single method
	 * for the same reason commitPackage() is — splitting it would scatter the
	 * commit-chain invariants across helpers.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string $branch Branch to advance.
	 * @param array<string,string> $files The tree to commit.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Credential owner.
	 *
	 * @return string The new commit SHA.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function commitTree(
		string $owner,
		string $repo,
		string $branch,
		array $files,
		string $credentialId,
		?string $actingUserId,
	): string {
		$base = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);

		$ref = $this->getJson(
			path: $base . '/git/refs/heads/' . rawurlencode($branch),
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$baseCommitSha = (string)($ref['object']['sha'] ?? '');
		if ($baseCommitSha === '') {
			throw new RuntimeException('GitHub bundle publish: could not resolve the default branch tip.');
		}

		$baseCommit = $this->getJson(
			path: $base . '/git/commits/' . rawurlencode($baseCommitSha),
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$baseTreeSha = (string)($baseCommit['tree']['sha'] ?? '');

		$treeEntries = $this->uploadBlobs(
			base: $base,
			files: $files,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		if ($treeEntries === []) {
			throw new RuntimeException('GitHub bundle publish: no publishable entries after path validation.');
		}

		// Base_tree preserves every path this bundle does not own — the property
		// that makes updating an existing repository safe rather than truncating.
		$tree = $this->postJson(
			path: $base . '/git/trees',
			body: ['base_tree' => $baseTreeSha, 'tree' => $treeEntries],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$treeSha = (string)($tree['sha'] ?? '');

		$commit = $this->postJson(
			path: $base . '/git/commits',
			body: [
				'message' => 'chore: publish agent skill bundle from Hermiq',
				'tree' => $treeSha,
				'parents' => [$baseCommitSha],
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$commitSha = (string)($commit['sha'] ?? '');
		if ($commitSha === '') {
			throw new RuntimeException('GitHub bundle publish: commit creation failed.');
		}

		$this->brokerCall(
			method: 'PATCH',
			path: $base . '/git/refs/heads/' . rawurlencode($branch),
			body: ['sha' => $commitSha],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		return $commitSha;
	}//end commitTree()

	/**
	 * Upload every file as a blob and return the resulting tree entries.
	 *
	 * @param string $base The `/repos/{owner}/{repo}` API base.
	 * @param array<string,string> $files The `path => contents` map.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Credential owner.
	 *
	 * @return array<int,array<string,string>> The tree entries.
	 *
	 * @throws RuntimeException When a blob upload returns no sha.
	 */
	private function uploadBlobs(string $base, array $files, string $credentialId, ?string $actingUserId): array {
		$treeEntries = [];

		foreach ($files as $path => $contents) {
			$path = (string)$path;
			if ($path === '' || $this->isSafeRepoPath(path: $path) === false) {
				$this->logger->warning('Hermiq bundle publish: skipped unsafe path.', ['path' => $path]);
				continue;
			}

			$blob = $this->postJson(
				path: $base . '/git/blobs',
				body: ['content' => base64_encode((string)$contents), 'encoding' => 'base64'],
				credentialId: $credentialId,
				actingUserId: $actingUserId
			);
			$blobSha = (string)($blob['sha'] ?? '');

			// A blob whose sha did not come back MUST NOT reach the tree. GitHub
			// rejects the WHOLE create-tree request with a 422 for one bad entry,
			// so a single silently-empty sha discards every other blob uploaded in
			// this run — hundreds of successful uploads lost to an error message
			// that names no file. Failing here names the path instead.
			if ($blobSha === '') {
				throw new RuntimeException(
					'GitHub bundle publish: blob upload returned no sha for "' . $path . '".'
				);
			}

			$treeEntries[] = [
				'path' => $path,
				'mode' => '100644',
				'type' => 'blob',
				'sha' => $blobSha,
			];
		}//end foreach

		return $treeEntries;
	}//end uploadBlobs()

	/**
	 * Fail fast when the target repository already exists.
	 *
	 * @param string $owner Owner/organisation.
	 * @param string $repo Repository name.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the repo already exists.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-refuse-to-overwrite-an-existing-github-repository
	 */
	private function assertRepoAbsent(
		string $owner,
		string $repo,
		string $credentialId,
		?string $actingUserId,
	): void {
		// Absent is the desired outcome, so a broker denial/404 here is NOT an error —
		// only a successful 200 means the repo already exists.
		$existing = $this->brokerCall(
			method: 'GET',
			path: '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo),
			body: null,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			failQuietly: true
		);

		if ($existing !== null) {
			throw new RuntimeException(sprintf('Repository %s/%s already exists', $owner, $repo));
		}
	}//end assertRepoAbsent()

	/**
	 * Create a new GitHub repository under the given owner.
	 *
	 * @param string $owner Owner/organisation.
	 * @param string $repo Repository name.
	 * @param string $visibility `public`|`private`.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential.
	 * @param string $kind The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 *
	 * @return array<string,mixed> Decoded repo payload.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function createRepo(
		string $owner,
		string $repo,
		string $visibility,
		string $credentialId,
		?string $actingUserId,
		string $kind,
	): array {
		$created = $this->brokerCall(
			method: 'POST',
			path: '/orgs/' . rawurlencode($owner) . '/repos',
			body: [
				'name' => $repo,
				'private' => ($visibility === 'private'),
				'auto_init' => true,
				'description' => $this->descriptionFor(kind: $kind),
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		if ($created === null) {
			throw new RuntimeException('GitHub create-repo failed.');
		}

		return $created;
	}//end createRepo()

	/**
	 * Tag the repository with the kind's discovery topic
	 * (`hermiq-agent-template`|`hermiq-skill`).
	 *
	 * @param string $owner Owner/organisation.
	 * @param string $repo Repository name.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential.
	 * @param string $kind The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 *
	 * @return void
	 *
	 * Deliberately does not throw on failure: the repo already exists at this
	 * point (created by createRepo()), and a topic-tagging hiccup should not
	 * turn an otherwise-successful publish into an error the caller cannot
	 * recover from without re-publishing under a new name.
	 */
	private function setTopics(string $owner, string $repo, string $credentialId, ?string $actingUserId, string $kind): void {
		$base = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/topics';
		$topic = $this->topicFor(kind: $kind);

		// GitHub's PUT /topics REPLACES the whole list. On a fresh repo that is
		// fine, but publishBundle() is create-or-UPDATE: publishing a skill bundle
		// into an existing app repository would drop that repo's own discovery
		// topic, making it invisible to the catalogue that published it. Merge
		// instead — observed on buildiq-hydra, where the bundle push replaced
		// `openbuild-app` with `hermiq-skill-bundle`.
		$names = [$topic];

		$existing = $this->brokerCall(
			method: 'GET',
			path: $base,
			body: null,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			failQuietly: true
		);

		if (is_array($existing) === true && is_array($existing['names'] ?? null) === true) {
			foreach ($existing['names'] as $name) {
				$name = (string)$name;
				if ($name !== '' && in_array($name, $names, true) === false) {
					$names[] = $name;
				}
			}
		}

		sort($names);

		$this->brokerCall(
			method: 'PUT',
			path: $base,
			body: ['names' => $names],
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			failQuietly: true
		);
	}//end setTopics()

	/**
	 * Commit the serialized package as a single blob on the repo's default branch
	 * (created by `auto_init: true`, which already carries one README commit).
	 *
	 * @param string $owner Owner/organisation.
	 * @param string $repo Repository name.
	 * @param string $branch The default branch to commit onto.
	 * @param string $package The JSON package string.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential.
	 * @param string $kind The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 * @param array $auxFiles Additional `{name, content}` files committed
	 *                        alongside the package (already selection-filtered
	 *                        by the caller — `learning-candidates.md` never
	 *                        reaches this boundary).
	 *
	 * @return string Commit SHA.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One linear GitHub git-data
	 *   sequence (ref → base commit → blobs → tree → commit → ref update); splitting
	 *   it would scatter the commit-chain invariants across helpers.
	 */
	private function commitPackage(
		string $owner,
		string $repo,
		string $branch,
		string $package,
		string $credentialId,
		?string $actingUserId,
		string $kind,
		array $auxFiles = [],
	): string {
		$base = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);

		$ref = $this->getJson(
			path: $base . '/git/refs/heads/' . rawurlencode($branch),
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$baseCommitSha = (string)($ref['object']['sha'] ?? '');
		if ($baseCommitSha === '') {
			throw new RuntimeException('GitHub publish: could not resolve the default branch tip.');
		}

		$baseCommit = $this->getJson(
			path: $base . '/git/commits/' . rawurlencode($baseCommitSha),
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$baseTreeSha = (string)($baseCommit['tree']['sha'] ?? '');

		$blob = $this->postJson(
			path: $base . '/git/blobs',
			body: ['content' => base64_encode($package), 'encoding' => 'base64'],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$blobSha = (string)($blob['sha'] ?? '');

		$treeEntries = [
			[
				'path' => $this->packageFileFor(kind: $kind),
				'mode' => '100644',
				'type' => 'blob',
				'sha' => $blobSha,
			],
		];

		// Skill-self-improvement: auxiliary skill files (learnings.md included, the
		// learning-candidates.md strip already applied by the caller's selection)
		// ride the SAME commit as additional blobs at their own paths.
		foreach ($auxFiles as $auxFile) {
			if (is_array($auxFile) === false) {
				continue;
			}

			$auxName = (string)($auxFile['name'] ?? '');
			if ($auxName === '' || $this->isSafeRepoPath(path: $auxName) === false) {
				continue;
			}

			$auxBlob = $this->postJson(
				path: $base . '/git/blobs',
				body: ['content' => base64_encode((string)($auxFile['content'] ?? '')), 'encoding' => 'base64'],
				credentialId: $credentialId,
				actingUserId: $actingUserId
			);

			$treeEntries[] = [
				'path' => $auxName,
				'mode' => '100644',
				'type' => 'blob',
				'sha' => (string)($auxBlob['sha'] ?? ''),
			];
		}//end foreach

		$tree = $this->postJson(
			path: $base . '/git/trees',
			body: [
				'base_tree' => $baseTreeSha,
				'tree' => $treeEntries,
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$treeSha = (string)($tree['sha'] ?? '');

		$commit = $this->postJson(
			path: $base . '/git/commits',
			body: [
				'message' => $this->commitMessageFor(kind: $kind),
				'tree' => $treeSha,
				'parents' => [$baseCommitSha],
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$commitSha = (string)($commit['sha'] ?? '');
		if ($commitSha === '') {
			throw new RuntimeException('GitHub publish: commit creation failed.');
		}

		$this->brokerCall(
			method: 'PATCH',
			path: $base . '/git/refs/heads/' . rawurlencode($branch),
			body: ['sha' => $commitSha],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		return $commitSha;
	}//end commitPackage()

	/**
	 * GET a JSON body through the broker and decode the response.
	 *
	 * @param string $path GitHub-relative path.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential.
	 *
	 * @return array<string,mixed> Decoded response payload.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function getJson(string $path, string $credentialId, ?string $actingUserId): array {
		$decoded = $this->brokerCall(
			method: 'GET',
			path: $path,
			body: null,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		if ($decoded === null) {
			throw new RuntimeException('GitHub API call failed: GET ' . $path);
		}

		return $decoded;
	}//end getJson()

	/**
	 * POST a JSON body through the broker and decode the response.
	 *
	 * @param string $path GitHub-relative path.
	 * @param array<string,mixed> $body Request body.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential.
	 *
	 * @return array<string,mixed> Decoded response payload.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function postJson(string $path, array $body, string $credentialId, ?string $actingUserId): array {
		$decoded = $this->brokerCall(
			method: 'POST',
			path: $path,
			body: $body,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		if ($decoded === null) {
			throw new RuntimeException('GitHub API call failed: POST ' . $path);
		}

		return $decoded;
	}//end postJson()

	/**
	 * Route one GitHub call through OpenRegister's credential broker.
	 *
	 * We send only {method, path, body}: the base URL is the broker's host-lock and
	 * the Authorization header is injected there from the vault. This process never
	 * sees the token, so there is nothing here to leak into a log or an exception.
	 *
	 * A non-2xx (including a broker 403 for an unlisted method/path) returns `null`
	 * rather than throwing, because `assertRepoAbsent()` treats absence as the happy
	 * path. Every other caller turns `null` into a RuntimeException.
	 *
	 * @param string $method HTTP method.
	 * @param string $path GitHub-relative path.
	 * @param array<string,mixed>|null $body Optional JSON body.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential.
	 * @param bool $failQuietly Suppress the error log on failure.
	 *
	 * @return array<string,mixed>|null Decoded payload, or null on any non-2xx.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $failQuietly is a genuine two-mode
	 *   logging input: setTopics() treats failure as cosmetic while every other caller
	 *   wants the scrubbed warning.
	 * @SuppressWarnings(PHPMD.StaticAccess)        OCP\Server::get is deliberate lazy
	 *   resolution of the optional OpenRegister broker so this class stays
	 *   constructible when the broker is absent.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	private function brokerCall(
		string $method,
		string $path,
		?array $body,
		string $credentialId,
		?string $actingUserId,
		bool $failQuietly = false,
	): ?array {
		$encoded = null;
		if ($body !== null) {
			$encoded = (string)json_encode($body);
		}

		try {
			$broker = Server::get(self::BROKER_CLASS);
			$response = $broker->request(
				$credentialId,
				self::APP_ID,
				$method,
				$path,
				['Accept' => 'application/vnd.github+json', 'Content-Type' => 'application/json'],
				$encoded,
				$actingUserId
			);
		} catch (\Throwable $e) {
			if ($failQuietly === false) {
				// Never log the body — it may carry the package contents, which can
				// carry anything the author typed. Method and path only.
				$this->logger->warning(
					'Hermiq GitHub template publish: broker call failed for ' . $method . ' ' . $path
					. ': ' . $this->scrub(message: $e->getMessage())
				);
			}

			return null;
		}//end try

		$status = (int)($response['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			if ($failQuietly === false) {
				$this->logger->warning(
					'Hermiq GitHub template publish: ' . $method . ' ' . $path . ' returned HTTP ' . $status
				);
			}

			return null;
		}

		return $this->decode(body: (string)($response['body'] ?? ''));
	}//end brokerCall()

	/**
	 * Resolve a kind's discovery topic (hermiq-github-store), falling back to
	 * `KIND_AGENT_TEMPLATE`'s topic for an unrecognised kind.
	 *
	 * @param string $kind The publish kind.
	 *
	 * @return string The bare topic name (no `topic:` search-qualifier prefix).
	 */
	private function topicFor(string $kind): string {
		return self::DISCOVERY_TOPICS[$kind] ?? self::DISCOVERY_TOPICS[self::KIND_AGENT_TEMPLATE];
	}//end topicFor()

	/**
	 * Resolve a kind's repo-root package file name (hermiq-github-store),
	 * falling back to `KIND_AGENT_TEMPLATE`'s file name for an unrecognised kind.
	 *
	 * @param string $kind The publish kind.
	 *
	 * @return string The repo-relative package file name.
	 */
	private function packageFileFor(string $kind): string {
		return self::PACKAGE_FILES[$kind] ?? self::PACKAGE_FILES[self::KIND_AGENT_TEMPLATE];
	}//end packageFileFor()

	/**
	 * Kind-appropriate new-repo description (hermiq-github-store).
	 *
	 * @param string $kind The publish kind.
	 *
	 * @return string The repo description.
	 */
	private function descriptionFor(string $kind): string {
		if ($kind === self::KIND_SKILL) {
			return 'Published from Hermiq — a portable Hermiq agent skill.';
		}

		return 'Published from Hermiq — a portable Hermiq agent template.';
	}//end descriptionFor()

	/**
	 * Kind-appropriate commit message (hermiq-github-store).
	 *
	 * @param string $kind The publish kind.
	 *
	 * @return string The commit message.
	 */
	private function commitMessageFor(string $kind): string {
		if ($kind === self::KIND_SKILL) {
			return 'chore: publish agent skill from Hermiq';
		}

		return 'chore: publish agent template from Hermiq';
	}//end commitMessageFor()

	/**
	 * Whether an auxiliary file name is a safe repo-relative path: no absolute
	 * paths, no `..` traversal, no backslashes, sane length.
	 *
	 * @param string $path The candidate repo path.
	 *
	 * @return bool True when safe to commit.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	private function isSafeRepoPath(string $path): bool {
		if (strlen($path) > 200 || str_starts_with($path, '/') === true || str_contains($path, '\\') === true) {
			return false;
		}

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return false;
			}
		}

		return true;
	}//end isSafeRepoPath()

	/**
	 * Decode a JSON response body into an array.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return array<string,mixed> Decoded payload (empty on non-array).
	 */
	private function decode(string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decode()

	/**
	 * Remove any leaked PAT-shaped tokens from an error message before it is
	 * surfaced into logs (defence in depth).
	 *
	 * @param string $message Raw error message.
	 *
	 * @return string Scrubbed message.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	private function scrub(string $message): string {
		return (string)preg_replace('/gh[pousr]_[A-Za-z0-9]{20,}/', '[redacted-token]', $message);
	}//end scrub()
}//end class
