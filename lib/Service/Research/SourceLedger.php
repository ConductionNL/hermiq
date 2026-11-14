<?php

/**
 * Hermiq SourceLedger.
 *
 * The numbered list of pages a research run has read (design D2, D3): per source
 * its URL, title, fetch time and the text `hermiq.webFetch` returned, with the
 * untrusted-content delimiter taken off. A citation can only point at a number
 * in this ledger, and the citation check reads the text stored here, so a page
 * that was refused or came back empty is never given a number.
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

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Hermiq\Service\WebResearch\WebFetchService;

/**
 * The sources a research run has read, numbered from 1 in the order they were read.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */
final class SourceLedger {

	/**
	 * The sources, in order; index + 1 is the number.
	 *
	 * @var array<int, array{number: int, url: string, title: string, fetchedAt: string, text: string}>
	 */
	private array $sources = [];

	/**
	 * Record what one `hermiq.webFetch` call returned.
	 *
	 * A refusal or failure (an `error` envelope), a result without a URL, or a
	 * page without text gets no number. The same URL read twice keeps its first
	 * number, so a citation cannot point at two copies of one page.
	 *
	 * @param array<string, mixed> $fetch     The webFetch tool result.
	 * @param string               $title     The page's title, '' when unknown (the URL is used).
	 * @param DateTimeImmutable    $fetchedAt When it was read.
	 *
	 * @return int|null The source's number, or null when it was not recorded.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function record(array $fetch, string $title, DateTimeImmutable $fetchedAt): ?int {
		if (isset($fetch['error']) === true) {
			return null;
		}

		$url  = trim((string)($fetch['url'] ?? ''));
		$text = self::untrustedBody(content: (string)($fetch['content'] ?? ''));
		if ($url === '' || $text === '') {
			return null;
		}

		$known = $this->numberOf(url: $url);
		if ($known !== null) {
			return $known;
		}

		$named = trim($title);
		if ($named === '') {
			$named = $url;
		}

		$number = (count($this->sources) + 1);
		$this->sources[] = [
			'number'    => $number,
			'url'       => $url,
			'title'     => $named,
			'fetchedAt' => $fetchedAt->format(DateTimeInterface::ATOM),
			'text'      => $text,
		];

		return $number;
	}//end record()

	/**
	 * One source by its number.
	 *
	 * @param int $number The number.
	 *
	 * @return array{number: int, url: string, title: string, fetchedAt: string, text: string}|null The source.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function source(int $number): ?array {
		return ($this->sources[($number - 1)] ?? null);
	}//end source()

	/**
	 * How many sources were read.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function count(): int {
		return count($this->sources);
	}//end count()

	/**
	 * The ledger as stored on the research handoff.
	 *
	 * @return array<int, array{number: int, url: string, title: string, fetchedAt: string, text: string}>
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function toArray(): array {
		return $this->sources;
	}//end toArray()

	/**
	 * A ledger read back from the research handoff. Malformed rows are skipped
	 * and the rest renumbered in order, so a number always names its row.
	 *
	 * @param array<int, mixed> $rows The stored rows.
	 *
	 * @return self The ledger.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public static function fromArray(array $rows): self {
		$ledger = new self();
		foreach ($rows as $row) {
			if (is_array($row) === false || trim((string)($row['url'] ?? '')) === '' || (string)($row['text'] ?? '') === '') {
				continue;
			}

			$ledger->sources[] = [
				'number'    => (count($ledger->sources) + 1),
				'url'       => (string)$row['url'],
				'title'     => (string)($row['title'] ?? $row['url']),
				'fetchedAt' => (string)($row['fetchedAt'] ?? ''),
				'text'      => (string)$row['text'],
			];
		}

		return $ledger;
	}//end fromArray()

	/**
	 * The report's closing sources list: number, title, URL and fetch time.
	 *
	 * @return string Markdown, '' when nothing was read.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003
	 */
	public function sourcesList(): string {
		if ($this->sources === []) {
			return '';
		}

		$lines = ['## Sources', ''];
		foreach ($this->sources as $source) {
			$lines[] = $source['number'] . '. ' . $source['title'] . ', <' . $source['url'] . '>, read ' . $source['fetchedAt'];
		}

		return implode("\n", $lines);
	}//end sourcesList()

	/**
	 * The number a URL already has, or null.
	 *
	 * @param string $url The URL.
	 *
	 * @return int|null The number.
	 */
	private function numberOf(string $url): ?int {
		foreach ($this->sources as $source) {
			if ($source['url'] === $url) {
				return $source['number'];
			}
		}

		return null;
	}//end numberOf()

	/**
	 * The page text inside webFetch's untrusted-content delimiter, trimmed.
	 *
	 * @param string $content The tool's content field.
	 *
	 * @return string The text.
	 */
	private static function untrustedBody(string $content): string {
		$content = str_replace([WebFetchService::UNTRUSTED_BEGIN, WebFetchService::UNTRUSTED_END], '', $content);

		return trim($content);
	}//end untrustedBody()
}//end class
