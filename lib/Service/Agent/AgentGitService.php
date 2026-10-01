<?php

/**
 * Hermiq AgentGitService.
 *
 * "Keep in git" on the agent page: the owner publishes the agent's package to a
 * new GitHub repository, pushes later edits to that repository only, and pulls
 * edits made in git back into the same agent after seeing what changes.
 *
 * The repository coordinates are read from the agent, never from a request: a
 * publish stamps them, and push and pull use the stamp. Only the package's
 * fields travel; sharing, schedules, credentials and memory never come from git.
 * A pulled prompt is content-scanned like any outside import, and a dangerous
 * verdict refuses the pull.
 *
 * Errors are RuntimeExceptions whose code is the HTTP status the controller
 * answers with (404, 403, 409, 422, 502), as AppAssistantService does.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\AgentTemplateSerializer;
use OCA\Hermiq\Service\AgentTemplateService;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Publish, push and pull an agent's package to and from its own GitHub repository.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator per stage of the
 * round trip: access, package, GitHub out, GitHub in, scan.
 *
 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
 */
class AgentGitService {

	/**
	 * Package field => agent field: the only fields a pull may change.
	 *
	 * @var array<string, string>
	 */
	private const PULLED_FIELDS = [
		'name' => 'name',
		'description' => 'description',
		'category' => 'type',
		'systemPrompt' => 'prompt',
		'suggestedProvider' => 'provider',
		'suggestedModel' => 'model',
		'tools' => 'tools',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Writes the stamp and the pulled fields.
	 * @param AgentAccessService $agentAccess Who may read and who owns the agent.
	 * @param AgentTemplateService $templates Builds the agent's secret-free package.
	 * @param AgentTemplateSerializer $serializer Parses a pulled package.
	 * @param GitHubTemplatePushService $push Creates and updates the repository.
	 * @param GitHubTemplateCatalogService $catalog Reads the package from the repository.
	 * @param ContentScanService $contentScan Scans a pulled prompt.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI, one collaborator per stage.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentAccessService $agentAccess,
		private readonly AgentTemplateService $templates,
		private readonly AgentTemplateSerializer $serializer,
		private readonly GitHubTemplatePushService $push,
		private readonly GitHubTemplateCatalogService $catalog,
		private readonly ContentScanService $contentScan,
	) {
	}//end __construct()

	/**
	 * Publish the agent to a new repository and stamp it on the agent.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string $githubOwner The GitHub user or organisation to create the repository under.
	 * @param string $repo The new repository's name.
	 * @param string $visibility `public` or `private`.
	 * @param string $credentialId The caller's GitHub credential in the broker.
	 *
	 * @return array{repoUrl: string, commitSha: string}
	 *
	 * @throws RuntimeException 404, 403, 409 (already kept in git) or 502 (GitHub refused).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The publish form's fields, each a distinct input.
	 *
	 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
	 */
	public function publish(string $agentId, string $uid, string $githubOwner, string $repo, string $visibility, string $credentialId): array {
		$agent = $this->ownedAgent(agentId: $agentId, uid: $uid);
		$data = $agent->getObject();
		if ($this->stamped(data: $data) === true) {
			throw new RuntimeException('This agent is already kept in git. Push your changes instead.', 409);
		}

		$result = $this->callGitHub(
			fn (): array => $this->push->push(
				package: $this->package(agentId: $agentId),
				owner: $githubOwner,
				repo: $repo,
				visibility: $visibility,
				credentialId: $credentialId,
				actingUserId: $uid,
				kind: GitHubTemplatePushService::KIND_AGENT_TEMPLATE
			)
		);

		$data['gitOwner'] = $githubOwner;
		$data['gitRepo'] = $repo;
		$this->save(agent: $agent, data: $data);

		return $result;
	}//end publish()

	/**
	 * Push the agent's current package to its stamped repository.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string $credentialId The caller's GitHub credential in the broker.
	 *
	 * @return array{repoUrl: string, commitSha: string}
	 *
	 * @throws RuntimeException 404, 403, 409 (not kept in git) or 502.
	 *
	 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
	 */
	public function push(string $agentId, string $uid, string $credentialId): array {
		$data = $this->stampedAgent(agentId: $agentId, uid: $uid)->getObject();

		return $this->callGitHub(
			fn (): array => $this->push->pushUpdate(
				package: $this->package(agentId: $agentId),
				owner: (string)$data['gitOwner'],
				repo: (string)$data['gitRepo'],
				credentialId: $credentialId,
				actingUserId: $uid,
				kind: GitHubTemplatePushService::KIND_AGENT_TEMPLATE
			)
		);
	}//end push()

	/**
	 * What a pull would change: the package's fields that differ from the agent.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string $credentialId The caller's GitHub credential in the broker.
	 *
	 * @return array{changes: array<int, array{field: string, from: mixed, to: mixed}>, scanReport: array<string, mixed>}
	 *
	 * @throws RuntimeException 404, 403, 409, 422 (dangerous scan) or 502.
	 *
	 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005
	 */
	public function pullPreview(string $agentId, string $uid, string $credentialId): array {
		$agent = $this->stampedAgent(agentId: $agentId, uid: $uid);
		[$fields, $scan] = $this->pulledFields(data: $agent->getObject(), uid: $uid, credentialId: $credentialId);

		return ['changes' => $this->changes(data: $agent->getObject(), fields: $fields), 'scanReport' => $scan];
	}//end pullPreview()

