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

use OCA\Hermiq\Kernel\Contracts\GitHubTemplatePushServiceInterface;
use Psr\Container\ContainerInterface;
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
class GitHubTemplatePushService implements GitHubTemplatePushServiceInterface {

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
	 * Constructor.
	 *
	 * No HTTP client: this service makes no outbound call of its own — every
	 * GitHub call goes through the broker.
	 *
	 * @param LoggerInterface $logger Logger (secret-free diagnostics only).
	 * @param ContainerInterface|null $container The app container the optional cross-app
	 *                                           classes are resolved from; null when the
	 *                                           class is built by hand in a test.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()

	/**
	 * The brokered GitHub API over this service's logger and container.
	 *
	 * @return GitHubBrokerClient The client.
	 */
	private function brokerClient(): GitHubBrokerClient {
		return new GitHubBrokerClient(logger: $this->logger, container: $this->container);
	}//end brokerClient()

	/**
	 * The Git-Data commit writer over this service's broker client.
	 *
	 * @return GitHubCommitWriter The writer.
	 */
	private function commitWriter(): GitHubCommitWriter {
		return new GitHubCommitWriter(broker: $this->brokerClient(), logger: $this->logger);
	}//end commitWriter()

	/**
	 * Whether OpenRegister's credential broker is installed.
	 *
	 * @return bool True when the broker class can be resolved.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	public function isBrokerAvailable(): bool {
		return class_exists(GitHubBrokerClient::BROKER_CLASS) === true;
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

		$run = new GitHubPublishRun(
			target: new GitHubRepoTarget(owner: $owner, repo: $repo),
			credential: new BrokerCredential(credentialId: $credentialId, actingUserId: $actingUserId)
		);
		$run->kind = $kind;
		$run->visibility = $visibility;

		return $this->publishToNewRepository(run: $run, payload: new PackagePayload(package: $package, auxFiles: $auxFiles));
	}//end push()

	/**
	 * Create the repository, tag it and commit the package onto its default branch.
	 *
	 * @param GitHubPublishRun $run The publish.
	 * @param PackagePayload $payload The package and its auxiliary files.
	 *
	 * @return array{repoUrl:string,commitSha:string} The repo URL and the commit SHA.
	 *
	 * @throws RuntimeException On any refusal or GitHub API failure.
	 */
	private function publishToNewRepository(GitHubPublishRun $run, PackagePayload $payload): array {
		$this->assertPublishable(run: $run);
		$this->assertRepoAbsent(run: $run);
		$this->adoptRepository(run: $run, repoData: $this->createRepo(run: $run));
		$this->setTopics(run: $run);

		return [
			'repoUrl' => $run->repoUrl,
			'commitSha' => $this->commitWriter()->commitPackage(run: $run, payload: $payload),
		];
	}//end publishToNewRepository()

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
		$run = new GitHubPublishRun(
			target: new GitHubRepoTarget(owner: $owner, repo: $repo),
			credential: new BrokerCredential(credentialId: $credentialId, actingUserId: $actingUserId)
		);
		$run->operation = 'GitHub republish';
		$run->kind = $kind;

		$this->logger->info(
			'Hermiq GitHub republish: updating repository',
			['owner' => $owner, 'repo' => $repo, 'kind' => $kind]
		);

