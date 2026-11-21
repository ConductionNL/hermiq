<?php

/**
 * Hermiq SessionWorkspaceKey.
 *
 * The key a conversation-long workspace is stored under, and how to read the
 * conversation back out of it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * Composes and reads `session:<agent>:<conversation>:<user>` keys.
 *
 * All three parts come from the verified run token. The user goes last: a
 * Nextcloud user id cannot hold a colon, and neither can the two uuids, so the
 * parts read back unambiguously.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
 */
final class SessionWorkspaceKey {

	/**
	 * The `Agent.workspaceMode` value that keeps one workspace per conversation.
	 *
	 * @var string
	 */
	public const MODE_SESSION = 'session';

	/**
	 * The prefix every session key starts with.
	 *
	 * @var string
	 */
	private const PREFIX = 'session:';

	/**
	 * The key for one agent, conversation and user, or null when a part is missing.
	 *
	 * A part that is empty or holds a colon gives no key: a session workspace
	 * that could be shared by accident is worse than a run workspace.
	 *
	 * @param string $agentId        The agent.
	 * @param string $conversationId The conversation.
	 * @param string $userId         The user.
	 *
	 * @return string|null The key, or null.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
	 */
	public static function compose(string $agentId, string $conversationId, string $userId): ?string {
		foreach ([$agentId, $conversationId, $userId] as $part) {
			if (trim($part) === '' || str_contains($part, ':') === true) {
				return null;
			}
		}

		return self::PREFIX . $agentId . ':' . $conversationId . ':' . $userId;
	}//end compose()

	/**
	 * Whether a workspace key is a session key.
	 *
	 * @param string $key The workspace key.
	 *
	 * @return bool True for a session key.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
	 */
	public static function isSession(string $key): bool {
		return self::conversationOf(key: $key) !== null;
	}//end isSession()

	/**
	 * The conversation a session key belongs to, or null for any other key.
	 *
	 * @param string $key The workspace key.
	 *
	 * @return string|null The conversation id.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
	 */
	public static function conversationOf(string $key): ?string {
		if (str_starts_with($key, self::PREFIX) === false) {
			return null;
		}

		$parts = explode(':', substr($key, strlen(self::PREFIX)));
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return null;
		}

		return $parts[1];
	}//end conversationOf()
}//end class
