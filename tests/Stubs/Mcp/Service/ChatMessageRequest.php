<?php

/**
 * Test stub for OpenRegister ChatMessageRequest.
 *
 * Stands in for OCA\OpenRegister\Mcp\Service\ChatMessageRequest when OpenRegister
 * is not installed (standalone CI), with the real constructor and option fields.
 *
 * @category Test
 * @package  OCA\OpenRegister\Mcp\Service
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
 * One chat message to process, with its conversation, user and options.
 */
final class ChatMessageRequest {

	/**
	 * View filters.
	 *
	 * @var array<int, mixed>
	 */
	public array $selectedViews = [];

	/**
	 * Tool UUIDs.
	 *
	 * @var array<int, mixed>
	 */
	public array $selectedTools = [];

	/**
	 * RAG overrides.
	 *
	 * @var array<string, mixed>
	 */
	public array $ragSettings = [];

	/**
	 * CnAiContext snapshot.
	 *
	 * @var array<string, mixed>
	 */
	public array $cnAiContext = [];

	/**
	 * Constructor.
	 *
	 * @param int $conversationId The conversation id.
	 * @param string $userId The acting user id.
	 * @param string $userMessage The prompt.
	 */
	public function __construct(
		public readonly int $conversationId,
		public readonly string $userId,
		public readonly string $userMessage,
	) {
	}//end __construct()
}//end class
