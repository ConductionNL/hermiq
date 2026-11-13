<?php

/**
 * Test stub for OpenRegister ChatService.
 *
 * Stands in for OCA\OpenRegister\Mcp\Service\ChatService when OpenRegister is not
 * installed (standalone CI). Exposes only processMessage, the agent-runtime entry
 * point ScheduleService invokes. The real service ships with OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service;

/**
 * Minimal ChatService stub for standalone unit runs.
 */
class ChatService {

	/**
	 * Process a chat message against a conversation's bound agent.
	 *
	 * @param ChatMessageRequest $request The message, its conversation and user, and its options.
	 *
	 * @return array<string,mixed>
	 */
	public function processMessage(ChatMessageRequest $request): array {
		return ['message' => ''];
	}//end processMessage()
}//end class
