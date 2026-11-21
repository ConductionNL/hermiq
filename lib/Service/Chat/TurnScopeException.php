<?php

/**
 * Hermiq TurnScopeException.
 *
 * A turn's scope that is malformed (400) or names a file or folder the speaker
 * cannot open (404). Its code is the HTTP status the turn is refused with.
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
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/tasks.md#task-1-a-turn-carries-a-scope
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use Exception;

/**
 * Refuses a turn for its scope, before anything is stored or any model is called.
 *
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
 */
class TurnScopeException extends Exception {

	/**
	 * The stable code for a scope that is not a file or folder scope.
	 *
	 * @var string
	 */
	public const INVALID = 'scope_invalid';

	/**
	 * The stable code for a scope the speaker cannot open.
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'scope_not_found';

	/**
	 * A refusal of a turn's scope.
	 *
	 * @param int $status 400 for a scope whose shape is not `{type: "file"|"folder", fileId}`,
	 *                    404 for one naming a file or folder the speaker cannot open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function __construct(int $status) {
		$message = 'The scope must be {type: "file" or "folder", fileId}';
		if ($status === 404) {
			$message = 'The document or folder this question is about was not found';
		}

		parent::__construct(message: $message, code: $status);
	}//end __construct()

	/**
	 * The stable error code for the frontend.
	 *
	 * @return string scope_invalid or scope_not_found.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function errorCode(): string {
		if ($this->getCode() === 404) {
			return self::NOT_FOUND;
		}

		return self::INVALID;
	}//end errorCode()
}//end class