	/**
	 * Write the pulled fields onto the agent, a new version of the same agent.
	 *
	 * The package is fetched and scanned again: what the owner confirmed is the
	 * repository's current content, and a commit may have landed since the preview.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string $credentialId The caller's GitHub credential in the broker.
	 *
	 * @return ObjectEntity The saved agent.
	 *
	 * @throws RuntimeException 404, 403, 409, 422 (dangerous scan) or 502.
	 *
	 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005
	 */
	public function pullApply(string $agentId, string $uid, string $credentialId): ObjectEntity {
		$agent = $this->stampedAgent(agentId: $agentId, uid: $uid);
		$data = $agent->getObject();
		[$fields, , $hash] = $this->pulledFields(data: $data, uid: $uid, credentialId: $credentialId);

		foreach ($fields as $key => $value) {
			$data[$key] = $value;
		}

		$data['gitLastPulledHash'] = $hash;

		return $this->save(agent: $agent, data: $data);
	}//end pullApply()

	/**
	 * Fetch, scan and map the repository's package to agent fields.
	 *
	 * @param array<string, mixed> $data The agent's data (for its coordinates).
	 * @param string $uid The caller.
	 * @param string $credentialId The caller's GitHub credential.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string} [agent fields, scan report, package hash].
	 *
	 * @throws RuntimeException 422 on a dangerous verdict, 502 when the package cannot be read.
	 */
	private function pulledFields(array $data, string $uid, string $credentialId): array {
		$gitRef = (string)($data['gitRef'] ?? '');
		$package = $this->catalog->fetchPackageFile(
			kind: GitHubTemplateCatalogService::KIND_AGENT_TEMPLATE,
			owner: (string)$data['gitOwner'],
			repo: (string)$data['gitRepo'],
			ref: ($gitRef === '') ? null : $gitRef,
			actingUserId: $uid,
			credentialId: $credentialId
		);
		if ($package === null) {
			throw new RuntimeException('Could not read the agent from its repository.', 502);
		}

		$parsed = $this->serializer->fromPackage(package: $package);
		$scan = $this->contentScan->scan(content: (string)$parsed['systemPrompt'], metadata: []);
		if (($scan['severity'] ?? '') === ContentScanService::SEVERITY_DANGEROUS) {
			$reasons = array_map(static fn (array $finding): string => (string)($finding['reason'] ?? ''), ($scan['findings'] ?? []));
			throw new RuntimeException('The prompt in git was refused by the content scan: ' . implode('; ', $reasons), 422);
		}

		$fields = [];
		foreach (self::PULLED_FIELDS as $from => $to) {
			$fields[$to] = $parsed[$from];
		}

		return [$fields, $scan, hash('sha256', $package)];
	}//end pulledFields()

	/**
	 * The fields whose value differs.
	 *
	 * @param array<string, mixed> $data The agent's data.
	 * @param array<string, mixed> $fields The pulled fields.
	 *
	 * @return array<int, array{field: string, from: mixed, to: mixed}>
	 */
	private function changes(array $data, array $fields): array {
		$changes = [];
		foreach ($fields as $key => $value) {
			$current = ($data[$key] ?? '');
			if ($current !== $value) {
				$changes[] = ['field' => $key, 'from' => $current, 'to' => $value];
			}
		}

		return $changes;
	}//end changes()

	/**
	 * The agent, when the caller owns it.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 *
	 * @return ObjectEntity
	 *
	 * @throws RuntimeException 404 when unreadable, 403 when readable but not owned.
	 */
	private function ownedAgent(string $agentId, string $uid): ObjectEntity {
		$agent = $this->agentAccess->loadAccessibleAgent(agentId: $agentId, userId: $uid);
		if ($agent === null) {
			throw new RuntimeException('Agent not found', 404);
		}

		if ($this->agentAccess->canUserModifyAgent(agent: $agent, userId: $uid) === false) {
			throw new RuntimeException('Only the owner of this agent can keep it in git.', 403);
		}

		return $agent;
	}//end ownedAgent()

	/**
	 * The owned agent, when it is kept in git.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 *
	 * @return ObjectEntity
	 *
	 * @throws RuntimeException 404, 403, or 409 when no repository is stamped.
	 */
	private function stampedAgent(string $agentId, string $uid): ObjectEntity {
		$agent = $this->ownedAgent(agentId: $agentId, uid: $uid);
		if ($this->stamped(data: $agent->getObject()) === false) {
			throw new RuntimeException('This agent is not kept in git yet. Publish it first.', 409);
		}

		return $agent;
	}//end stampedAgent()

	/**
	 * Whether the agent carries repository coordinates.
	 *
	 * @param array<string, mixed> $data The agent's data.
	 *
	 * @return bool
	 */
	private function stamped(array $data): bool {
		return (string)($data['gitOwner'] ?? '') !== '' && (string)($data['gitRepo'] ?? '') !== '';
	}//end stamped()

	/**
	 * The agent's secret-free package.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return string
	 */
	private function package(string $agentId): string {
		return (string)$this->templates->exportFromAgent(agentId: $agentId);
	}//end package()

	/**
	 * Run a GitHub call, turning its failure into a 502 with GitHub's reason.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{repoUrl: string, commitSha: string}
	 *
	 * @throws RuntimeException 502.
	 */
	private function callGitHub(callable $call): array {
		try {
			return $call();
		} catch (RuntimeException $e) {
			throw new RuntimeException($e->getMessage(), 502, $e);
		}
	}//end callGitHub()

	/**
	 * Save the agent. The git properties are admin-only through the object API, so
	 * this write skips RBAC; the caller is the checked owner.
	 *
	 * @param ObjectEntity $agent The agent.
	 * @param array<string, mixed> $data The data to save.
	 *
	 * @return ObjectEntity
	 */
	private function save(ObjectEntity $agent, array $data): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: 'hermiq',
			schema: 'agent',
			uuid: (string)$agent->getUuid(),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()
}//end class
