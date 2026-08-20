<?php

/**
 * Hermiq RedactionService.
 *
 * A faithful PHP/PCRE port of Hermes' `agent/redact.py` secret/PII redactor
 * (NousResearch hermes-agent, MIT). It masks API keys, auth headers, DB DSN
 * passwords, private-key blocks, JWTs, Telegram tokens, config/ENV/JSON/YAML
 * key=value assignments, bare-token credential URLs and E.164 phone numbers
 * BEFORE any value reaches the immutable OpenRegister AuditTrail — the
 * redaction-before-persist invariant of ADR-004 (the hash-chained trail is
 * append-only, so a secret written once can never be removed).
 *
 * Fidelity notes vs redact.py:
 *   - Ports the full `redact_sensitive_text` pass and its complete pattern set
 *     (the ~40 vendor prefixes, ENV/dotted-config/anchored/YAML/JSON assignment
 *     rules, Authorization + x-api-key headers, Telegram, private keys, DB
 *     connection strings, bare-token URLs, JWTs, form-urlencoded bodies, E.164
 *     phones) plus `mask_secret`/`_mask_token`/`_mask_token_nonreusable`.
 *   - The web-URL query-param / `user:pass@` userinfo / HTTP-access-log passes
 *     are intentionally OFF here exactly as in redact.py's main pass (those live
 *     only in the CDP-URL helper, irrelevant to audit records).
 *   - The enable toggle is frozen at construction (read once from app config), so
 *     an agent run can never disable redaction mid-flight — matching redact.py's
 *     import-time snapshot of HERMES_REDACT_SECRETS.
 *
 * This is a legitimate ADR-031 imperative helper: a pure string transform on the
 * boundary before an audit write. It owns no schema, no derived value, no
 * lifecycle.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use OCP\IConfig;

/**
 * Regex-based secret/PII redactor applied before every audit write.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     The redactor is a sum of many
 *   small single-pattern passes; each helper stays simple.
 * @SuppressWarnings(PHPMD.TooManyMethods)           One private method per credential
 *   pattern keeps every pass independently testable and low-complexity.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     A faithful port of a 40-pattern
 *   redactor is inherently long; the length is data (patterns), not logic.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Class complexity is the sum of
 *   many independent one-pattern passes, each individually trivial.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */
class RedactionService {

	/**
	 * Mode flag: force redaction even when the frozen toggle is off (safety boundary).
	 *
	 * @var int
	 */
	public const MODE_FORCE = 1;

	/**
	 * Mode flag: text is source code — skip ENV/JSON/YAML assignment passes (false positives).
	 *
	 * @var int
	 */
	public const MODE_CODE_FILE = 2;

	/**
	 * Mode flag: file content returned to the agent — prefix hits become a non-reusable sentinel.
	 *
	 * @var int
	 */
	public const MODE_FILE_READ = 4;

	/**
	 * Known vendor API-key prefixes: match the prefix + contiguous token chars.
	 *
	 * Ported verbatim from redact.py `_PREFIX_PATTERNS`. Case-sensitive.
	 *
	 * @var array<int, string>
	 */
	private const PREFIX_PATTERNS = [
		'sk-[A-Za-z0-9_-]{10,}',
		'ghp_[A-Za-z0-9]{10,}',
		'github_pat_[A-Za-z0-9_]{10,}',
		'gho_[A-Za-z0-9]{10,}',
		'ghu_[A-Za-z0-9]{10,}',
		'ghs_[A-Za-z0-9]{10,}',
		'ghr_[A-Za-z0-9]{10,}',
		'xapp-\d+-[A-Za-z0-9-]{10,}',
		'xox[baprs]-[A-Za-z0-9-]{10,}',
		'AIza[A-Za-z0-9_-]{30,}',
		'pplx-[A-Za-z0-9]{10,}',
		'fal_[A-Za-z0-9_-]{10,}',
		'fc-[A-Za-z0-9]{10,}',
		'bb_live_[A-Za-z0-9_-]{10,}',
		'gAAAA[A-Za-z0-9_=-]{20,}',
		'AKIA[A-Z0-9]{16}',
		'sk_live_[A-Za-z0-9]{10,}',
		'sk_test_[A-Za-z0-9]{10,}',
		'rk_live_[A-Za-z0-9]{10,}',
		'SG\.[A-Za-z0-9_-]{10,}',
		'hf_[A-Za-z0-9]{10,}',
		'r8_[A-Za-z0-9]{10,}',
		'npm_[A-Za-z0-9]{10,}',
		'pypi-[A-Za-z0-9_-]{10,}',
		'dop_v1_[A-Za-z0-9]{10,}',
		'doo_v1_[A-Za-z0-9]{10,}',
		'am_[A-Za-z0-9_-]{10,}',
		'sk_[A-Za-z0-9_]{10,}',
		'tvly-[A-Za-z0-9]{10,}',
		'exa_[A-Za-z0-9]{10,}',
		'gsk_[A-Za-z0-9]{10,}',
		'syt_[A-Za-z0-9]{10,}',
		'retaindb_[A-Za-z0-9]{10,}',
		'hsk-[A-Za-z0-9]{10,}',
		'mem0_[A-Za-z0-9]{10,}',
		'brv_[A-Za-z0-9]{10,}',
		'xai-[A-Za-z0-9]{30,}',
		'ntn_[A-Za-z0-9]{10,}',
		'fw_[A-Za-z0-9]{30,}',
	];

