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
use OCA\Hermiq\Service\Translation\TranslationDisclosure;
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
	 * The longest `originalRef` accepted. The reference is opaque to hermiq and
	 * echoed back; the cap keeps a caller from parking a document in it.
	 *
	 * @var int
	 */
	private const ORIGINAL_REF_MAX_LENGTH = 512;

	/**
	 * The disclosure table, which also owns the language-tag shape check.
	 *
	 * @var TranslationDisclosure
	 */
	private readonly TranslationDisclosure $disclosure;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession Resolves the requesting user (401 if absent).
	 * @param MessageTranslationEngine $engine The gated translation pipeline.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param TranslationDisclosure|null $disclosure The language-tag check. Nullable and
	 *                                               trailing so a controller built by
	 *                                               hand keeps its old shape.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly MessageTranslationEngine $engine,
		private readonly LoggerInterface $logger,
		?TranslationDisclosure $disclosure=null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->disclosure = $disclosure ?? new TranslationDisclosure();
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
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-010-language-tags-and-the-original-reference-are-validated-before-any-prompt
	 */
	public function translate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$input = $this->readInput();
		if (is_string($input) === true) {
			return new JSONResponse(['error' => $input], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->engine->translate(
				sourceText: $input['sourceText'],
				targetLanguage: $input['targetLanguage'],
				glossary: $input['glossary'],
				sourceLanguage: $input['sourceLanguage'],
				originalRef: $input['originalRef']
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

	/**
	 * Read and validate the request body.
	 *
	 * @return array{sourceText: string, targetLanguage: string, sourceLanguage: string|null, originalRef: string, glossary: array}|string
	 *         The validated input, or the 400 message.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-010-language-tags-and-the-original-reference-are-validated-before-any-prompt
	 */
	private function readInput(): array|string {
		$sourceText = $this->request->getParam('sourceText');
		if (is_string($sourceText) === false || $sourceText === '') {
			return 'sourceText is required';
		}

		$targetLanguage = $this->request->getParam('targetLanguage');
		if (is_string($targetLanguage) === false || $targetLanguage === '') {
			return 'targetLanguage is required';
		}

		$sourceLanguage = $this->request->getParam('sourceLanguage');
		if ($sourceLanguage === '') {
			$sourceLanguage = null;
		}

		$originalRef = ($this->request->getParam('originalRef') ?? '');

		$error = $this->provenanceError(
			targetLanguage: $targetLanguage,
			sourceLanguage: $sourceLanguage,
			originalRef: $originalRef
		);
		if ($error !== null) {
			return $error;
		}

		$glossary = $this->request->getParam('glossary', []);
		if (is_array($glossary) === false) {
			$glossary = [];
		}

		return [
			'sourceText'     => $sourceText,
			'targetLanguage' => $targetLanguage,
			'sourceLanguage' => $sourceLanguage,
			'originalRef'    => $originalRef,
			'glossary'       => $glossary,
		];
	}//end readInput()

	/**
	 * Check the fields that reach a prompt or the response as provenance. Both
	 * language tags are interpolated into a prompt, so only a tag gets there.
	 *
	 * @param string $targetLanguage The target tag.
	 * @param mixed $sourceLanguage The source tag, or null when the caller left it out.
	 * @param mixed $originalRef The caller's reference to the original.
	 *
	 * @return string|null The 400 message, or null when all three are acceptable.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-010-language-tags-and-the-original-reference-are-validated-before-any-prompt
	 */
	private function provenanceError(string $targetLanguage, mixed $sourceLanguage, mixed $originalRef): ?string {
		if ($this->disclosure->isLanguageTag(tag: $targetLanguage) === false) {
			return 'targetLanguage must be a BCP-47 language tag';
		}

		if ($sourceLanguage !== null && $this->disclosure->isLanguageTag(tag: $sourceLanguage) === false) {
			return 'sourceLanguage must be a BCP-47 language tag';
		}

		if (is_string($originalRef) === false || mb_strlen($originalRef) > self::ORIGINAL_REF_MAX_LENGTH) {
			return 'originalRef must be a string of at most ' . self::ORIGINAL_REF_MAX_LENGTH . ' characters';
		}

		return null;
	}//end provenanceError()
}//end class
