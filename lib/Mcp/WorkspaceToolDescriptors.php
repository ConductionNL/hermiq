<?php

/**
 * Hermiq workspace tool descriptors (hermiq-runner-git-capability).
 *
 * The governed workspace and git surface, as MCP tool descriptors merged into
 * `HermiqToolProvider`'s catalogue. The surface is closed and named: no tool
 * takes a command string, a subcommand, a refspec or a remote URL, and there is
 * no merge, rebase, reset, tag, force push or ref deletion to reach. The
 * contract's `workspace.*` names are carried as `hermiq.workspace*` ids, because
 * every tool id in the registry is namespaced by the app that provides it.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Descriptor source for the workspace tools.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface
 */
final class WorkspaceToolDescriptors {

	public const OPEN = Application::APP_ID . '.workspaceOpen';

	public const STATUS = Application::APP_ID . '.workspaceStatus';

	public const DIFF = Application::APP_ID . '.workspaceDiff';

	public const LOG = Application::APP_ID . '.workspaceLog';

	public const LIST_FILES = Application::APP_ID . '.workspaceListFiles';

	public const READ_FILE = Application::APP_ID . '.workspaceReadFile';

	/**
	 * Every workspace tool id.
	 *
	 * @var array<int, string>
	 */
	public const IDS = [self::OPEN, self::STATUS, self::DIFF, self::LOG, self::LIST_FILES, self::READ_FILE];

	/**
	 * The descriptors, in catalogue order.
	 *
	 * `open` is write-shaped (it creates the run's checkout) though it changes
	 * nothing anyone owns; the inspection and read tools are read-only.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public const ALL = [
		[
			'id' => self::OPEN,
			'subject' => 'workspace',
			'action' => 'open',
			'reach' => ToolReachResolver::REACH_EXTERNAL,
			'name' => 'Open a workspace',
			'description' => 'Check out a repository for this run and return its workspace id and head commit. '
				. 'Name the repository as owner/name; the forge host is set by the administrator.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'repository' => ['type' => 'string', 'description' => 'The repository as owner/name, never a URL.'],
					'ref' => ['type' => 'string', 'description' => 'The branch or tag to start from.'],
					'depth' => ['type' => 'integer', 'description' => 'How many commits of history to fetch (default 20).'],
				],
				'required' => ['repository', 'ref'],
			],
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'create',
		],
		[
			'id' => self::STATUS,
			'subject' => 'workspace',
			'action' => 'status',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Workspace status',
			'description' => 'Show the current branch and the changed, added and deleted files in this run\'s workspace.',
			'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
		[
			'id' => self::DIFF,
			'subject' => 'workspace',
			'action' => 'diff',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Workspace diff',
			'description' => 'Show the uncommitted changes in this run\'s workspace, optionally for one path.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'path' => ['type' => 'string', 'description' => 'A path relative to the repository root (optional).'],
					'staged' => ['type' => 'boolean', 'description' => 'Show staged changes instead of unstaged ones.'],
				],
				'required' => [],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
		[
			'id' => self::LOG,
			'subject' => 'workspace',
			'action' => 'log',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Workspace log',
			'description' => 'List the latest commits on the current branch of this run\'s workspace.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => ['limit' => ['type' => 'integer', 'description' => 'How many commits (default 20, at most 100).']],
				'required' => [],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
		[
			'id' => self::LIST_FILES,
			'subject' => 'workspace',
			'action' => 'list',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'List workspace files',
			'description' => 'List the files in this run\'s workspace, optionally under one folder.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => ['path' => ['type' => 'string', 'description' => 'A folder relative to the repository root (optional).']],
				'required' => [],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
		[
			'id' => self::READ_FILE,
			'subject' => 'workspace',
			'action' => 'get',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Read a workspace file',
			'description' => 'Read a text file in this run\'s workspace (size-capped).',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'path' => ['type' => 'string', 'description' => 'A path relative to the repository root.'],
					'maxBytes' => ['type' => 'integer', 'description' => 'Read at most this many bytes (default 262144).'],
				],
				'required' => ['path'],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
	];
}//end class
