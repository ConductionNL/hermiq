<?php

/**
 * Hermiq TalkMessageTool (tools-post-a-talk-message).
 *
 * Posts an agent's message in a Talk conversation under the agent's own bot,
 * and only in a conversation that bot is a member of. The agent is the one the
 * run injected (`agentId`), never a value the model supplies: without a run
 * there is no agent and so no bot to post as. Talk missing is an error the
 * agent can read, never an exception, so the turn carries on.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
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

namespace OCA\Hermiq\Service\NcNative;

use OCA\Hermiq\Kernel\Contracts\TalkMessageToolInterface;
use OCA\Hermiq\Mcp\NcTalkToolDescriptors;
use OCA\Hermiq\Mcp\ToolErrorEnvelope;
use OCA\Hermiq\Service\Talk\TalkBridge;
use Psr\Log\LoggerInterface;

/**
 * The Talk message tool.
 *
 * @spec openspec/changes/tools-post-a-talk-message/specs/nc-native-tools/spec.md#requirement-an-agent-can-post-a-message-in-a-talk-conversation-it-is-a-member-of-req-nctalk-001
 */
class TalkMessageTool implements TalkMessageToolInterface {

	/**
	 * Constructor.
	 *
	 * @param TalkBridge      $bridge Talk availability, bot membership and the post itself.
	 * @param LoggerInterface $logger Records each post by room and agent, never its text.
	 */
	public function __construct(
		private readonly TalkBridge $bridge,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Post the message, or answer why not.
	 *
	 * @param string               $uid       The acting user's uid.
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The arguments, with the run-injected `agentId`.
	 *
	 * @return array<string, mixed> `{posted, conversation, messageId}`, or an error envelope.
	 *
	 * @spec openspec/changes/tools-post-a-talk-message/specs/nc-native-tools/spec.md#requirement-an-agent-can-post-a-message-in-a-talk-conversation-it-is-a-member-of-req-nctalk-001
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		$errors = new ToolErrorEnvelope();
		if ($toolId !== NcTalkToolDescriptors::SEND_MESSAGE) {
			return $errors->build(code: 'unknown_tool', message: "Unknown tool id '" . $toolId . "'.");
		}

		$agentId = trim((string)($arguments['agentId'] ?? ''));
		if ($agentId === '') {
			return $errors->noAgentContext();
		}

		if ($this->bridge->isAvailable() === false) {
			return $errors->build(code: 'talk_unavailable', message: 'Talk is not installed on this instance, so no message was posted.');
		}

		$conversation = trim((string)($arguments['conversation'] ?? ''));
		$message      = (string)($arguments['message'] ?? '');
		if ($conversation === '' || trim($message) === '') {
			return $errors->build(code: 'invalid_arguments', message: 'A conversation and a message are both needed.');
		}

		if ($this->bridge->isAgentBotIn(roomToken: $conversation, agentId: $agentId) === false) {
			return $errors->build(
				code: 'not_a_member',
				message: 'This agent is not a member of that conversation, so nothing was posted.'
			);
		}

		$messageId = $this->bridge->postToRoomReturningId(roomToken: $conversation, message: $message, agentId: $agentId);
		if ($messageId === null) {
			return $errors->build(code: 'post_failed', message: 'Talk did not accept the message.');
		}

		$this->logger->info(
			message: '[TalkMessageTool] An agent posted a Talk message',
			context: ['roomToken' => $conversation, 'agentId' => $agentId, 'uid' => $uid, 'messageId' => $messageId]
		);

		return ['posted' => true, 'conversation' => $conversation, 'messageId' => $messageId];
	}//end invoke()
}//end class
