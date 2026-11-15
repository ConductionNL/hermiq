<?php

/**
 * Unit tests for SourceLedger (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
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

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use DateTimeImmutable;
use OCA\Hermiq\Service\Research\SourceLedger;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\SourceLedger
 */
final class SourceLedgerTest extends TestCase {

	/**
	 * A webFetch success result, delimited as the tool returns it.
	 *
	 * @param string $url  The URL.
	 * @param string $text The page text.
	 *
	 * @return array<string, mixed>
	 */
	private static function fetched(string $url, string $text): array {
		return [
			'url'       => $url,
			'truncated' => false,
			'content'   => WebFetchService::UNTRUSTED_BEGIN . "\n" . $text . "\n" . WebFetchService::UNTRUSTED_END,
		];
	}

	private static function at(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-13T10:00:00+00:00');
	}

	public function testAFetchedPageIsNumberedWithItsUrlTitleTimeAndText(): void {
		$ledger = new SourceLedger();

		self::assertSame(1, $ledger->record(self::fetched('https://a.example/register', 'Utrecht publiceert 12 algoritmen.'), 'Algoritmeregister Utrecht', self::at()));
		self::assertSame(2, $ledger->record(self::fetched('https://b.example/', 'Amsterdam publiceert er 40.'), '', self::at()));

		self::assertSame(
			['number' => 1, 'url' => 'https://a.example/register', 'title' => 'Algoritmeregister Utrecht', 'fetchedAt' => '2026-11-13T10:00:00+00:00', 'text' => 'Utrecht publiceert 12 algoritmen.'],
			$ledger->source(1)
		);
		self::assertSame('https://b.example/', $ledger->source(2)['title'], 'a page without a title is named by its URL');
		self::assertSame(2, $ledger->count());
	}

	public function testARefusedFetchIsNotListed(): void {
		$ledger = new SourceLedger();

		self::assertNull($ledger->record(['error' => ['code' => 'egress_blocked', 'message' => 'refused']], 'Intranet', self::at()));
		self::assertNull($ledger->record(self::fetched('https://empty.example/', '   '), 'Empty', self::at()));
		self::assertNull($ledger->record(['content' => 'text without a url'], 'No url', self::at()));

		self::assertSame(0, $ledger->count());
		self::assertNull($ledger->source(1));
	}

	public function testTheSameUrlKeepsItsFirstNumber(): void {
		$ledger = new SourceLedger();
		$ledger->record(self::fetched('https://a.example/', 'First read.'), 'A', self::at());
		$ledger->record(self::fetched('https://b.example/', 'Other.'), 'B', self::at());

		self::assertSame(1, $ledger->record(self::fetched('https://a.example/', 'Second read.'), 'A again', self::at()));
		self::assertSame(2, $ledger->count());
		self::assertSame('First read.', $ledger->source(1)['text']);
	}

	public function testTheLedgerSurvivesTheHandoffAndRenumbersAroundBadRows(): void {
		$ledger = new SourceLedger();
		$ledger->record(self::fetched('https://a.example/', 'Alpha.'), 'A', self::at());
		$ledger->record(self::fetched('https://b.example/', 'Beta.'), 'B', self::at());

		self::assertEquals($ledger, SourceLedger::fromArray($ledger->toArray()));

		$read = SourceLedger::fromArray(['broken', ['url' => '', 'text' => 'x'], ...$ledger->toArray()]);
		self::assertSame(2, $read->count());
		self::assertSame('https://a.example/', $read->source(1)['url']);
		self::assertSame(2, $read->source(2)['number']);
	}

	public function testTheSourcesListNamesNumberTitleUrlAndTime(): void {
		$ledger = new SourceLedger();
		self::assertSame('', $ledger->sourcesList());

		$ledger->record(self::fetched('https://a.example/register', 'Alpha.'), 'Algoritmeregister Utrecht', self::at());

		self::assertSame(
			"## Sources\n\n1. Algoritmeregister Utrecht, <https://a.example/register>, read 2026-11-13T10:00:00+00:00",
			$ledger->sourcesList()
		);
	}
}
