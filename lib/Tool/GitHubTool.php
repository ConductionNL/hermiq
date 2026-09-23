<?php

/**
 * The forge, as tools an agent may call.
 *
 * Hermiq already speaks MCP: `McpRunController` re-exposes whatever OpenRegister's
 * tool registry holds, scoped to one agent's grants. So registering a tool here is
 * the whole of "Hermiq provides a GitHub MCP to the Assistant" — the Assistant's
 * ExApp points at `/apps/hermiq/api/mcp/run` and these functions appear in its
 * `tools/list`.
 *
 * THE TOKEN NEVER ENTERS HERMIQ. Every call goes through OpenRegister's
 * `CredentialBrokerService`, which holds the secret, pins the host, and checks the
 * method and path against the `github` provider's allow-rules before it dials. That
 * is not politeness: an agent chooses which function to call and with what
 * arguments, so the only durable limit on what it can do to a repository is one the
 * agent cannot reach. A raw PAT in app config would make every function on this
 * class as powerful as the token, including the ones nobody wrote.
 *
 * WHY EACH FUNCTION IS NARROW. `github_put_file` takes a path and content rather
 * than a ref-spec, `github_create_branch` takes a base and a name rather than a
 * SHA. The model supplies prose well and identifiers badly, so anything the code
 * can resolve for itself — a base branch's head SHA, an existing file's blob SHA
 * for an update — this class resolves rather than asks for. A tool that asks a
 * language model for a SHA gets a plausible one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tool
 * @package  OCA\Hermiq\Tool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tool;

use OCA\Hermiq\Service\GitHubBroker;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Tool\ToolInterface;

/**
 * GitHub, as an agent-callable tool.
 */
class GitHubTool implements ToolInterface {

	/**
	 * Constructor.
	 *
	 * @param GitHubBroker $broker The brokered GitHub client.
	 */
	public function __construct(
		private readonly GitHubBroker $broker,
	) {
	}//end __construct()

	/**
	 * The registry's display name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'GitHub';
	}//end getName()

	/**
	 * What the tool is for, in one line.
	 *
	 * @return string
	 */
	public function getDescription(): string {
		return 'Read and write issues, branches, files and pull requests on GitHub, through a brokered credential.';
	}//end getDescription()

	/**
	 * Receive the agent whose grants admitted this tool.
	 *
	 * Deliberately a no-op. `ToolInterface` requires the setter, and every
	 * function here is already scoped twice over without knowing which agent is
	 * calling: the registry hands the tool over only after resolving that agent's
	 * grants, and every call then goes through the credential broker's own
	 * host-lock and allow-rules. Storing the agent would add a field nothing
	 * reads, which phpstan correctly reported as write-only, and a field nothing
	 * reads is a standing invitation for a later change to start scoping here
	 * instead of where the scoping actually happens.
	 *
	 * @param Agent|null $agent The agent, or null outside an agent run.
	 *
	 * @return void
	 */
	public function setAgent(?Agent $agent): void {
	}//end setAgent()

