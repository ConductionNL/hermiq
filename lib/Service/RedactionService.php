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
 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#1-redaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Redaction\AssignmentRedactor;
use OCA\Hermiq\Service\Redaction\CredentialRedactor;
use OCA\Hermiq\Service\Redaction\FormBodyRedactor;
use OCA\Hermiq\Service\Redaction\PhoneRedactor;
use OCA\Hermiq\Service\Redaction\TokenMasker;
use OCA\Hermiq\Service\Redaction\VendorKeyRedactor;
use OCP\IConfig;

/**
 * Regex-based secret/PII redactor applied before every audit write.
 *
 * Owns the frozen enable toggle, the mode flags and the order of the passes;
 * each family of patterns lives in its own class under `Redaction/`.
 *
 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#1-redaction
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
	 * Whether redaction is enabled (frozen at construction; a run cannot flip it).
	 *
	 * @var boolean
	 */
	private bool $enabled;

	/**
	 * The vendor-prefix pass.
	 *
	 * @var VendorKeyRedactor
	 */
	private VendorKeyRedactor $vendorKeys;

	/**
	 * The ENV / config / JSON / YAML assignment passes (skipped for source code).
	 *
	 * @var AssignmentRedactor
	 */
	private AssignmentRedactor $assignments;

	/**
	 * The header, token, key-block and credential-URL passes.
	 *
	 * @var CredentialRedactor
	 */
	private CredentialRedactor $credentials;

	/**
	 * The form-urlencoded body pass.
	 *
	 * @var FormBodyRedactor
	 */
	private FormBodyRedactor $formBodies;

	/**
	 * The phone-number pass.
	 *
	 * @var PhoneRedactor
	 */
	private PhoneRedactor $phones;

	/**
	 * Constructor.
	 *
	 * Freezes the redaction toggle from app config so runtime mutation cannot
	 * disable it mid-session (secure default: ON). Builds the pattern passes
	 * once (the vendor-prefix pass pre-compiles its alternation).
	 *
	 * @param IConfig $config Reads the frozen `redact_secrets` app setting (default on).
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	public function __construct(IConfig $config) {
		$setting = strtolower((string)$config->getAppValue('hermiq', 'redact_secrets', 'yes'));
		$this->enabled = in_array($setting, ['1', 'true', 'yes', 'on'], true);

		$masker = new TokenMasker();
		$this->vendorKeys = new VendorKeyRedactor(masker: $masker);
		$this->assignments = new AssignmentRedactor(masker: $masker);
		$this->credentials = new CredentialRedactor(masker: $masker);
		$this->formBodies = new FormBodyRedactor();
		$this->phones = new PhoneRedactor();

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
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
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
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
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

		return $this->applyPasses(text: $text, mode: $mode);
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
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
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
	 * Run every pattern pass in redact.py's order for the given mode.
	 *
	 * @param string $text The non-empty text to scan.
	 * @param int $mode Bitmask with MODE_FILE_READ already implying MODE_CODE_FILE.
	 *
	 * @return string The redacted text.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	private function applyPasses(string $text, int $mode): string {
		$codeFile = (($mode & self::MODE_CODE_FILE) !== 0);

		$text = $this->vendorKeys->redact(text: $text, fileRead: (($mode & self::MODE_FILE_READ) !== 0));
		if ($codeFile === false) {
			$text = $this->assignments->redact(text: $text);
		}

		$text = $this->credentials->redact(text: $text, codeFile: $codeFile);
		$text = $this->formBodies->redact(text: $text);
		return $this->phones->redact(text: $text);
	}//end applyPasses()
}//end class
