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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 */
class ChatRefusalFrame {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the sentences the person reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
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
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
	 */
	public function forCause(Throwable $cause): ?array {
		// An attachment that is not available to the speaker, or that the
		// feature refuses unredacted: the sentence is written for the person.
		if ($cause instanceof AttachmentRefusedException) {
			return ['code' => 'attachment_refused', 'message' => $cause->getMessage()];
		}

		// A refused pinned credential (operations-a-credential-per-agent).
		if ($cause instanceof PinnedCredentialRefusedException) {
			return [
				'code' => PinnedCredentialRefusedException::ERROR_CODE,
				'message' => $this->l10n->t('The credential pinned to this agent cannot be used for this run.'),
			];
		}

		// Agents-switch-off-and-stop: the agent is switched off; no answer.
		if ($cause instanceof AgentSwitchedOffException) {
			return [
				'code' => AgentSwitchedOffException::ERROR_CODE,
				'message' => $this->l10n->t('This agent is switched off.'),
			];
		}

		// Compliance-ai-literacy: the person is sent to the course.
		if ($cause instanceof LiteracyRequiredException) {
			return [
				'code' => LiteracyRequiredException::ERROR_CODE,
				'message' => $this->l10n->t('Finish the short course Working with AI first.'),
				'courseUrl' => LiteracyRequiredException::COURSE_PATH,
			];
		}

		// Models-no-training-guarantee: the person reads why, not the step text.
		if ($cause instanceof DataUseViolationException) {
			return [
				'code' => DataUseViolationException::ERROR_CODE,
				'message' => $this->l10n->t('This assistant cannot answer: your organisation only allows AI providers that never train on its data.'),
			];
		}

		return null;
	}//end forCause()
}//end class
