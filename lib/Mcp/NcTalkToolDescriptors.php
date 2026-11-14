<?php

/**
 * Hermiq Talk message tool descriptor (tools-post-a-talk-message).
 *
 * One tool, "Talk-bericht plaatsen": an agent posts a message in a Talk
 * conversation its own bot is a member of. Held outside `HermiqToolProvider`
 * like the other descriptor lists; the provider stays the one
 * `IMcpToolProvider` and merges this in.
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
 * @spec openspec/changes/tools-post-a-talk-message/specs/nc-native-tools/spec.md#requirement-an-agent-can-post-a-message-in-a-talk-conversation-it-is-a-member-of-req-nctalk-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Descriptor source for the Talk message tool.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @spec openspec/changes/tools-post-a-talk-message/specs/nc-native-tools/spec.md#requirement-an-agent-can-post-a-message-in-a-talk-conversation-it-is-a-member-of-req-nctalk-001
 */
final class NcTalkToolDescriptors {

	/**
	 * The tool id (the spec's `talk.message.send`, in this app's id scheme).
	 *
	 * @var string
	 */
	public const SEND_MESSAGE = AppIdentity::APP_ID . '.sendTalkMessage';

	/**
	 * Every tool id this class describes.
	 *
	 * @var array<int, string>
	 */
	public const IDS = [self::SEND_MESSAGE];

	/**
	 * The descriptors, in catalogue order.
	 *
	 * `reach: instance` (medium risk): the message is read by the other members
	 * of the conversation, on this instance, and cannot be unsent by the agent.
	 * Like every write, the tool is default-denied until an agent is granted it.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public const ALL = [
		[
			'id' => self::SEND_MESSAGE,
			'subject' => 'talkMessage',
			'action' => 'create',
			'reach' => ToolReachResolver::REACH_INSTANCE,
			'name' => 'Post a Talk message',
			'description' => 'Posts a message in a Talk conversation, as this agent\'s own Talk bot. Only works in a '
				. 'conversation the agent\'s bot is a member of; every member of the conversation reads it.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'conversation' => ['type' => 'string', 'description' => 'The Talk conversation token.'],
					'message' => ['type' => 'string', 'description' => 'The message, markdown.'],
				],
				'required' => ['conversation', 'message'],
			],
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => false,
			'scope' => 'create',
		],
	];

}//end class
