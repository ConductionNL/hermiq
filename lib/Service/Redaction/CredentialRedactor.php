<?php

/**
 * Hermiq CredentialRedactor.
 *
 * Masks credentials carried in transport formats: Authorization and API-key
 * headers, Telegram bot tokens, PEM private-key blocks, database connection
 * string passwords, bare-token URLs and JWTs.
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
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Redaction;

/**
 * The header, token, key-block and credential-URL passes of the redaction pipeline.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
 */
final class CredentialRedactor {

	/**
	 * Constructor.
	 *
	 * @param TokenMasker $masker Masks a matched credential for a log line.
	 */
	public function __construct(
		private readonly TokenMasker $masker,
	) {
	}//end __construct()

	/**
	 * Run the credential passes in pipeline order.
	 *
	 * @param string $text The text to scan.
	 * @param bool $codeFile Whether the text is source code (keeps `{...}` DSN templates).
	 *
	 * @return string The text with transport credentials masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	public function redact(string $text, bool $codeFile): string {
		$text = $this->redactAuthHeaders(text: $text);
		$text = $this->redactApiKeyHeaders(text: $text);
		$text = $this->redactTelegram(text: $text);
		$text = $this->redactPrivateKeys(text: $text);
		$text = $this->redactDbConnStrings(text: $text, codeFile: $codeFile);
		$text = $this->redactUrlBareToken(text: $text);
		return $this->redactJwt(text: $text);
	}//end redact()

	/**
	 * Mask the credential in Authorization / Proxy-Authorization headers.
	 *
	 * Port of redact.py `_AUTH_HEADER_RE` — any scheme; header + scheme preserved.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with header credentials masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactAuthHeaders(string $text): string {
		if (stripos($text, 'uthorization') === false) {
			return $text;
		}

		return preg_replace_callback(
			'~((?:Proxy-)?Authorization:\s*)([A-Za-z][\w.+-]*\s+)?([^\s"\']+)~i',
			function (array $matches): string {
				return $matches[1] . $matches[2] . $this->masker->mask(token: $matches[3]);
			},
			$text
		);
	}//end redactAuthHeaders()

	/**
	 * Mask opaque API-key header values (x-api-key, api-key, …).
	 *
	 * Port of redact.py `_SECRET_HEADER_RE`.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with API-key header values masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactApiKeyHeaders(string $text): string {
		if (str_contains($text, ':') === false) {
			return $text;
		}

		$names = '(?:x-api-key|x-goog-api-key|api-key|apikey|x-api-token|x-auth-token|x-access-token)';

		return preg_replace_callback(
			'~(' . $names . '\s*:\s*)(\S+)~i',
			function (array $matches): string {
				return $matches[1] . $this->masker->mask(token: $matches[2]);
			},
			$text
		);
	}//end redactApiKeyHeaders()

	/**
	 * Mask Telegram bot tokens (bot<digits>:<token> or <digits>:<token>).
	 *
	 * Port of redact.py `_TELEGRAM_RE`.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with Telegram tokens masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactTelegram(string $text): string {
		if (str_contains($text, ':') === false) {
			return $text;
		}

		return preg_replace_callback(
			'~(bot)?(\d{8,}):([-A-Za-z0-9_]{30,})~',
			function (array $matches): string {
				return $matches[1] . $matches[2] . ':***';
			},
			$text
		);
	}//end redactTelegram()

	/**
	 * Replace PEM private-key blocks with a marker.
	 *
	 * Port of redact.py `_PRIVATE_KEY_RE`.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with private-key blocks replaced.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactPrivateKeys(string $text): string {
		if (str_contains($text, 'BEGIN') === false || str_contains($text, '-----') === false) {
			return $text;
		}

		return preg_replace(
			'~-----BEGIN[A-Z ]*PRIVATE KEY-----[\s\S]*?-----END[A-Z ]*PRIVATE KEY-----~',
			'[REDACTED PRIVATE KEY]',
			$text
		);
	}//end redactPrivateKeys()

	/**
	 * Mask the password in a database connection string.
	 *
	 * Port of redact.py `_DB_CONNSTR_RE`. For code files, a pure `{...}` brace
	 * expression is an f-string template reference and is preserved.
	 *
	 * @param string $text The text to scan.
	 * @param bool $codeFile Whether the text is source code.
	 *
	 * @return string The text with DSN passwords masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactDbConnStrings(string $text, bool $codeFile): string {
		if (str_contains($text, '://') === false) {
			return $text;
		}

		return preg_replace_callback(
			'~((?:postgres(?:ql)?|mysql|mongodb(?:\+srv)?|redis|amqp)://[^:\s]+:)([^@\s]+)(@)~i',
			fn (array $matches): string => $this->dbPasswordReplacement(matches: $matches, codeFile: $codeFile),
			$text
		);
	}//end redactDbConnStrings()

	/**
	 * Replacement for a DSN match: mask the password, unless a code file holds
	 * a `{...}` template reference there.
	 *
	 * @param array<int, string> $matches The regex groups (1=prefix, 2=password, 3=`@`).
	 * @param bool $codeFile Whether the text is source code.
	 *
	 * @return string The replacement.
	 */
	private function dbPasswordReplacement(array $matches, bool $codeFile): string {
		$pass = $matches[2];
		if ($codeFile === true && str_starts_with($pass, '{') === true && str_ends_with($pass, '}') === true) {
			return $matches[0];
		}

		return $matches[1] . '***' . $matches[3];
	}//end dbPasswordReplacement()

	/**
	 * Mask a bare-token credential in a web/transport URL (scheme://TOKEN@host).
	 *
	 * Port of redact.py `_URL_BARE_TOKEN_RE`. Only runs when a URL is present.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with bare URL tokens masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactUrlBareToken(string $text): string {
		if (str_contains($text, '://') === false) {
			return $text;
		}

		return preg_replace_callback(
			'~((?:https?|wss?|git|ssh|ftp|ftps|sftp)://)([^\s:@/]{8,})(@[^\s]+)~i',
			function (array $matches): string {
				return $matches[1] . $this->masker->mask(token: $matches[2]) . $matches[3];
			},
			$text
		);
	}//end redactUrlBareToken()

	/**
	 * Mask JWT tokens (eyJ… header.payload.signature).
	 *
	 * Port of redact.py `_JWT_RE`.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with JWTs masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactJwt(string $text): string {
		if (str_contains($text, 'eyJ') === false) {
			return $text;
		}

		return preg_replace_callback(
			'~eyJ[A-Za-z0-9_-]{10,}(?:\.[A-Za-z0-9_=-]{4,}){0,2}~',
			function (array $matches): string {
				return $this->masker->mask(token: $matches[0]);
			},
			$text
		);
	}//end redactJwt()
}//end class
