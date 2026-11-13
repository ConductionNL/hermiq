<?php

/**
 * Hermiq AssignmentRedactor.
 *
 * Masks secrets written as configuration: ENV-style, dotted and anchored
 * `key=value` assignments, JSON fields and unquoted YAML values whose key names
 * a credential. The pipeline skips this pass for source code, where such
 * assignments are mostly false positives (`MAX_TOKENS=100`).
 *
 * Part of the PHP/PCRE port of Hermes' `agent/redact.py` (NousResearch
 * hermes-agent, MIT) that RedactionService runs before every audit write.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Redaction
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
 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#1-redaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Redaction;

/**
 * The configuration-assignment passes of the redaction pipeline.
 *
 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
 */
final class AssignmentRedactor {

	/**
	 * Upper-case ENV names that mark a credential.
	 *
	 * @var string
	 */
	private const ENV_NAMES = '(?:API_?KEY|TOKEN|SECRET|PASSWORD|PASSWD|CREDENTIAL|AUTH)';

	/**
	 * ENV-style assignment (port of redact.py `_ENV_ASSIGN_RE`).
	 *
	 * @var string
	 */
	private const ENV_RE = '~([A-Z0-9_]{0,50}' . self::ENV_NAMES . '[A-Z0-9_]{0,50})\s*=\s*([\'"]?)(\S+)\2~';

	/**
	 * Lower-case / dotted config key names that mark a credential.
	 *
	 * @var string
	 */
	private const CFG_NAMES = '(?:api[ _.\-]?key|token|secret|passwd|password|credential|auth)';

	/**
	 * A config value, optionally quoted, ending at whitespace, `&` or the end.
	 *
	 * @var string
	 */
	private const CFG_VALUE = '([\'"]?)([^\s&]+?)\2(?=[\s&]|$)';

	/**
	 * A dotted config key naming a credential.
	 *
	 * @var string
	 */
	private const DOTTED_KEY = '((?:[A-Za-z0-9_\-]+\.)+[A-Za-z0-9_.\-]*' . self::CFG_NAMES . '[A-Za-z0-9_.\-]*'
		. '|[A-Za-z0-9_.\-]*' . self::CFG_NAMES . '[A-Za-z0-9_.\-]*\.[A-Za-z0-9_.\-]+)';

	/**
	 * Dotted config assignment (port of redact.py `_CFG_DOTTED_RE`).
	 *
	 * @var string
	 */
	private const DOTTED_RE = '~' . self::DOTTED_KEY . '=' . self::CFG_VALUE . '~i';

	/**
	 * A line-anchored (optionally exported) config key naming a credential.
	 *
	 * @var string
	 */
	private const ANCHORED_KEY = '(^[ \t]*(?:export[ \t]+)?[A-Za-z0-9_\-]*' . self::CFG_NAMES . '[A-Za-z0-9_\-]*)';

	/**
	 * Anchored config assignment (port of redact.py `_CFG_ANCHORED_RE`).
	 *
	 * @var string
	 */
	private const ANCHORED_RE = '~' . self::ANCHORED_KEY . '=' . self::CFG_VALUE . '~im';

	/**
	 * JSON field names that mark a credential.
	 *
	 * @var string
	 */
	private const JSON_KEY_NAMES = '(?:api_?[Kk]ey|token|secret|password|access_token|refresh_token'
		. '|auth_token|bearer|secret_value|raw_secret|secret_input|key_material)';

	/**
	 * JSON field (port of redact.py `_JSON_FIELD_RE`).
	 *
	 * @var string
	 */
	private const JSON_RE = '~("' . self::JSON_KEY_NAMES . '")\s*:\s*"([^"]+)"~i';

	/**
	 * YAML key names that mark a credential.
	 *
	 * @var string
	 */
	private const YAML_NAMES = '(?:api[ _.\-]?key|token|secret|passwd|password|credential)';

