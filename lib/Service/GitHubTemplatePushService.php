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
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 * @spec openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCP\Server;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GitHub delivery target for a published AgentTemplate package. Broker-only.
 *
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */
class GitHubTemplatePushService
{
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
        self::KIND_SKILL          => 'hermiq-skill',
    ];

    /**
     * Per-kind repo-root file name the committed package is written to (mirrors
     * `GitHubTemplateCatalogService::PACKAGE_FILES` — the same convention on both
     * sides of the publish/install round-trip).
     *
     * @var array<string,string>
     */
    private const PACKAGE_FILES = [
        self::KIND_AGENT_TEMPLATE => 'hermiq-agent-template.json',
        self::KIND_SKILL          => 'hermiq-skill.md',
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
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
     */
    public function isBrokerAvailable(): bool
    {
        return class_exists(self::BROKER_CLASS) === true;
    }//end isBrokerAvailable()

    /**
     * Publish a serialized AgentTemplate package to a new, tagged GitHub repository.
     *
     * @param string      $package      The JSON package string (`AgentTemplateSerializer::toPackage()` output).
     * @param string      $owner        Target GitHub owner (user or organisation).
     * @param string      $repo         Target repository name.
     * @param string      $visibility   Repository visibility (`public`|`private`).
     * @param string      $credentialId Broker credential UUID for a `github` provider credential.
     *                                  Not a token: this service cannot read the secret behind
     *                                  it.
     * @param string|null $actingUserId Credential owner. Required when there is no user
     *                                  session for the broker's ownership guard to read.
     * @param string      $kind         The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`,
     *                                  hermiq-github-store). Defaults to `KIND_AGENT_TEMPLATE` —
     *                                  every existing caller that omits `$kind` gets EXACTLY the
     *                                  prior agent-template-only behaviour.
     *
     * @return array{repoUrl:string,commitSha:string} The repo URL and the commit SHA the package landed in.
     *
     * @throws RuntimeException On any GitHub API failure (broker absent, no credential,
     *                          repo already exists, auth failure, …).
     *
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-refuse-to-overwrite-an-existing-github-repository
     * @spec openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
     */
    public function push(
        string $package,
        string $owner,
        string $repo,
        string $visibility,
        string $credentialId,
        ?string $actingUserId=null,
        string $kind=self::KIND_AGENT_TEMPLATE,
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

        $defaultBranch = (string) ($repoData['default_branch'] ?? 'main');
        $this->setTopics(owner: $owner, repo: $repo, credentialId: $credentialId, actingUserId: $actingUserId, kind: $kind);

        $commitSha = $this->commitPackage(
            owner: $owner,
            repo: $repo,
            branch: $defaultBranch,
            package: $package,
            credentialId: $credentialId,
            actingUserId: $actingUserId,
            kind: $kind
        );

        return [
            'repoUrl'   => (string) ($repoData['html_url'] ?? ('https://github.com/'.$owner.'/'.$repo)),
            'commitSha' => $commitSha,
        ];
    }//end push()

    /**
     * Fail fast when the target repository already exists.
     *
     * @param string      $owner        Owner/organisation.
     * @param string      $repo         Repository name.
     * @param string      $credentialId Broker credential UUID.
     * @param string|null $actingUserId Owner of the credential.
     *
     * @return void
     *
     * @throws RuntimeException When the repo already exists.
     *
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-refuse-to-overwrite-an-existing-github-repository
     */
    private function assertRepoAbsent(
        string $owner,
        string $repo,
        string $credentialId,
        ?string $actingUserId
    ): void {
        // Absent is the desired outcome, so a broker denial/404 here is NOT an error —
        // only a successful 200 means the repo already exists.
        $existing = $this->brokerCall(
            method: 'GET',
            path: '/repos/'.rawurlencode($owner).'/'.rawurlencode($repo),
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
     * @param string      $owner        Owner/organisation.
     * @param string      $repo         Repository name.
     * @param string      $visibility   `public`|`private`.
     * @param string      $credentialId Broker credential UUID.
     * @param string|null $actingUserId Owner of the credential.
     * @param string      $kind         The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
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
        string $kind
    ): array {
        $created = $this->brokerCall(
            method: 'POST',
            path: '/orgs/'.rawurlencode($owner).'/repos',
            body: [
                'name'        => $repo,
                'private'     => ($visibility === 'private'),
                'auto_init'   => true,
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
     * @param string      $owner        Owner/organisation.
     * @param string      $repo         Repository name.
     * @param string      $credentialId Broker credential UUID.
     * @param string|null $actingUserId Owner of the credential.
     * @param string      $kind         The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
     *
     * @return void
     *
     * Deliberately does not throw on failure: the repo already exists at this
     * point (created by createRepo()), and a topic-tagging hiccup should not
     * turn an otherwise-successful publish into an error the caller cannot
     * recover from without re-publishing under a new name.
     */
    private function setTopics(string $owner, string $repo, string $credentialId, ?string $actingUserId, string $kind): void
    {
        $this->brokerCall(
            method: 'PUT',
            path: '/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/topics',
            body: ['names' => [$this->topicFor(kind: $kind)]],
            credentialId: $credentialId,
            actingUserId: $actingUserId,
            failQuietly: true
        );
    }//end setTopics()

    /**
     * Commit the serialized package as a single blob on the repo's default branch
     * (created by `auto_init: true`, which already carries one README commit).
     *
     * @param string      $owner        Owner/organisation.
     * @param string      $repo         Repository name.
     * @param string      $branch       The default branch to commit onto.
     * @param string      $package      The JSON package string.
     * @param string      $credentialId Broker credential UUID.
     * @param string|null $actingUserId Owner of the credential.
     * @param string      $kind         The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
     *
     * @return string Commit SHA.
     *
     * @throws RuntimeException On API failure.
     */
    private function commitPackage(
        string $owner,
        string $repo,
        string $branch,
        string $package,
        string $credentialId,
        ?string $actingUserId,
        string $kind
    ): string {
        $base = '/repos/'.rawurlencode($owner).'/'.rawurlencode($repo);

        $ref           = $this->getJson(
            path: $base.'/git/refs/heads/'.rawurlencode($branch),
            credentialId: $credentialId,
            actingUserId: $actingUserId
        );
        $baseCommitSha = (string) ($ref['object']['sha'] ?? '');
        if ($baseCommitSha === '') {
            throw new RuntimeException('GitHub publish: could not resolve the default branch tip.');
        }

        $baseCommit  = $this->getJson(
            path: $base.'/git/commits/'.rawurlencode($baseCommitSha),
            credentialId: $credentialId,
            actingUserId: $actingUserId
        );
        $baseTreeSha = (string) ($baseCommit['tree']['sha'] ?? '');

        $blob    = $this->postJson(
            path: $base.'/git/blobs',
            body: ['content' => base64_encode($package), 'encoding' => 'base64'],
            credentialId: $credentialId,
            actingUserId: $actingUserId
        );
        $blobSha = (string) ($blob['sha'] ?? '');

        $tree    = $this->postJson(
            path: $base.'/git/trees',
            body: [
                'base_tree' => $baseTreeSha,
                'tree'      => [
                    [
                        'path' => $this->packageFileFor(kind: $kind),
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha'  => $blobSha,
                    ],
                ],
            ],
            credentialId: $credentialId,
            actingUserId: $actingUserId
        );
        $treeSha = (string) ($tree['sha'] ?? '');

        $commit    = $this->postJson(
            path: $base.'/git/commits',
            body: [
                'message' => $this->commitMessageFor(kind: $kind),
                'tree'    => $treeSha,
                'parents' => [$baseCommitSha],
            ],
            credentialId: $credentialId,
            actingUserId: $actingUserId
        );
        $commitSha = (string) ($commit['sha'] ?? '');
        if ($commitSha === '') {
            throw new RuntimeException('GitHub publish: commit creation failed.');
        }

        $this->brokerCall(
            method: 'PATCH',
            path: $base.'/git/refs/heads/'.rawurlencode($branch),
            body: ['sha' => $commitSha],
            credentialId: $credentialId,
            actingUserId: $actingUserId
        );

        return $commitSha;
    }//end commitPackage()

    /**
     * GET a JSON body through the broker and decode the response.
     *
     * @param string      $path         GitHub-relative path.
     * @param string      $credentialId Broker credential UUID.
     * @param string|null $actingUserId Owner of the credential.
     *
     * @return array<string,mixed> Decoded response payload.
     *
     * @throws RuntimeException On API failure.
     */
    private function getJson(string $path, string $credentialId, ?string $actingUserId): array
    {
        $decoded = $this->brokerCall(
            method: 'GET',
            path: $path,
            body: null,
            credentialId: $credentialId,
            actingUserId: $actingUserId
        );

        if ($decoded === null) {
            throw new RuntimeException('GitHub API call failed: GET '.$path);
        }

        return $decoded;
    }//end getJson()

    /**
     * POST a JSON body through the broker and decode the response.
     *
     * @param string              $path         GitHub-relative path.
     * @param array<string,mixed> $body         Request body.
     * @param string              $credentialId Broker credential UUID.
     * @param string|null         $actingUserId Owner of the credential.
     *
     * @return array<string,mixed> Decoded response payload.
     *
     * @throws RuntimeException On API failure.
     */
    private function postJson(string $path, array $body, string $credentialId, ?string $actingUserId): array
    {
        $decoded = $this->brokerCall(
            method: 'POST',
            path: $path,
            body: $body,
            credentialId: $credentialId,
            actingUserId: $actingUserId
        );

        if ($decoded === null) {
            throw new RuntimeException('GitHub API call failed: POST '.$path);
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
     * @param string                   $method       HTTP method.
     * @param string                   $path         GitHub-relative path.
     * @param array<string,mixed>|null $body         Optional JSON body.
     * @param string                   $credentialId Broker credential UUID.
     * @param string|null              $actingUserId Owner of the credential.
     * @param bool                     $failQuietly  Suppress the error log on failure.
     *
     * @return array<string,mixed>|null Decoded payload, or null on any non-2xx.
     *
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
     */
    private function brokerCall(
        string $method,
        string $path,
        ?array $body,
        string $credentialId,
        ?string $actingUserId,
        bool $failQuietly=false
    ): ?array {
        $encoded = null;
        if ($body !== null) {
            $encoded = (string) json_encode($body);
        }

        try {
            $broker   = Server::get(self::BROKER_CLASS);
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
                    'Hermiq GitHub template publish: broker call failed for '.$method.' '.$path
                    .': '.$this->scrub(message: $e->getMessage())
                );
            }

            return null;
        }//end try

        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            if ($failQuietly === false) {
                $this->logger->warning(
                    'Hermiq GitHub template publish: '.$method.' '.$path.' returned HTTP '.$status
                );
            }

            return null;
        }

        return $this->decode(body: (string) ($response['body'] ?? ''));
    }//end brokerCall()

    /**
     * Resolve a kind's discovery topic (hermiq-github-store), falling back to
     * `KIND_AGENT_TEMPLATE`'s topic for an unrecognised kind.
     *
     * @param string $kind The publish kind.
     *
     * @return string The bare topic name (no `topic:` search-qualifier prefix).
     */
    private function topicFor(string $kind): string
    {
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
    private function packageFileFor(string $kind): string
    {
        return self::PACKAGE_FILES[$kind] ?? self::PACKAGE_FILES[self::KIND_AGENT_TEMPLATE];

    }//end packageFileFor()

    /**
     * Kind-appropriate new-repo description (hermiq-github-store).
     *
     * @param string $kind The publish kind.
     *
     * @return string The repo description.
     */
    private function descriptionFor(string $kind): string
    {
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
    private function commitMessageFor(string $kind): string
    {
        if ($kind === self::KIND_SKILL) {
            return 'chore: publish agent skill from Hermiq';
        }

        return 'chore: publish agent template from Hermiq';

    }//end commitMessageFor()

    /**
     * Decode a JSON response body into an array.
     *
     * @param string $body Raw response body.
     *
     * @return array<string,mixed> Decoded payload (empty on non-array).
     */
    private function decode(string $body): array
    {
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
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
     */
    private function scrub(string $message): string
    {
        return (string) preg_replace('/gh[pousr]_[A-Za-z0-9]{20,}/', '[redacted-token]', $message);
    }//end scrub()
}//end class
