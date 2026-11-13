<?php

/**
 * Hermiq TurnScope.
 *
 * Reads the optional `scope` of a chat turn: `{type: "file", fileId}` for one
 * document, `{type: "folder", fileId}` for everything under a folder. No scope
 * leaves the turn unscoped; any other shape refuses the turn with a 400.
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

/**
 * Parses a turn's scope parameter.
 *
 * @psalm-type Scope = array{type: 'file'|'folder', fileId: int}
 *
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
 */
final class TurnScope {

	/**
	 * The scope types a turn may carry.
	 *
	 * @var list<string>
	 */
	public const TYPES = ['file', 'folder'];

	/**
	 * The scope in its one accepted shape, or null when the turn carries none.
	 *
	 * @param mixed $value The request's `scope` value.
	 *
	 * @return array{type: string, fileId: int}|null The scope.
	 *
	 * @throws TurnScopeException When a scope is given in any other shape.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-a-collection-req-dchat-002
	 */
	public function parse(mixed $value): ?array {
		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		if (is_array($value) === false || in_array(($value['type'] ?? null), self::TYPES, true) === false) {
			throw new TurnScopeException(status: 400);
		}

		$fileId = $this->fileId(value: ($value['fileId'] ?? null));
		if ($fileId === null) {
			throw new TurnScopeException(status: 400);
		}

		return ['type' => (string)$value['type'], 'fileId' => $fileId];
	}//end parse()

	/**
	 * The scope a turn's RAG settings carry, when they carry one.
	 *
	 * @param array<string, mixed> $ragSettings The turn's RAG settings.
	 *
	 * @return array{type: string, fileId: int}|null The scope.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function fromRagSettings(array $ragSettings): ?array {
		$scope = ($ragSettings['scope'] ?? null);
		if (is_array($scope) === false) {
			return null;
		}

		return $this->parse(value: $scope);
	}//end fromRagSettings()

	/**
	 * A positive file id from an int or a string of digits, else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The id.
	 */
	private function fileId(mixed $value): ?int {
		if (is_string($value) === true && ctype_digit($value) === true) {
			$value = (int)$value;
		}

		if (is_int($value) === false || $value < 1) {
			return null;
		}

		return $value;
	}//end fileId()
}//end class