		return $this->republishToExistingRepository(run: $run, payload: new PackagePayload(package: $package, auxFiles: $auxFiles));
	}//end pushUpdate()

	/**
	 * Commit the package onto the default branch of the repository the run names,
	 * which must already exist.
	 *
	 * @param GitHubPublishRun $run The republish.
	 * @param PackagePayload $payload The package and its auxiliary files.
	 *
	 * @return array{repoUrl:string,commitSha:string} The repo URL and the update commit SHA.
	 *
	 * @throws RuntimeException On any refusal or GitHub API failure.
	 */
	private function republishToExistingRepository(GitHubPublishRun $run, PackagePayload $payload): array {
		$this->assertPublishable(run: $run);

		// The provenance repo must EXIST — a vanished repo is a refusal, never a
		// silent re-create (that would be a first publish, which has its own path).
		$repoData = $this->brokerClient()->call(run: $run, call: new GitHubCall(method: 'GET', path: $run->target->apiPath()));
		if ($repoData === null) {
			throw new RuntimeException(
				sprintf('Repository %s/%s does not exist — cannot republish.', $run->target->owner, $run->target->repo)
			);
		}

		$this->adoptRepository(run: $run, repoData: $repoData);

		return [
			'repoUrl' => $run->repoUrl,
			'commitSha' => $this->commitWriter()->commitPackage(run: $run, payload: $payload),
		];
	}//end republishToExistingRepository()

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
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function publishBundle(
		array $files,
		string $owner,
		string $repo,
		string $visibility,
		string $credentialId,
		?string $actingUserId = null,
	): array {
		$run = new GitHubPublishRun(
			target: new GitHubRepoTarget(owner: $owner, repo: $repo),
			credential: new BrokerCredential(credentialId: $credentialId, actingUserId: $actingUserId)
		);
		$run->operation = 'GitHub bundle publish';
		$run->commitLabel = 'GitHub bundle publish';
		$run->kind = self::KIND_SKILL_BUNDLE;
		$run->visibility = $visibility;

		return $this->publishBundleTree(run: $run, files: $files);
	}//end publishBundle()

	/**
	 * Create-or-update the bundle repository, tag it, commit the tree and log the outcome.
	 *
	 * @param GitHubPublishRun $run The bundle publish.
	 * @param array<string,string> $files The `path => contents` tree to commit.
	 *
	 * @return array{repoUrl:string,commitSha:string,created:bool} The publish outcome.
	 *
	 * @throws RuntimeException On any refusal or GitHub API failure.
	 */
	private function publishBundleTree(GitHubPublishRun $run, array $files): array {
		$this->assertPublishable(run: $run);
		if ($files === []) {
			throw new RuntimeException('GitHub bundle publish requires a non-empty tree.');
		}

		$this->adoptRepository(run: $run, repoData: $this->bundleRepository(run: $run));
		$this->setTopics(run: $run);
		$commitSha = $this->commitWriter()->commitTree(run: $run, files: $files);
		$this->logBundlePublished(run: $run, entries: count($files));

		return [
			'repoUrl' => $run->repoUrl,
			'commitSha' => $commitSha,
			'created' => $run->created,
		];
	}//end publishBundleTree()

	/**
	 * Log a completed bundle publish: where to, whether the repository was created, how many entries.
	 *
	 * @param GitHubPublishRun $run The bundle publish.
	 * @param int $entries The number of paths in the tree that was asked for.
	 *
	 * @return void
	 */
	private function logBundlePublished(GitHubPublishRun $run, int $entries): void {
		$this->logger->info(
			'Hermiq GitHub bundle publish complete',
			['owner' => $run->target->owner, 'repo' => $run->target->repo, 'created' => $run->created, 'entries' => $entries]
		);
	}//end logBundlePublished()

	/**
	 * The bundle repository: the existing one, or a freshly created one when absent.
	 *
	 * Absent → create; present → update. A broker denial/404 here means absent,
	 * exactly as assertRepoAbsent() treats it.
	 *
	 * @param GitHubPublishRun $run The bundle publish; `created` is recorded here.
	 *
	 * @return array<string,mixed> The repository's GitHub data.
	 *
	 * @throws RuntimeException When creating the repository fails.
	 */
	private function bundleRepository(GitHubPublishRun $run): array {
		$repoData = $this->brokerClient()->call(run: $run, call: (new GitHubCall(method: 'GET', path: $run->target->apiPath()))->quietly());
		if ($repoData !== null) {
			return $repoData;
		}

		$run->created = true;
		return $this->createRepo(run: $run);
	}//end bundleRepository()

	/**
	 * Record the repository's default branch and web URL on the run.
	 *
	 * @param GitHubPublishRun $run The publish.
	 * @param array<string,mixed> $repoData The repository's GitHub data.
	 *
	 * @return void
	 */
	private function adoptRepository(GitHubPublishRun $run, array $repoData): void {
		$run->branch = (string)($repoData['default_branch'] ?? 'main');
		$run->repoUrl = (string)($repoData['html_url'] ?? $run->target->defaultUrl());
	}//end adoptRepository()

	/**
	 * Refuse a publish that cannot be made: no broker, no credential, or an invalid owner or repository name.
	 *
	 * @param GitHubPublishRun $run The publish.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Worded after the run's operation.
	 */
	private function assertPublishable(GitHubPublishRun $run): void {
		if ($this->isBrokerAvailable() === false) {
			// Fail closed. There is deliberately no token-bearing fallback.
			throw new RuntimeException($run->operation . ' requires the OpenRegister credential broker, which is not available.');
		}

		if ($run->credential->credentialId === '') {
			throw new RuntimeException($run->operation . ' requires a broker credential.');
		}

		if ($run->target->hasValidNames() === false) {
			throw new RuntimeException($run->operation . ' requires a valid owner and repository name.');
		}
	}//end assertPublishable()

	/**
	 * Fail fast when the target repository already exists.
	 *
	 * Absent is the desired outcome, so a broker denial/404 here is NOT an error —
	 * only a successful 200 means the repo already exists.
	 *
	 * @param GitHubPublishRun $run The publish.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the repository already exists.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-refuse-to-overwrite-an-existing-github-repository
	 */
	private function assertRepoAbsent(GitHubPublishRun $run): void {
		$existing = $this->brokerClient()->call(run: $run, call: (new GitHubCall(method: 'GET', path: $run->target->apiPath()))->quietly());

		if ($existing !== null) {
			throw new RuntimeException(sprintf('Repository %s/%s already exists', $run->target->owner, $run->target->repo));
		}
	}//end assertRepoAbsent()

	/**
	 * Create a new GitHub repository under the run's owner, with the run's
	 * visibility and the description of its kind.
	 *
	 * @param GitHubPublishRun $run The publish.
	 *
	 * @return array<string,mixed> The created repository's data.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function createRepo(GitHubPublishRun $run): array {
		$created = $this->brokerClient()->call(run: $run, call: $this->createRepoCall(run: $run));

		if ($created === null) {
			throw new RuntimeException('GitHub create-repo failed.');
		}

		return $created;
	}//end createRepo()

	/**
	 * The call that creates the run's repository, auto-initialised so it has a branch to commit on.
	 *
	 * @param GitHubPublishRun $run The publish.
	 *
	 * @return GitHubCall The POST /orgs/<owner>/repos call.
	 */
	private function createRepoCall(GitHubPublishRun $run): GitHubCall {
		return (new GitHubCall(method: 'POST', path: '/orgs/' . rawurlencode($run->target->owner) . '/repos'))->withBody(
			[
				'name' => $run->target->repo,
				'private' => ($run->visibility === 'private'),
				'auto_init' => true,
				'description' => $this->descriptionFor(kind: $run->kind),
			]
		);
	}//end createRepoCall()

	/**
	 * Tag the repository with the kind's discovery topic, keeping its other topics.
	 *
	 * Deliberately does not throw on failure: the repo already exists at this
	 * point, and a topic-tagging hiccup should not turn an otherwise-successful
	 * publish into an error the caller cannot recover from without re-publishing
	 * under a new name.
	 *
	 * GitHub's PUT /topics REPLACES the whole list. On a fresh repo that is
	 * fine, but publishBundle() is create-or-UPDATE: publishing a skill bundle
	 * into an existing app repository would drop that repo's own discovery
	 * topic, making it invisible to the catalogue that published it. Merge
	 * instead — observed on buildiq-hydra, where the bundle push replaced
	 * `openbuild-app` with `hermiq-skill-bundle`.
	 *
	 * @param GitHubPublishRun $run The publish.
	 *
	 * @return void
	 */
	private function setTopics(GitHubPublishRun $run): void {
		$path = $run->target->apiPath() . '/topics';
		$topic = $this->topicFor(kind: $run->kind);

		$existing = $this->brokerClient()->call(run: $run, call: (new GitHubCall(method: 'GET', path: $path))->quietly());

		$this->brokerClient()->call(
			run: $run,
			call: (new GitHubCall(method: 'PUT', path: $path))
				->withBody(['names' => $this->mergedTopics(topic: $topic, existing: $this->topicNames(topics: $existing))])
				->quietly()
		);
	}//end setTopics()

	/**
	 * The topic names a GET /topics answer carries, [] when it carries none.
	 *
	 * @param array<string,mixed>|null $topics The answer, or null when the call failed.
	 *
	 * @return array<int,mixed> The names.
	 */
	private function topicNames(?array $topics): array {
		if (is_array($topics) === true && is_array($topics['names'] ?? null) === true) {
			return $topics['names'];
		}

		return [];
	}//end topicNames()

	/**
	 * The kind's topic plus every other non-empty existing topic, once each, sorted.
	 *
	 * @param string $topic The kind's discovery topic.
	 * @param array<int,mixed> $existing The repository's existing topic names.
	 *
	 * @return array<int,string> The merged names.
	 */
	private function mergedTopics(string $topic, array $existing): array {
		$names = [$topic];
		foreach ($existing as $name) {
			$name = (string)$name;
			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		sort($names);
		return $names;
	}//end mergedTopics()

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

}//end class
