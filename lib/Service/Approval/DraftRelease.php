<?php

/**
 * Hermiq DraftRelease
 *
 * What a reviewer's release of a held draft sends: the agent's text as it
 * is, or with the reviewer's edits to the fields that may be edited. The
 * recipients and every other field stay as the agent wrote them.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

/**
 * Applies a reviewer's edits to a held draft, refusing edits to fixed fields.
 *
 * Two kinds of draft: `delivery` (a scheduled run's output, `{channel,
 * output, conversationUuid}`) and `tool` (an outbound message tool call,
 * `{toolId, arguments, editable}`).
 */
final class DraftRelease {

	/**
	 * A scheduled run's output held before delivery.
	 */
	public const KIND_DELIVERY = 'delivery';

	/**
	 * An outbound message tool call held before it runs.
	 */
	public const KIND_TOOL = 'tool';

	/**
	 * The fields of a mail a reviewer may edit when the tool names none.
	 */
	public const DEFAULT_EDITABLE = ['subject', 'body'];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $draft The Approval's `draftPayload`.
	 */
	public function __construct(private readonly array $draft) {
	}//end __construct()

	/**
	 * The draft's kind: `delivery` or `tool`.
	 *
	 * @return string The kind.
	 */
	public function kind(): string {
		if ((string)($this->draft['kind'] ?? '') === self::KIND_TOOL) {
			return self::KIND_TOOL;
		}

		return self::KIND_DELIVERY;
	}//end kind()

	/**
	 * Apply a release's edits.
	 *
	 * An edit that repeats a fixed field's value unchanged is accepted, so a
	 * client may send the whole draft back; a changed fixed field is refused.
	 *
	 * @param array<string, mixed> $edits The edited fields: `output` for a delivery, the editable tool arguments for a tool.
	 *
	 * @return array{draft: array<string, mixed>, original: string, sent: string, edited: bool} The draft to send and both texts.
	 *
	 * @throws DraftEditRefused When an edit changes a field that may not be edited, or is not text.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	public function released(array $edits): array {
		if ($this->kind() === self::KIND_TOOL) {
			return $this->releasedTool(edits: $edits);
		}

		$original = (string)($this->draft['output'] ?? '');
		$sent = $original;
		foreach ($edits as $field => $value) {
			if ($field !== 'output') {
				throw new DraftEditRefused('Only the text of this draft can be changed.');
			}

			$sent = $this->text(field: (string)$field, value: $value);
		}

		$draft = $this->draft;
		$draft['output'] = $sent;

		return ['draft' => $draft, 'original' => $original, 'sent' => $sent, 'edited' => ($sent !== $original)];
	}//end released()

	/**
	 * The fields of a tool draft a reviewer may edit.
	 *
	 * @return list<string> The editable argument names.
	 */
	public function editable(): array {
		$raw = $this->draft['editable'] ?? self::DEFAULT_EDITABLE;
		if (is_array($raw) === false) {
			return self::DEFAULT_EDITABLE;
		}

		return array_values(array_filter($raw, static fn ($field): bool => is_string($field) === true && $field !== ''));
	}//end editable()

	/**
	 * Apply edits to an outbound message tool call.
	 *
	 * @param array<string, mixed> $edits The edited arguments.
	 *
	 * @return array{draft: array<string, mixed>, original: string, sent: string, edited: bool}
	 *
	 * @throws DraftEditRefused When a fixed argument is changed.
	 */
	private function releasedTool(array $edits): array {
		$arguments = $this->draft['arguments'] ?? [];
		if (is_array($arguments) === false) {
			$arguments = [];
		}

		$editable = $this->editable();
		$released = $arguments;
		foreach ($edits as $field => $value) {
			$field = (string)$field;
			if (in_array($field, $editable, true) === true) {
				$released[$field] = $this->text(field: $field, value: $value);
				continue;
			}

			if (array_key_exists($field, $arguments) === true && $value === $arguments[$field]) {
				continue;
			}

			throw new DraftEditRefused('The field "' . $field . '" of this draft cannot be changed.');
		}

		$draft = $this->draft;
		$draft['arguments'] = $released;
		$original = self::messageText(arguments: $arguments);
		$sent = self::messageText(arguments: $released);

		return ['draft' => $draft, 'original' => $original, 'sent' => $sent, 'edited' => ($sent !== $original)];
	}//end releasedTool()

	/**
	 * An edited value, which must be text.
	 *
	 * @param string $field The field name, for the refusal.
	 * @param mixed $value The edited value.
	 *
	 * @return string The value.
	 *
	 * @throws DraftEditRefused When the value is not text.
	 */
	private function text(string $field, mixed $value): string {
		if (is_string($value) === false) {
			throw new DraftEditRefused('The field "' . $field . '" must be text.');
		}

		return $value;
	}//end text()

	/**
	 * The readable text of a message tool call: its subject and its body.
	 *
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return string The text.
	 */
	private static function messageText(array $arguments): string {
		$body = (string)($arguments['body'] ?? ($arguments['message'] ?? ''));
		$subject = trim((string)($arguments['subject'] ?? ''));
		if ($subject === '') {
			return $body;
		}

		return 'Subject: ' . $subject . "\n\n" . $body;
	}//end messageText()
}//end class
