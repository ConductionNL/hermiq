<?php

/**
 * Hermiq ChatRefusalFrame.
 *
 * The error frame a chat turn ends with when it fails for a reason the person
 * can act on: a refused attachment, a refused pinned credential, a switched-off
 * agent, a required AI literacy course, or an organisation's data-use rule.
 * Any other failure gets no frame here and stays masked by the caller.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCA\Hermiq\Service\Agent\AgentSwitchedOffException;
use OCA\Hermiq\Service\AiFeature\DataUseViolationException;
use OCA\Hermiq\Service\Credential\PinnedCredentialRefusedException;
use OCA\Hermiq\Service\Literacy\LiteracyRequiredException;
use OCP\IL10N;
use Throwable;

/**
 * Maps one cause of a failed chat turn to the error frame the person reads.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
 */
class ChatRefusalFrame {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the sentences the person reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The error frame for a cause the person can act on, or null to mask it.
	 *
	 * @param Throwable $cause One exception in the failure's chain.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	public function forCause(Throwable $cause): ?array {
		// An attachment that is not available to the speaker, or that the
		// feature refuses unredacted: the sentence is written for the person.
		if ($cause instanceof AttachmentRefusedException) {
			return $this->frame(code: 'attachment_refused', message: $cause->getMessage());
		}

		return match (true) {
			// A refused pinned credential (operations-a-credential-per-agent).
			$cause instanceof PinnedCredentialRefusedException => $this->frame(
				code: PinnedCredentialRefusedException::ERROR_CODE,
				message: $this->l10n->t('The credential pinned to this agent cannot be used for this run.')
			),
			// Agents-switch-off-and-stop: the agent is switched off; no answer.
			$cause instanceof AgentSwitchedOffException => $this->frame(
				code: AgentSwitchedOffException::ERROR_CODE,
				message: $this->l10n->t('This agent is switched off.')
			),
			// Compliance-ai-literacy: the person is sent to the course.
			$cause instanceof LiteracyRequiredException => $this->frame(
				code: LiteracyRequiredException::ERROR_CODE,
				message: $this->l10n->t('Finish the short course Working with AI first.')
			) + ['courseUrl' => LiteracyRequiredException::COURSE_PATH],
			// Models-no-training-guarantee: the person reads why, not the step text.
			$cause instanceof DataUseViolationException => $this->frame(
				code: DataUseViolationException::ERROR_CODE,
				message: $this->l10n->t('This assistant cannot answer: your organisation only allows AI providers that never train on its data.')
			),
			// Chat-about-a-document: the scope is malformed or cannot be opened.
			$cause instanceof TurnScopeException => $this->frame(
				code: $cause->errorCode(),
				message: $this->scopeMessage(cause: $cause)
			),
			default => null,
		};
	}//end forCause()

	/**
	 * The sentence for a refused scope.
	 *
	 * @param TurnScopeException $cause The refusal.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	private function scopeMessage(TurnScopeException $cause): string {
		if ($cause->errorCode() === TurnScopeException::NOT_FOUND) {
			return $this->l10n->t('The document or folder this question is about cannot be opened.');
		}

		return $this->l10n->t('This question names its document or folder in a way hermiq does not understand.');
	}//end scopeMessage()

	/**
	 * One error frame.
	 *
	 * @param string $code    The stable error code.
	 * @param string $message The sentence the person reads.
	 *
	 * @return array{code: string, message: string}
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-2
	 */
	private function frame(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end frame()
}//end class