	/**
	 * Unquoted YAML / colon config value (port of redact.py `_YAML_ASSIGN_RE`).
	 *
	 * @var string
	 */
	private const YAML_RE = '~(^[ \t]*[A-Za-z0-9_.\-]*' . self::YAML_NAMES . '[A-Za-z0-9_.\-]*)(:[ \t]*)(?![\'"])([^\s&]+)~im';

	/**
	 * Constructor.
	 *
	 * @param TokenMasker $masker Masks a matched value for a log line.
	 */
	public function __construct(
		private readonly TokenMasker $masker,
	) {
	}//end __construct()

	/**
	 * Mask assignment, JSON-field and YAML secrets, in that order.
	 *
	 * @param string $text The text to scan (not source code).
	 *
	 * @return string The text with configuration secrets masked.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	public function redact(string $text): string {
		$text = $this->redactEnvAssignments(text: $text);
		$text = $this->redactJsonFields(text: $text);
		return $this->redactYaml(text: $text);
	}//end redact()

	/**
	 * Mask ENV-style and lowercase/dotted/anchored config assignments.
	 *
	 * Ports redact.py's `_ENV_ASSIGN_RE`, `_CFG_DOTTED_RE`, `_CFG_ANCHORED_RE`.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with assignment secrets masked.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	private function redactEnvAssignments(string $text): string {
		if (str_contains($text, '=') === false) {
			return $text;
		}

		$text = $this->maskAssignments(regex: self::ENV_RE, text: $text);

		// Web-URL query params are intentionally passed through; DB DSN passwords
		// are still caught by the connection-string pass.
		if (str_contains($text, '://') === true) {
			return $text;
		}

		$text = $this->maskAssignments(regex: self::DOTTED_RE, text: $text);
		return $this->maskAssignments(regex: self::ANCHORED_RE, text: $text);
	}//end redactEnvAssignments()

	/**
	 * Mask the value of every assignment one regex matches.
	 *
	 * @param string $regex An assignment regex with name, quote and value groups.
	 * @param string $text The text to scan.
	 *
	 * @return string The text with matched values masked.
	 */
	private function maskAssignments(string $regex, string $text): string {
		return preg_replace_callback(
			$regex,
			fn (array $matches): string => $this->envAssignReplacement(matches: $matches),
			$text
		);
	}//end maskAssignments()

	/**
	 * Replacement callback for an assignment match: keep name+quote, mask value.
	 *
	 * @param array<int, string> $matches The regex groups (1=name, 2=quote, 3=value).
	 *
	 * @return string The masked assignment.
	 */
	private function envAssignReplacement(array $matches): string {
		$quote = ($matches[2] ?? '');
		return $matches[1] . '=' . $quote . $this->masker->mask(token: ($matches[3] ?? '')) . $quote;
	}//end envAssignReplacement()

	/**
	 * Mask JSON field values ("apiKey": "…", "token": "…").
	 *
	 * Port of redact.py `_JSON_FIELD_RE`.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with JSON secret values masked.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	private function redactJsonFields(string $text): string {
		if (str_contains($text, ':') === false || str_contains($text, '"') === false) {
			return $text;
		}

		return preg_replace_callback(
			self::JSON_RE,
			function (array $matches): string {
				return $matches[1] . ': "' . $this->masker->mask(token: $matches[2]) . '"';
			},
			$text
		);
	}//end redactJsonFields()

	/**
	 * Mask unquoted YAML / colon config values (password: …).
	 *
	 * Port of redact.py `_YAML_ASSIGN_RE`. Skipped for URLs.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with YAML secret values masked.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	private function redactYaml(string $text): string {
		if (str_contains($text, ':') === false || str_contains($text, '://') === true) {
			return $text;
		}

		return preg_replace_callback(
			self::YAML_RE,
			function (array $matches): string {
				return $matches[1] . $matches[2] . $this->masker->mask(token: $matches[3]);
			},
			$text
		);
	}//end redactYaml()
}//end class