	/**
	 * The callable surface.
	 *
	 * Every function takes `repo` as `owner/name` rather than reading a configured
	 * default, because a run that silently targets the wrong repository writes real
	 * artefacts into it and there is no undo for an opened issue.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getFunctions(): array {
		$repo = [
			'type' => 'string',
			'description' => 'Repository as owner/name, for example ConductionNL/planninq.',
		];

		return [
			[
				'name' => 'github_create_issue',
				'subject' => 'issue',
				'action' => 'create',
				'description' => 'Open an issue. Returns its number and html_url.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'title' => ['type' => 'string', 'description' => 'Issue title.'],
						'body' => ['type' => 'string', 'description' => 'Issue body in markdown.'],
						'labels' => [
							'type' => 'array',
							'description' => 'Label names to apply.',
							'items' => ['type' => 'string'],
						],
					],
					'required' => ['repo', 'title', 'body'],
				],
			],
			[
				'name' => 'github_get_issue',
				'subject' => 'issue',
				'action' => 'read',
				'description' => 'Read one issue: title, body, state and the names of its labels.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'number' => ['type' => 'integer', 'description' => 'Issue number.'],
					],
					'required' => ['repo', 'number'],
				],
			],
			[
				'name' => 'github_comment_issue',
				'subject' => 'issue',
				'action' => 'update',
				'description' => 'Add a comment to an issue or pull request.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'number' => ['type' => 'integer', 'description' => 'Issue or pull request number.'],
						'body' => ['type' => 'string', 'description' => 'Comment body in markdown.'],
					],
					'required' => ['repo', 'number', 'body'],
				],
			],
			[
				'name' => 'github_create_branch',
				'subject' => 'branch',
				'action' => 'create',
				'description' => 'Branch from another branch. The base head SHA is resolved here; do not supply one.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'name' => ['type' => 'string', 'description' => 'New branch name, without refs/heads/.'],
						'base' => ['type' => 'string', 'description' => 'Branch to start from. Defaults to development.'],
					],
					'required' => ['repo', 'name'],
				],
			],
			[
				'name' => 'github_get_file',
				'subject' => 'file',
				'action' => 'read',
				'description' => 'Read one text file at a ref. A large file comes back cut, with truncated true and '
					. 'totalBytes saying how big it really is. Read the rest by calling again with offset set past '
					. 'what you already have. Never rewrite a file you have only seen part of.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'path' => ['type' => 'string', 'description' => 'Path from the repository root.'],
						'ref' => ['type' => 'string', 'description' => 'Branch, tag or SHA. Defaults to development.'],
						'offset' => [
							'type' => 'integer',
							'description' => 'Byte to start reading from. Use it to continue past a truncated read.',
						],
					],
					'required' => ['repo', 'path'],
				],
			],
			[
				'name' => 'github_list_files',
				'subject' => 'file',
				'action' => 'list',
				'description' => 'List the entries of one directory at a ref.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'path' => ['type' => 'string', 'description' => 'Directory from the repository root. Empty for the root.'],
						'ref' => ['type' => 'string', 'description' => 'Branch, tag or SHA. Defaults to development.'],
					],
					'required' => ['repo'],
				],
			],
			[
				'name' => 'github_put_file',
				'subject' => 'file',
				'action' => 'update',
				'description' => 'Create or replace one text file on a branch and commit it. An existing file is updated; its blob SHA is resolved here.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'branch' => ['type' => 'string', 'description' => 'Branch to commit on. It must already exist.'],
						'path' => ['type' => 'string', 'description' => 'Path from the repository root.'],
						'content' => ['type' => 'string', 'description' => 'The file content, as plain text.'],
						'message' => ['type' => 'string', 'description' => 'Commit message.'],
					],
					'required' => ['repo', 'branch', 'path', 'content', 'message'],
				],
			],
			[
				'name' => 'github_create_pull_request',
				'subject' => 'pull-request',
				'action' => 'create',
				'description' => 'Open a pull request. Leave draft true unless a person asked for it to be ready for review.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'head' => ['type' => 'string', 'description' => 'Branch holding the changes.'],
						'base' => ['type' => 'string', 'description' => 'Branch to merge into. Defaults to development.'],
						'title' => ['type' => 'string', 'description' => 'Pull request title.'],
						'body' => ['type' => 'string', 'description' => 'Pull request body in markdown.'],
						'draft' => ['type' => 'boolean', 'description' => 'Open as a draft. Defaults to true.'],
					],
					'required' => ['repo', 'head', 'title', 'body'],
				],
			],
			[
				'name' => 'github_compare',
				'subject' => 'pull-request',
				'action' => 'read',
				'description' => 'Compare two refs. Returns each changed file with its patch, which is what a review reads.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'repo' => $repo,
						'base' => ['type' => 'string', 'description' => 'Ref to compare from. Defaults to development.'],
						'head' => ['type' => 'string', 'description' => 'Ref to compare to.'],
					],
					'required' => ['repo', 'head'],
				],
			],
		];
	}//end getFunctions()

	/**
	 * Dispatch one call.
	 *
	 * Returns a result array rather than throwing, because the caller is a tool loop
	 * feeding the answer back to a model: an exception ends the turn, whereas
	 * `['success' => false, 'error' => ...]` lets the model read what went wrong and
	 * try something else. A 404 on a branch it invented is a correctable mistake.
	 *
	 * @param string $functionName The function to call.
	 * @param array<array-key,mixed> $parameters Its arguments, keyed by parameter name.
	 * @param string|null $userId The acting user, for the broker's owner guard.
	 *
	 * @return array<string,mixed>
	 */
	public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array {
		$repo = trim((string)($parameters['repo'] ?? ''));
		if ($repo === '') {
			return ['success' => false, 'error' => 'repo is required, as owner/name.'];
		}

		try {
			return match ($functionName) {
				'github_create_issue' => $this->broker->createIssue(
					repo: $repo,
					title: (string)($parameters['title'] ?? ''),
					body: (string)($parameters['body'] ?? ''),
					labels: $this->stringList(value: ($parameters['labels'] ?? [])),
					userId: $userId
				),
				'github_get_issue' => $this->broker->getIssue(
					repo: $repo,
					number: (int)($parameters['number'] ?? 0),
					userId: $userId
				),
				'github_comment_issue' => $this->broker->commentIssue(
					repo: $repo,
					number: (int)($parameters['number'] ?? 0),
					body: (string)($parameters['body'] ?? ''),
					userId: $userId
				),
				'github_create_branch' => $this->broker->createBranch(
					repo: $repo,
					name: (string)($parameters['name'] ?? ''),
					base: (string)($parameters['base'] ?? 'development'),
					userId: $userId
				),
				'github_get_file' => $this->broker->getFile(
					repo: $repo,
					path: (string)($parameters['path'] ?? ''),
					ref: (string)($parameters['ref'] ?? 'development'),
					userId: $userId,
					offset: (int)($parameters['offset'] ?? 0)
				),
				'github_list_files' => $this->broker->listFiles(
					repo: $repo,
					path: (string)($parameters['path'] ?? ''),
					ref: (string)($parameters['ref'] ?? 'development'),
					userId: $userId
				),
				'github_put_file' => $this->broker->putFile(
					repo: $repo,
					branch: (string)($parameters['branch'] ?? ''),
					path: (string)($parameters['path'] ?? ''),
					content: (string)($parameters['content'] ?? ''),
					message: (string)($parameters['message'] ?? ''),
					userId: $userId
				),
				'github_create_pull_request' => $this->broker->createPullRequest(
					repo: $repo,
					head: (string)($parameters['head'] ?? ''),
					base: (string)($parameters['base'] ?? 'development'),
					title: (string)($parameters['title'] ?? ''),
					body: (string)($parameters['body'] ?? ''),
					draft: ((bool)($parameters['draft'] ?? true)),
					userId: $userId
				),
				'github_compare' => $this->broker->compare(
					repo: $repo,
					base: (string)($parameters['base'] ?? 'development'),
					head: (string)($parameters['head'] ?? ''),
					userId: $userId
				),
				default => ['success' => false, 'error' => "Unknown function '{$functionName}'."],
			};
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}//end try
	}//end executeFunction()

	/**
	 * Coerce a labels argument into a list of non-empty strings.
	 *
	 * Models pass a bare string as often as a list, and a JSON-encoded list about as
	 * often as either, so all three are accepted rather than rejected.
	 *
	 * @param mixed $value Whatever arrived.
	 *
	 * @return array<int,string>
	 */
	private function stringList(mixed $value): array {
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
			$parsed = [$value];
			if (is_array($decoded) === true) {
				$parsed = $decoded;
			}

			$value = $parsed;
		}

		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			$entry = trim((string)$entry);
			if ($entry !== '') {
				$out[] = $entry;
			}
		}

		return $out;
	}//end stringList()
}//end class
