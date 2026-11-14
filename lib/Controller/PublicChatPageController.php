<?php

/**
 * Hermiq PublicChatPageController.
 *
 * The standalone public chat page a website frames (delivery-public-web-chat).
 * Public: it shows the channel's disclosure and posts to the public endpoint,
 * and only the channel's allowed origins may frame it.
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
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\Hermiq\Service\PublicChat\PublicChatService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The public chat page.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
class PublicChatPageController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request The request.
	 * @param PublicChannelStore $store   Finds the channel by its token.
	 * @param PublicChatService  $chat    Gives the channel's disclosure.
	 * @param IInitialState      $state   Hands the page its channel.
	 * @param IURLGenerator      $urls    Builds the endpoint the page posts to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function __construct(
		IRequest $request,
		private readonly PublicChannelStore $store,
		private readonly PublicChatService $chat,
		private readonly IInitialState $state,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The standalone chat page a website frames.
	 *
	 * GET /public/{channelToken}. Only the channel's allowed origins may frame
	 * it. The disclosure is part of the page before the input.
	 *
	 * @param string $channelToken The channel's token from the embed code.
	 *
	 * @return TemplateResponse 200, or 404 with an unavailable window for an unknown token.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function page(string $channelToken): TemplateResponse {
		$channel = $this->store->channelByToken(token: $channelToken);
		$this->state->provideInitialState(
			'public-chat',
			[
				'available'  => ($channel !== null && ($channel['enabled'] ?? false) === true),
				'disclosure' => $this->chat->disclosureFor(channel: ($channel ?? [])),
				'endpoint'   => $this->urls->linkToRoute(AppIdentity::APP_ID . '.publicChat.message', ['channelToken' => $channelToken]),
				'title'      => (string)($channel['name'] ?? ''),
			]
		);

		$response = new TemplateResponse(AppIdentity::APP_ID, 'public-chat', [], TemplateResponse::RENDER_AS_BASE);
		$policy   = new ContentSecurityPolicy();
		foreach ($this->store->allowedOrigins(channel: ($channel ?? [])) as $origin) {
			$policy->addAllowedFrameAncestorDomain($origin);
		}

		$response->setContentSecurityPolicy($policy);
		if ($channel === null) {
			$response->setStatus(Http::STATUS_NOT_FOUND);
		}

		return $response;
	}//end page()
}//end class
