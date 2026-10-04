<?php

/**
 * Hermiq ChatImageController.
 *
 * The chat action "Create an image" (chat-attachments-and-images D7).
 * `POST /api/chat/images` takes `{ sessionId, prompt }` from the owner or a
 * listed participant of the session, creates the image through
 * ImageGenerationService, and stores two turns: the description as the person's
 * turn and the image as the answer, an attachment with origin `generated`.
 * `GET /api/chat/images/availability` tells the Chat page whether to show the
 * action at all.
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
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Service\Chat\ImageGenerationException;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates an image from the chat and stores it as the answer.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004
 */
class ChatImageController extends Controller {

	/**
	 * The HTTP status per refusal code; anything else is 502.
	 *
	 * @var array<string, int>
	 */
	private const STATUS_FOR = [
		'invalid_prompt' => 400,
		'feature_not_enabled' => 409,
		'provider_unavailable' => 503,
	];

	/**
	 * Constructor.
	 *
	 * @param string                    $appName       The app id.
	 * @param IRequest                  $request       The request.
	 * @param IUserSession              $userSession   The signed-in person.
	 * @param ImageGenerationService    $images        Creates and marks the image.
	 * @param ObjectService             $objectService Reads the session.
	 * @param MessageHistoryHandler     $history       Stores the two turns.
	 * @param ConversationParticipation $participation Owner-or-listed-participant guard.
	 * @param IL10N                     $l10n          Messages in the person's language.
	 * @param LoggerInterface           $logger        Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ImageGenerationService $images,
		private readonly ObjectService $objectService,
		private readonly MessageHistoryHandler $history,
		private readonly ConversationParticipation $participation,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether the Chat page shows "Create an image" for the signed-in person.
	 *
	 * @return JSONResponse `{ available: bool }`.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-no-provider-no-button
	 */
	#[NoAdminRequired]
	public function availability(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['available' => false]);
		}

		return new JSONResponse(data: ['available' => $this->images->canCreate(uid: $user->getUID())]);
	}//end availability()

	/**
	 * Create an image from the description and store it as the answer on the session.
	 *
	 * @param string $sessionId The session the action was chosen in.
	 * @param string $prompt    What the image shows.
	 *
	 * @return JSONResponse `{ userTurn, assistantTurn }`, or `{ error, code }` on a refusal.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-a-communications-advisor-asks-for-an-illustration
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function create(string $sessionId = '', string $prompt = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->refusal(message: $this->l10n->t('Not signed in.'), code: 'not_signed_in', status: 401);
		}

		$prompt = trim($prompt);
		if ($prompt === '' || $sessionId === '') {
			return $this->refusal(message: $this->l10n->t('Describe the image to create.'), code: 'invalid_prompt', status: 400);
		}

		$uid = $user->getUID();
		$session = $this->objectService->find(id: $sessionId, register: 'hermiq', schema: 'agentsession');
		if ($session === null) {
			return $this->refusal(message: $this->l10n->t('This session does not exist.'), code: 'not_found', status: 404);
		}

		if ($this->participation->mayTakeTurn(conversationData: $session->getObject(), userId: $uid) === false) {
			return $this->refusal(message: $this->l10n->t('You cannot add to this session.'), code: 'forbidden', status: 403);
		}

		try {
			$created = $this->images->create(uid: $uid, prompt: $prompt);
		} catch (ImageGenerationException $e) {
			return $this->refusal(message: $e->getMessage(), code: $e->errorCode, status: (self::STATUS_FOR[$e->errorCode] ?? 502));
		}

		return $this->storeTurns(sessionId: $sessionId, uid: $uid, displayName: $user->getDisplayName(), prompt: $prompt, created: $created);
	}//end create()

	/**
	 * Store the description and the image as two turns and answer with both.
	 *
	 * @param string               $sessionId   The session.
	 * @param string               $uid         The person.
	 * @param string               $displayName Their display name at send time.
	 * @param string               $prompt      The description.
	 * @param array<string, mixed> $created     What the service created.
	 *
	 * @return JSONResponse The two turns, or a 500 when they could not be stored.
	 */
	private function storeTurns(string $sessionId, string $uid, string $displayName, string $prompt, array $created): JSONResponse {
		$attachment = $this->images->asAttachment(created: $created);
		$answer = $this->l10n->t('Here is the image you asked for.');

		try {
			$userTurn = $this->history->storeMessage(
				conversationId: $sessionId,
				role: 'user',
				content: $prompt,
				authorId: $uid,
				authorDisplayName: $displayName
			);
			$assistantTurn = $this->history->storeMessage(
				conversationId: $sessionId,
				role: 'assistant',
				content: $answer,
				attachments: [$attachment]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ChatImageController] The image was created but the turns could not be stored',
				context: ['fileId' => $attachment['fileId'], 'exception' => $e]
			);
			$message = $this->l10n->t('The image is in your Files, but it could not be added to the chat.');
			return $this->refusal(message: $message, code: 'not_stored', status: 500);
		}

		return new JSONResponse(
			data: [
				'userTurn' => ['id' => (string)$userTurn->getUuid(), 'role' => 'user', 'content' => $prompt, 'attachments' => []],
				'assistantTurn' => [
					'id' => (string)$assistantTurn->getUuid(),
					'role' => 'assistant',
					'content' => $answer,
					'attachments' => [$attachment],
				],
			]
		);
	}//end storeTurns()

	/**
	 * A refusal in the shape the Chat page reads.
	 *
	 * @param string $message The message for the person.
	 * @param string $code    The stable code.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse `{ error, code }`.
	 */
	private function refusal(string $message, string $code, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $message, 'code' => $code], statusCode: $status);
	}//end refusal()
}//end class