	/**
	 * Query/body parameter names whose values are always secret (case-insensitive).
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
	 * Whether redaction is enabled (frozen at construction; a run cannot flip it).
	 *
	 * @var boolean
	 */
	private bool $enabled;

	/**
	 * Pre-built prefix alternation regex (with non-word-char guards).
	 *
	 * @var string
	 */
	private string $prefixRegex;

	/**
	 * Literal leading substrings of each prefix pattern (cheap pre-screen).
	 *
	 * @var array<int, string>
	 */
	private array $prefixSubstrings;

	/**
	 * Constructor.
	 *
	 * Freezes the redaction toggle from app config so runtime mutation cannot
	 * disable it mid-session (secure default: ON). Pre-compiles the prefix
	 * alternation and its literal pre-screen substrings once.
	 *
	 * @param IConfig $config Reads the frozen `redact_secrets` app setting (default on).
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	public function __construct(IConfig $config) {
		$setting = strtolower((string)$config->getAppValue('hermiq', 'redact_secrets', 'yes'));
		$this->enabled = in_array($setting, ['1', 'true', 'yes', 'on'], true);

		$this->prefixRegex = '~(?<![A-Za-z0-9_-])(' . implode('|', self::PREFIX_PATTERNS) . ')(?![A-Za-z0-9_-])~';
		$this->prefixSubstrings = array_map(
			fn (string $pattern): string => $this->extractLiteralPrefix(pattern: $pattern),
			self::PREFIX_PATTERNS
		);

	}//end __construct()

	/**
	 * Redact a string for a compliance audit write (a forced safety boundary).
	 *
	 * Always redacts regardless of the frozen toggle, because the audit trail is
	 * append-only: a leaked secret cannot be undone. Aggressive (not code-file):
	 * ENV/JSON/YAML assignment values are masked too.
	 *
	 * @param string $text The text to redact (agent output summary / error).
	 *
	 * @return string The redacted text.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	public function redact(string $text): string {
		return $this->redactSensitiveText(text: $text, mode: self::MODE_FORCE);
	}//end redact()

	/**
	 * Apply all redaction patterns to a block of text.
	 *
	 * Faithful port of redact.py `redact_sensitive_text`. Non-matching text
	 * passes through unchanged. `$mode` is a bitmask of the MODE_* flags.
	 *
	 * @param string $text The text to scan.
	 * @param int $mode Bitmask: MODE_FORCE | MODE_CODE_FILE | MODE_FILE_READ.
	 *
	 * @return string The redacted text.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	public function redactSensitiveText(string $text, int $mode = 0): string {
		if ($text === '') {
			return $text;
		}

		if (($mode & self::MODE_FORCE) === 0 && $this->enabled === false) {
			return $text;
		}

		// File content is config/data, not log lines — skip source-code false-positive paths.
		if (($mode & self::MODE_FILE_READ) !== 0) {
			$mode |= self::MODE_CODE_FILE;
		}

		$text = $this->redactPrefixes(text: $text, mode: $mode);
		$text = $this->redactEnvAssignments(text: $text, mode: $mode);
		$text = $this->redactJsonFields(text: $text, mode: $mode);
		$text = $this->redactYaml(text: $text, mode: $mode);
		$text = $this->redactAuthHeaders(text: $text);
		$text = $this->redactApiKeyHeaders(text: $text);
		$text = $this->redactTelegram(text: $text);
		$text = $this->redactPrivateKeys(text: $text);
		$text = $this->redactDbConnStrings(text: $text, mode: $mode);
		$text = $this->redactUrlBareToken(text: $text);
		$text = $this->redactJwt(text: $text);
		$text = $this->redactFormBody(text: $text);
		$text = $this->redactPhones(text: $text);

		return $text;
	}//end redactSensitiveText()

	/**
	 * Mask a secret preserving head/tail chars; short values are fully masked.
	 *
	 * Port of redact.py `mask_secret`.
	 *
	 * @param string $value The secret to mask.
	 * @param int $head Leading characters to preserve.
	 * @param int $tail Trailing characters to preserve.
	 * @param int $floor Values shorter than this are fully masked.
	 * @param string $placeholder Returned for too-short inputs.
	 * @param string $empty Returned for an empty value.
	 *
	 * @return string The masked value.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	public function maskSecret(
		string $value,
		int $head = 4,
		int $tail = 4,
		int $floor = 12,
		string $placeholder = '***',
		string $empty = '',
	): string {
		if ($value === '') {
			return $empty;
		}

		if (strlen($value) < $floor) {
			return $placeholder;
		}

		return substr($value, 0, $head) . '...' . substr($value, -$tail);
	}//end maskSecret()

	/**
	 * Mask a log token — 18-char floor, preserves 6 prefix / 4 suffix.
	 *
	 * Port of redact.py `_mask_token` (empty → '***', never '').
	 *
	 * @param string $token The token to mask.
	 *
	 * @return string The masked token.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function maskToken(string $token): string {
		if ($token === '') {
			return '***';
		}

		return $this->maskSecret(value: $token, head: 6, tail: 4, floor: 18);
	}//end maskToken()

	/**
	 * Redact a prefix-matched credential to a NON-REUSABLE sentinel.
	 *
	 * Port of redact.py `_mask_token_nonreusable` — used for file-read content so
	 * an agent cannot write a truncated-but-valid-looking key back to config.
	 *
	 * @param string $token The prefix-matched credential.
	 *
	 * @return string The non-reusable sentinel (vendor label preserved).
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function maskTokenNonReusable(string $token): string {
		foreach ($this->prefixSubstrings as $sub) {
			if ($sub !== '' && str_starts_with($token, $sub) === true) {
				return '«redacted:' . $sub . '…»';
			}
		}

		return '«redacted-secret»';
	}//end maskTokenNonReusable()

	/**
	 * Mask known vendor prefixes (sk-, ghp_, AKIA, …).
	 *
	 * @param string $text The text to scan.
	 * @param int $mode The active mode bitmask.
	 *
	 * @return string The text with prefix credentials masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactPrefixes(string $text, int $mode): string {
		if ($this->hasKnownPrefixSubstring(text: $text) === false) {
			return $text;
		}

		$fileRead = (($mode & self::MODE_FILE_READ) !== 0);

		return preg_replace_callback(
			$this->prefixRegex,
			function (array $matches) use ($fileRead): string {
				if ($fileRead === true) {
					return $this->maskTokenNonReusable(token: $matches[1]);
				}

				return $this->maskToken(token: $matches[1]);
			},
			$text
		);

	}//end redactPrefixes()

	/**
	 * Mask ENV-style and lowercase/dotted/anchored config assignments.
	 *
	 * Ports redact.py's `_ENV_ASSIGN_RE`, `_CFG_DOTTED_RE`, `_CFG_ANCHORED_RE`.
	 * Skipped for code files (false positives on `MAX_TOKENS=100` etc.).
	 *
	 * @param string $text The text to scan.
	 * @param int $mode The active mode bitmask.
	 *
	 * @return string The text with assignment secrets masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactEnvAssignments(string $text, int $mode): string {
		if (($mode & self::MODE_CODE_FILE) !== 0 || str_contains($text, '=') === false) {
			return $text;
		}

		$rep = fn (array $matches): string => $this->envAssignReplacement(matches: $matches);

		$envNames = '(?:API_?KEY|TOKEN|SECRET|PASSWORD|PASSWD|CREDENTIAL|AUTH)';
		$envRe = '~([A-Z0-9_]{0,50}' . $envNames . '[A-Z0-9_]{0,50})\s*=\s*([\'"]?)(\S+)\2~';
		$text = preg_replace_callback($envRe, $rep, $text);

		// Web-URL query params are intentionally passed through; DB DSN passwords
		// are still caught by the connection-string pass.
		if (str_contains($text, '://') === true) {
			return $text;
		}

		$cfgNames = '(?:api[ _.\-]?key|token|secret|passwd|password|credential|auth)';
		$cfgValue = '([\'"]?)([^\s&]+?)\2(?=[\s&]|$)';
		$dottedKey = '((?:[A-Za-z0-9_\-]+\.)+[A-Za-z0-9_.\-]*' . $cfgNames . '[A-Za-z0-9_.\-]*'
			. '|[A-Za-z0-9_.\-]*' . $cfgNames . '[A-Za-z0-9_.\-]*\.[A-Za-z0-9_.\-]+)';
		$text = preg_replace_callback('~' . $dottedKey . '=' . $cfgValue . '~i', $rep, $text);

		$anchoredKey = '(^[ \t]*(?:export[ \t]+)?[A-Za-z0-9_\-]*' . $cfgNames . '[A-Za-z0-9_\-]*)';
		$text = preg_replace_callback('~' . $anchoredKey . '=' . $cfgValue . '~im', $rep, $text);

		return $text;
	}//end redactEnvAssignments()

	/**
	 * Replacement callback for an assignment match: keep name+quote, mask value.
	 *
	 * @param array<int, string> $matches The regex match (1=name, 2=quote, 3=value).
	 *
	 * @return string The rebuilt, masked assignment.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function envAssignReplacement(array $matches): string {
		$quote = ($matches[2] ?? '');
		return $matches[1] . '=' . $quote . $this->maskToken(token: ($matches[3] ?? '')) . $quote;
	}//end envAssignReplacement()

	/**
	 * Mask JSON field values ("apiKey": "…", "token": "…").
	 *
	 * Port of redact.py `_JSON_FIELD_RE`. Skipped for code files.
	 *
	 * @param string $text The text to scan.
	 * @param int $mode The active mode bitmask.
	 *
	 * @return string The text with JSON secret values masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactJsonFields(string $text, int $mode): string {
		if (($mode & self::MODE_CODE_FILE) !== 0) {
			return $text;
		}

		if (str_contains($text, ':') === false || str_contains($text, '"') === false) {
			return $text;
		}

		$keyNames = '(?:api_?[Kk]ey|token|secret|password|access_token|refresh_token'
			. '|auth_token|bearer|secret_value|raw_secret|secret_input|key_material)';

		return preg_replace_callback(
			'~("' . $keyNames . '")\s*:\s*"([^"]+)"~i',
			function (array $matches): string {
				return $matches[1] . ': "' . $this->maskToken(token: $matches[2]) . '"';
			},
			$text
		);

	}//end redactJsonFields()

	/**
	 * Mask unquoted YAML / colon config values (password: …).
	 *
	 * Port of redact.py `_YAML_ASSIGN_RE`. Skipped for code files and URLs.
	 *
	 * @param string $text The text to scan.
	 * @param int $mode The active mode bitmask.
	 *
	 * @return string The text with YAML secret values masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactYaml(string $text, int $mode): string {
		if (($mode & self::MODE_CODE_FILE) !== 0) {
			return $text;
		}

		if (str_contains($text, ':') === false || str_contains($text, '://') === true) {
			return $text;
		}

		$yamlNames = '(?:api[ _.\-]?key|token|secret|passwd|password|credential)';
		$yamlRe = '~(^[ \t]*[A-Za-z0-9_.\-]*' . $yamlNames . '[A-Za-z0-9_.\-]*)(:[ \t]*)(?![\'"])([^\s&]+)~im';

		return preg_replace_callback(
			$yamlRe,
			function (array $matches): string {
				return $matches[1] . $matches[2] . $this->maskToken(token: $matches[3]);
			},
			$text
		);

	}//end redactYaml()

	/**
	 * Mask the credential in Authorization / Proxy-Authorization headers.
	 *
	 * Port of redact.py `_AUTH_HEADER_RE` — any scheme; header + scheme preserved.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with auth-header credentials masked.
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
				return $matches[1] . $matches[2] . $this->maskToken(token: $matches[3]);
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
				return $matches[1] . $this->maskToken(token: $matches[2]);
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
	 * @return string The text with private-key blocks removed.
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
	 * @param int $mode The active mode bitmask.
	 *
	 * @return string The text with DSN passwords masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactDbConnStrings(string $text, int $mode): string {
		if (str_contains($text, '://') === false) {
			return $text;
		}

		$dbRe = '~((?:postgres(?:ql)?|mysql|mongodb(?:\+srv)?|redis|amqp)://[^:\s]+:)([^@\s]+)(@)~i';
		$codeFile = (($mode & self::MODE_CODE_FILE) !== 0);

		return preg_replace_callback(
			$dbRe,
			function (array $matches) use ($codeFile): string {
				$pass = $matches[2];
				if ($codeFile === true && str_starts_with($pass, '{') === true && str_ends_with($pass, '}') === true) {
					return $matches[0];
				}

				return $matches[1] . '***' . $matches[3];
			},
			$text
		);

	}//end redactDbConnStrings()

	/**
	 * Mask a bare-token credential in a web/transport URL (scheme://TOKEN@host).
	 *
	 * Port of redact.py `_URL_BARE_TOKEN_RE`. Only runs when a URL is present.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with bare-token userinfo masked.
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
				return $matches[1] . $this->maskToken(token: $matches[2]) . $matches[3];
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
				return $this->maskToken(token: $matches[0]);
			},
			$text
		);

	}//end redactJwt()

	/**
	 * Mask E.164 phone numbers (+<country><number>).
	 *
	 * Port of redact.py `_SIGNAL_PHONE_RE` + `_redact_phone`.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with phone numbers masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactPhones(string $text): string {
		if (str_contains($text, '+') === false) {
			return $text;
		}

		return preg_replace_callback(
			'~(\+[1-9]\d{6,14})(?![A-Za-z0-9])~',
			function (array $matches): string {
				$phone = $matches[1];
				if (strlen($phone) <= 8) {
					return substr($phone, 0, 2) . '****' . substr($phone, -2);
				}

				return substr($phone, 0, 4) . '****' . substr($phone, -4);
			},
			$text
		);

	}//end redactPhones()

	/**
	 * Redact sensitive values in a pure form-urlencoded body (k=v&k=v).
	 *
	 * Port of redact.py `_redact_form_body`; only triggers on a clean single-line
	 * form body, leaving prose untouched.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with form-body secrets masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactFormBody(string $text): string {
		if (str_contains($text, '&') === false || str_contains($text, '=') === false) {
			return $text;
		}

		if (str_contains($text, "\n") === true) {
			return $text;
		}

		$trimmed = trim($text);
		$formRe = '~^[A-Za-z_][A-Za-z0-9_.-]*=[^&\s]*(?:&[A-Za-z_][A-Za-z0-9_.-]*=[^&\s]*)+$~';
		if (preg_match($formRe, $trimmed) !== 1) {
			return $text;
		}

		return $this->redactQueryString(query: $trimmed);
	}//end redactFormBody()

	/**
	 * Redact sensitive parameter values in a `k=v&k=v` query string.
	 *
	 * Port of redact.py `_redact_query_string`.
	 *
	 * @param string $query The query string.
	 *
	 * @return string The query string with sensitive values masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function redactQueryString(string $query): string {
		if ($query === '') {
			return $query;
		}

		$parts = [];
		foreach (explode('&', $query) as $pair) {
			if (str_contains($pair, '=') === false) {
				$parts[] = $pair;
				continue;
			}

			[$key] = explode('=', $pair, 2);
			if (in_array(strtolower($key), self::SENSITIVE_QUERY_PARAMS, true) === true) {
				$parts[] = $key . '=***';
				continue;
			}

			$parts[] = $pair;
		}

		return implode('&', $parts);
	}//end redactQueryString()

	/**
	 * Whether the text contains any known credential prefix substring.
	 *
	 * Cheap pre-screen before the expensive prefix alternation (no false negatives).
	 *
	 * @param string $text The text to test.
	 *
	 * @return bool True when a known prefix substring is present.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function hasKnownPrefixSubstring(string $text): bool {
		foreach ($this->prefixSubstrings as $sub) {
			if ($sub !== '' && str_contains($text, $sub) === true) {
				return true;
			}
		}

		return false;
	}//end hasKnownPrefixSubstring()

	/**
	 * Return the leading literal characters of a regex pattern.
	 *
	 * Port of redact.py `_extract_literal_prefix`: stops at the first regex
	 * metacharacter, yielding a substring every match must contain.
	 *
	 * @param string $pattern The regex pattern.
	 *
	 * @return string The literal prefix.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	private function extractLiteralPrefix(string $pattern): string {
		$meta = '[(\\.?*+|{^$';
		$length = strlen($pattern);
		for ($i = 0; $i < $length; $i++) {
			if (str_contains($meta, $pattern[$i]) === true) {
				return substr($pattern, 0, $i);
			}
		}

		return $pattern;
	}//end extractLiteralPrefix()
}//end class
