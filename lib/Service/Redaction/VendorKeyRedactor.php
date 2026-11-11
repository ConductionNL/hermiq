<?php

/**
 * Hermiq VendorKeyRedactor.
 *
 * Masks API keys recognised by their vendor prefix (sk-, ghp_, AKIA, …). In
 * file-read content a hit becomes a non-reusable sentinel instead, so an agent
 * cannot write a truncated-but-valid-looking key back to config.
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
 * The vendor-prefix pass of the redaction pipeline.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
 */
final class VendorKeyRedactor {

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
	 * Constructor: pre-compiles the prefix alternation and its literal
	 * pre-screen substrings once.
	 *
	 * @param TokenMasker $masker Masks a matched key for a log line.
	 */
	public function __construct(
		private readonly TokenMasker $masker,
	) {
		$this->prefixRegex = '~(?<![A-Za-z0-9_-])(' . implode('|', self::PREFIX_PATTERNS) . ')(?![A-Za-z0-9_-])~';
		$this->prefixSubstrings = array_map(
			fn (string $pattern): string => $this->extractLiteralPrefix(pattern: $pattern),
			self::PREFIX_PATTERNS
		);
	}//end __construct()

	/**
	 * Mask known vendor prefixes (sk-, ghp_, AKIA, …).
	 *
	 * @param string $text The text to scan.
	 * @param bool $fileRead Whether the text is file content returned to the agent.
	 *
	 * @return string The text with prefix credentials masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	public function redact(string $text, bool $fileRead): string {
		if ($this->hasKnownPrefixSubstring(text: $text) === false) {
			return $text;
		}

		return preg_replace_callback(
			$this->prefixRegex,
			function (array $matches) use ($fileRead): string {
				if ($fileRead === true) {
					return $this->maskTokenNonReusable(token: $matches[1]);
				}

				return $this->masker->mask(token: $matches[1]);
			},
			$text
		);
	}//end redact()

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
	 * Cheap pre-screen: does the text contain any literal vendor prefix at all?
	 *
	 * @param string $text The text to scan.
	 *
	 * @return bool
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
	 * The literal leading part of a prefix pattern, up to its first regex
	 * metacharacter.
	 *
	 * @param string $pattern One PREFIX_PATTERNS entry.
	 *
	 * @return string The literal prefix.
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
