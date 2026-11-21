<?php

/**
 * Hermiq InvokerToolbox.
 *
 * A research toolbox over two functions bound to the governed
 * `FacadeToolInvoker`. The invoker answers JSON: the tool's own result when
 * it ran, a refusal envelope (`isError: true` or an `error` field) when a
 * grant, guardrail, egress guard or turn guard stopped it. A refusal becomes
 * an `error` envelope here, so `SourceLedger::record()` never numbers it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

use LLPhant\Chat\FunctionInfo\FunctionInfo;

/**
 * Calls the governed search and fetch functions and decodes their answers.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */
class InvokerToolbox implements ResearchToolbox {
	/**
	 * Constructor.
	 *
	 * @param FunctionInfo $search The governed `hermiq.webSearch` function.
	 * @param FunctionInfo $fetch  The governed `hermiq.webFetch` function.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FunctionInfo $search,
		private readonly FunctionInfo $fetch,
	) {
	}//end __construct()

	/**
	 * Search the web through the governed chain.
	 *
	 * @param string $query The search query.
	 *
	 * @return list<array{title: string, url: string}> The results, empty when refused or none.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function search(string $query): array {
		$answer = self::decode(raw: $this->search->callWithArguments(['query' => $query]));
		if (isset($answer['error']) === true) {
			return [];
		}

		$results = [];
		foreach ((array)($answer['results'] ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$url = trim((string)($item['url'] ?? ''));
			if ($url === '') {
				continue;
			}

			$results[] = ['title' => trim((string)($item['title'] ?? '')), 'url' => $url];
		}

		return $results;
	}//end search()

	/**
	 * Read one page through the governed chain.
	 *
	 * @param string $url The page.
	 *
	 * @return array<string, mixed> The webFetch result, or an `error` envelope when refused or failed.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function fetch(string $url): array {
		return self::decode(raw: $this->fetch->callWithArguments(['url' => $url]));
	}//end fetch()

	/**
	 * The invoker's JSON answer as an array; a refusal or anything unreadable is an `error` envelope.
	 *
	 * @param mixed $raw What the invoker returned.
	 *
	 * @return array<string, mixed>
	 */
	private static function decode(mixed $raw): array {
		$decoded = null;
		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
		}

		if (is_array($decoded) === false) {
			return ['error' => ['code' => 'unreadable', 'message' => 'The tool answer could not be read.']];
		}

		if (($decoded['isError'] ?? false) === true || isset($decoded['error']) === true) {
			return ['error' => ($decoded['error'] ?? 'refused')];
		}

		return $decoded;
	}//end decode()
}//end class
