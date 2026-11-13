<?php

/**
 * Hermiq FormBodyRedactor.
 *
 * Masks sensitive parameter values (tokens, keys, passwords, codes, …) in a
 * clean single-line form-urlencoded body, leaving prose untouched.
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
 * The form-body pass of the redaction pipeline.
 *
 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
 */
final class FormBodyRedactor {

	/**
	 * Query/form parameter names whose values are always masked.
	 *
	 * Ported from redact.py `_SENSITIVE_QUERY_PARAMS`; used by the form-body pass.
	 *
	 * @var array<int, string>
	 */
	private const SENSITIVE_QUERY_PARAMS = [
		'access_token',
		'refresh_token',
		'id_token',
		'token',
		'api_key',
		'apikey',
		'client_secret',
		'password',
		'auth',
		'jwt',
		'session',
		'secret',
		'key',
		'code',
		'signature',
		'x-amz-signature',
	];

	/**
	 * A clean `k=v&k=v` body: at least two pairs, nothing else.
	 *
	 * @var string
	 */
	private const FORM_RE = '~^[A-Za-z_][A-Za-z0-9_.-]*=[^&\s]*(?:&[A-Za-z_][A-Za-z0-9_.-]*=[^&\s]*)+$~';

	/**
	 * Redact sensitive values in a pure form-urlencoded body (k=v&k=v).
	 *
	 * Port of redact.py `_redact_form_body`; only triggers on a clean single-line
	 * form body, leaving prose untouched.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text (the trimmed body when it was redacted).
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	public function redact(string $text): string {
		if (str_contains($text, '&') === false || str_contains($text, '=') === false) {
			return $text;
		}

		if (str_contains($text, "\n") === true) {
			return $text;
		}

		$trimmed = trim($text);
		if (preg_match(self::FORM_RE, $trimmed) !== 1) {
			return $text;
		}

		return $this->redactQueryString(query: $trimmed);
	}//end redact()

	/**
	 * Redact sensitive parameter values in a `k=v&k=v` query string.
	 *
	 * Port of redact.py `_redact_query_string`.
	 *
	 * @param string $query The query string.
	 *
	 * @return string The query string with sensitive values masked.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	private function redactQueryString(string $query): string {
		if ($query === '') {
			return $query;
		}

		$parts = array_map(
			fn (string $pair): string => $this->redactQueryPair(pair: $pair),
			explode('&', $query)
		);

		return implode('&', $parts);
	}//end redactQueryString()

	/**
	 * One `k=v` pair with its value masked when the key is sensitive.
	 *
	 * @param string $pair The pair (a bare key without `=` passes through).
	 *
	 * @return string The pair, masked or as given.
	 */
	private function redactQueryPair(string $pair): string {
		if (str_contains($pair, '=') === false) {
			return $pair;
		}

		[$key] = explode('=', $pair, 2);
		if (in_array(strtolower($key), self::SENSITIVE_QUERY_PARAMS, true) === true) {
			return $key . '=***';
		}

		return $pair;
	}//end redactQueryPair()
}//end class
