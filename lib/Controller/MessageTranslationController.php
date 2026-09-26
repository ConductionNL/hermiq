<?php

/**
 * Hermiq MessageTranslationController (message-translation-delegate).
 *
 * The REST surface other Conduction apps call to translate a parent-facing
 * message or news item. Auth + input-shape validation only — all gating
 * (AiFeature enabled), prompt building and the LLM call live in
 * `MessageTranslationEngine`, mirroring `CourseRecommendationController`'s
 * thin auth + HTTP-mapping shell.
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
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-005-the-rest-endpoint-requires-authentication-and-validates-its-input
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\MessageTranslationEngine;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Auth + validation shell over MessageTranslationEngine.
 *
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-005-the-rest-endpoint-requires-authentication-and-validates-its-input
 */
class MessageTranslationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession Resolves the requesting user (401 if absent).
	 * @param MessageTranslationEngine $engine The gated translation pipeline.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly MessageTranslationEngine $engine,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Translate `sourceText` into `targetLanguage`, honouring an optional glossary.
	 *
	 * @return JSONResponse The engine's `{available: bool, ...}` result, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-005-the-rest-endpoint-requires-authentication-and-validates-its-input
	 */
	public function translate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$sourceText = $this->request->getParam('sourceText');
		if (is_string($sourceText) === false || $sourceText === '') {
			return new JSONResponse(['error' => 'sourceText is required'], Http::STATUS_BAD_REQUEST);
		}

		$targetLanguage = $this->request->getParam('targetLanguage');
		if (is_string($targetLanguage) === false || $targetLanguage === '') {
			return new JSONResponse(['error' => 'targetLanguage is required'], Http::STATUS_BAD_REQUEST);
		}

		$glossary = $this->request->getParam('glossary', []);
		if (is_array($glossary) === false) {
			$glossary = [];
		}

		try {
			$result = $this->engine->translate(
				sourceText: $sourceText,
				targetLanguage: $targetLanguage,
				glossary: $glossary
			);

			return new JSONResponse($result);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq message-translation request failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				['error' => 'Could not translate the given text'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end translate()
}//end class
