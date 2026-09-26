<?php

/**
 * Hermiq IntakeController.
 *
 * The conversational intake surface: a message arrives from whichever app owns the
 * channel, and the conversation either ends in a filed request or in front of a
 * person. hermiq transports nothing itself, so every message reaches this controller
 * through the app that owns the channel it came in on.
 *
 * The surface is deliberately separate from the case assistant, which stays
 * tool-free. A chat box on a case must not be able to act on the case; a citizen with
 * no case needs one filed. Those are different problems and they get different
 * surfaces.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-an-intake-conversation-must-be-able-to-file-on-its-own-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Intake\IntakeRefusedException;
use OCA\Hermiq\Service\Intake\IntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives intake messages, concludes conversations and carries verdicts.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md
 */
class IntakeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IntakeService $intake The intake conversation.
	 * @param IUserSession $userSession Resolves the calling app's principal.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IntakeService $intake,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Take one message into the person's open conversation about a subject.
	 *
	 * @return JSONResponse The conversation as it now stands.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-starting-by-e-mail-and-continuing-in-the-portal
	 */
	public function receive(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$person = trim((string)$this->request->getParam('person', ''));
		$subject = trim((string)$this->request->getParam('subject', ''));

		if ($person === '' || $subject === '') {
			return new JSONResponse(
				['error' => 'An intake message needs the person and the subject: together they are the conversation.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$conversation = $this->intake->receive(
				person: $person,
				subject: $subject,
				channel: trim((string)$this->request->getParam('channel', 'portal')),
				text: (string)$this->request->getParam('text', '')
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq intake receive failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'The message could not be taken in'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($conversation);
	}//end receive()

	/**
	 * Read one conversation.
	 *
	 * @param string $conversationId The conversation uuid.
	 *
	 * @return JSONResponse The conversation.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-no-conversation-must-dead-end
	 */
	public function show(string $conversationId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$conversation = $this->intake->conversation(conversationId: $conversationId);
		if ($conversation === null) {
			return new JSONResponse(['error' => 'Conversation not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($conversation);
	}//end show()

	/**
	 * End one conversation: file it, or hand it to a person.
	 *
	 * @param string $conversationId The conversation uuid.
	 *
	 * @return JSONResponse The conversation in its terminal state.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	public function conclude(string $conversationId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$classification = $this->request->getParam('classification', []);
		$catalogue = $this->request->getParam('catalogue', []);
		$intakeTool = trim((string)$this->request->getParam('intakeTool', ''));

		if (is_array($classification) === false || is_array($catalogue) === false || $intakeTool === '') {
			return new JSONResponse(
				['error' => 'Concluding an intake needs a classification, the catalogue it came from, and the intake tool to file through.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$conversation = $this->intake->conclude(
				conversationId: $conversationId,
				classification: $classification,
				catalogue: $catalogue,
				intakeTool: $intakeTool
			);
		} catch (IntakeRefusedException $refusal) {
			return new JSONResponse(['error' => $refusal->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq intake conclude failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'The conversation could not be concluded'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($conversation === null) {
			return new JSONResponse(['error' => 'Conversation not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($conversation);
	}//end conclude()

	/**
	 * Carry an external party's verdict into a conversation.
	 *
	 * @param string $conversationId The conversation uuid.
	 *
	 * @return JSONResponse The conversation with the verdict on it.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-a-verdict-travels-into-the-conversation-with-its-author
	 */
	public function review(string $conversationId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$toolId = trim((string)$this->request->getParam('tool', ''));
		$arguments = $this->request->getParam('arguments', []);
		if (is_array($arguments) === false) {
			$arguments = [];
		}

		try {
			$conversation = $this->intake->review(
				conversationId: $conversationId,
				toolId: $toolId,
				arguments: $arguments
			);
		} catch (IntakeRefusedException $refusal) {
			return new JSONResponse(['error' => $refusal->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq intake review failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'The review could not be carried out'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($conversation === null) {
			return new JSONResponse(['error' => 'Conversation not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($conversation);
	}//end review()
}//end class
