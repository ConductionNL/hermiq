<?php

/**
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
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

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Workspace\SessionWorkspaceKey;
use PHPUnit\Framework\TestCase;

/**
 * The key a conversation-long workspace is stored under.
 *
 * @covers \OCA\Hermiq\Service\Workspace\SessionWorkspaceKey
 */
final class SessionWorkspaceKeyTest extends TestCase {

	public function testTheKeyNamesAgentConversationAndUserAndReadsBack(): void {
		$key = SessionWorkspaceKey::compose(agentId: 'agent-1', conversationId: 'conv-1', userId: 'alice');

		self::assertSame('session:agent-1:conv-1:alice', $key);
		self::assertTrue(SessionWorkspaceKey::isSession(key: (string)$key));
		self::assertSame('conv-1', SessionWorkspaceKey::conversationOf(key: (string)$key));
	}//end testTheKeyNamesAgentConversationAndUserAndReadsBack()

	public function testEachPartChangesTheKey(): void {
		$base = SessionWorkspaceKey::compose(agentId: 'agent-1', conversationId: 'conv-1', userId: 'alice');

		self::assertNotSame($base, SessionWorkspaceKey::compose(agentId: 'agent-2', conversationId: 'conv-1', userId: 'alice'));
		self::assertNotSame($base, SessionWorkspaceKey::compose(agentId: 'agent-1', conversationId: 'conv-2', userId: 'alice'));
		self::assertNotSame($base, SessionWorkspaceKey::compose(agentId: 'agent-1', conversationId: 'conv-1', userId: 'bob'));
	}//end testEachPartChangesTheKey()

	public function testAMissingOrAmbiguousPartGivesNoKey(): void {
		self::assertNull(SessionWorkspaceKey::compose(agentId: 'agent-1', conversationId: '', userId: 'alice'));
		self::assertNull(SessionWorkspaceKey::compose(agentId: ' ', conversationId: 'conv-1', userId: 'alice'));
		self::assertNull(SessionWorkspaceKey::compose(agentId: 'agent-1', conversationId: 'conv-1', userId: ''));
		self::assertNull(SessionWorkspaceKey::compose(agentId: 'agent-1', conversationId: 'conv:1', userId: 'alice'));
	}//end testAMissingOrAmbiguousPartGivesNoKey()

	public function testARunKeyIsNoSessionKey(): void {
		foreach (['run-7', 'session:', 'session:a:b', 'session:a::c', 'session:a:b:c:d'] as $key) {
			self::assertFalse(SessionWorkspaceKey::isSession(key: $key), $key);
			self::assertNull(SessionWorkspaceKey::conversationOf(key: $key), $key);
		}
	}//end testARunKeyIsNoSessionKey()
}//end class
