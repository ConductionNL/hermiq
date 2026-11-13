<?php

/**
 * Hermiq TurnHandlers
 *
 * The handlers one engine turn passes through: context, response, conversation, history and context assembly.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * The handlers one engine turn passes through: context, response, conversation, history and context assembly.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class TurnHandlers {

	/**
	 * Constructor.
	 *
	 * @param ContextRetrievalHandler $contextHandler RAG context handler.
	 * @param ResponseGenerationHandler $responseHandler LLM response handler.
	 * @param ConversationManagementHandler $conversationHandler Title/summary handler.
	 * @param MessageHistoryHandler $historyHandler Message storage/history handler.
	 * @param ContextAssembler $contextAssembler Resolves an agent's attached
	 *                                           Context objects into a
	 *                                           system-prompt preamble
	 *                                           (agent-context-system).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ContextRetrievalHandler $contextHandler,
		public readonly ResponseGenerationHandler $responseHandler,
		public readonly ConversationManagementHandler $conversationHandler,
		public readonly MessageHistoryHandler $historyHandler,
		public readonly ContextAssembler $contextAssembler,
	) {
	}//end __construct()
}//end class
