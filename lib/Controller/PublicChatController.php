<?php

/**
 * Hermiq PublicChatController.
 *
 * The public web chat: the endpoint a website's chat window posts a
 * visitor's question to, and its CORS preflight. No visitor signs in. The
 * chat page itself is PublicChatPageController.
 *
 * WHY #[PublicPage] IS SAFE HERE. The channel token in the URL names a
 * channel; it is not a secret and authenticates nobody. What makes the
 * endpoint safe is what it can reach: one tool-free agent, answering only
 * from objects OpenRegister serves to anonymous callers inside the channel's
 * declared scope (PublicRetrievalService), with the session ignored. The
 * per-client rate limit, the channel's daily limit and the origin check bound
 * its cost. Kept apart from every session-authenticated controller: a
 * `#[PublicPage]` method never sits in a class with session-authenticated ones.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\Hermiq\Service\PublicChat\PublicChatRefusedException;
use OCA\Hermiq\Service\PublicChat\PublicChatService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public chat endpoint and its preflight.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */
class PublicChatController extends Controller {

	/**
	 * How long a browser may cache a preflight answer, in seconds.
	 */
	private const PREFLIGHT_MAX_AGE = 600;

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request The request.
	 * @param PublicChannelStore $store   Finds the channel by its token.
	 * @param PublicChatService  $chat    Runs the turn.
	 * @param LoggerInterface    $logger  Records a failed turn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function __construct(
		IRequest $request,
		private readonly PublicChannelStore $store,
		private readonly PublicChatService $chat,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Answer a visitor's question on a website channel.
	 *
	 * POST /api/public/{channelToken}/messages with `question`, and
	 * `conversationToken` from the previous answer to continue a visit.
	 *
	 * @param string $channelToken The channel's token from the embed code.
	 *
	 * @return JSONResponse 200 with the answer; 403 for an origin the channel does not allow (no CORS headers);
	 *                      404 for an unknown token; 400, 429 or 503 when the turn is refused.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[UserRateLimit(limit: 20, period: 60)]
	public function message(string $channelToken): JSONResponse {
		$channel = $this->store->channelByToken(token: $channelToken);
		if ($channel === null) {
			return new JSONResponse(['message' => PublicChatRefusedException::NOT_AVAILABLE, 'available' => false], Http::STATUS_NOT_FOUND);
		}

		$origin = $this->origin();
		if ($this->originAllowed(channel: $channel, origin: $origin) === false) {
			return new JSONResponse(['message' => 'This website may not use this chat.'], Http::STATUS_FORBIDDEN);
		}

		try {
			$answer   = $this->chat->answer(
				channel: $channel,
				question: (string)$this->request->getParam('question', ''),
				locale: self::locale(value: $this->request->getParam('locale', 'en')),
				sourceScope: null,
				conversationToken: $this->tokenOrNull(value: $this->request->getParam('conversationToken'))
			);
			$response = new JSONResponse($answer->toArray());
		} catch (PublicChatRefusedException $e) {
			$response = new JSONResponse(['message' => $e->getMessage(), 'available' => ($e->isUnavailable() === false)], $e->getStatus());
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[PublicChatController] A public chat turn failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			$response = new JSONResponse(['message' => PublicChatRefusedException::NOT_AVAILABLE, 'available' => false], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$this->addCors(response: $response, origin: $origin);

		return $response;
	}//end message()

	/**
	 * Answer a browser's CORS preflight for a website channel.
	 *
	 * OPTIONS /api/public/{channelToken}/messages. Only an origin the channel
	 * lists is told it may post; any other gets no CORS headers at all.
	 *
	 * @param string $channelToken The channel's token from the embed code.
	 *
	 * @return Response 204 with the CORS headers for an allowed origin; 403 without them otherwise.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function preflight(string $channelToken): Response {
		$channel = $this->store->channelByToken(token: $channelToken);
		$origin  = $this->origin();
		if ($channel === null || $origin === '' || $this->originAllowed(channel: $channel, origin: $origin) === false) {
			$response = new Response();
			$response->setStatus(Http::STATUS_FORBIDDEN);
			return $response;
		}

		$response = new Response();
		$response->setStatus(Http::STATUS_NO_CONTENT);
		$response->addHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
		$response->addHeader('Access-Control-Allow-Headers', 'Content-Type');
		$response->addHeader('Access-Control-Max-Age', (string)self::PREFLIGHT_MAX_AGE);

		$this->addCors(response: $response, origin: $origin);

		return $response;
	}//end preflight()

	/**
	 * Whether an origin may post to a channel.
	 *
	 * No Origin header is not a browser on another site (a browser always
	 * sends one on a cross-origin post), so it is let through and bounded by
	 * the rate limits. This instance's own origin is the framed chat page.
	 * Anything else must be listed exactly in the channel's allowed origins.
	 *
	 * @param array<string, mixed> $channel The channel.
	 * @param string               $origin  The request's Origin, '' when absent.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function originAllowed(array $channel, string $origin): bool {
		if ($origin === '' || $origin === $this->ownOrigin()) {
			return true;
		}

		return in_array($origin, $this->store->allowedOrigins(channel: $channel), true);
	}//end originAllowed()

	/**
	 * Add the CORS headers for an origin other than this instance's own.
	 *
	 * @param Response $response The response.
	 * @param string   $origin   The allowed origin, '' when the request sent none.
	 *
	 * @return void
	 */
	private function addCors(Response $response, string $origin): void {
		if ($origin !== '' && $origin !== $this->ownOrigin()) {
			$response->addHeader('Access-Control-Allow-Origin', $origin);
			$response->addHeader('Vary', 'Origin');
		}
	}//end addCors()

	/**
	 * The request's Origin header, lower-cased, '' when absent.
	 *
	 * @return string
	 */
	private function origin(): string {
		return strtolower(rtrim(trim((string)$this->request->getHeader('Origin')), '/'));
	}//end origin()

	/**
	 * This instance's own origin, as a browser on the framed page sends it.
	 *
	 * @return string
	 */
	private function ownOrigin(): string {
		return strtolower($this->request->getServerProtocol() . '://' . $this->request->getServerHost());
	}//end ownOrigin()

	/**
	 * A locale of the shape `nl` or `nl_NL`, English otherwise.
	 *
	 * @param mixed $value The requested locale.
	 *
	 * @return string
	 */
	private static function locale(mixed $value): string {
		$value = (string)$value;
		if (preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/', $value) === 1) {
			return $value;
		}

		return 'en';
	}//end locale()

	/**
	 * A conversation token, or null for none.
	 *
	 * @param mixed $value The token the window sent.
	 *
	 * @return string|null
	 */
	private function tokenOrNull(mixed $value): ?string {
		if (is_string($value) === false || $this->store->isToken(token: $value) === false) {
			return null;
		}

		return $value;
	}//end tokenOrNull()
}//end class
