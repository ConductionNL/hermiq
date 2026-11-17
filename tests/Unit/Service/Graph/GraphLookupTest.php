<?php

/**
 * Unit tests for GraphLookup: resolving a named entity or a free-text query to
 * the graph entities the acting user may see.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use OCA\Hermiq\Service\Graph\GraphLookup;
use OCA\Hermiq\Service\Graph\GraphStore;
use OCA\Hermiq\Service\Graph\GraphTraversal;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GraphLookup.
 *
 * @covers \OCA\Hermiq\Service\Graph\GraphLookup
 */
class GraphLookupTest extends TestCase {

	private const UUID = '0b8e2c1a-3f4d-4e5a-9b6c-7d8e9f0a1b2c';

	/**
	 * Label keys the store was asked for.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * A lookup over label key → entity uuids, where only $visible resolve.
	 *
	 * @param array<string, list<string>> $byLabel Label key → uuids.
	 * @param list<string>                $visible Uuids the user may see.
	 *
	 * @return GraphLookup
	 */
	private function lookup(array $byLabel, array $visible): GraphLookup {
		$store = $this->createMock(GraphStore::class);
		$store->method('find')->willReturnCallback(function (string $schema, array $filters, int $limit) use ($byLabel): array {
			self::assertSame(GraphStore::ENTITY_SCHEMA, $schema);
			self::assertSame(20, $limit);
			$this->asked[] = $filters['labelKey'];
			return array_map(static function (string $uuid): ObjectEntity {
				$e = new ObjectEntity();
				$e->setUuid($uuid);
				return $e;
			}, ($byLabel[$filters['labelKey']] ?? []));
		});
		$traversal = $this->createMock(GraphTraversal::class);
		$traversal->method('visible')->willReturnCallback(
			static fn (string $uuid, string $uid): ?array => in_array($uuid, $visible, true) ? ['uuid' => $uuid, 'uid' => $uid] : null
		);
		return new GraphLookup($store, $traversal);
	}

	/**
	 * A uuid is resolved directly, without a label search.
	 *
	 * @return void
	 */
	public function testAUuidResolvesDirectly(): void {
		$lookup = $this->lookup([], [self::UUID]);

		self::assertSame(['uuid' => self::UUID, 'uid' => 'alice'], $lookup->named('  ' . self::UUID . ' ', 'alice'));
		self::assertNull($this->lookup([], [])->named(strtoupper(self::UUID), 'alice'));
		self::assertSame([], $this->asked);
	}

	/**
	 * A name is matched on its lower-cased label key, first visible hit.
	 *
	 * @return void
	 */
	public function testANameMatchesTheLabelKey(): void {
		$lookup = $this->lookup(['acme corp' => ['hidden', 'e-1', 'e-2']], ['e-1', 'e-2']);

		self::assertSame(['uuid' => 'e-1', 'uid' => 'alice'], $lookup->named(' Acme Corp ', 'alice'));
		self::assertSame(['acme corp'], $this->asked);
	}

	/**
	 * An unknown or invisible name is null.
	 *
	 * @return void
	 */
	public function testAnUnknownNameIsNull(): void {
		self::assertNull($this->lookup(['acme' => ['e-1']], [])->named('acme', 'bob'));
		self::assertNull($this->lookup([], [])->named('nobody', 'alice'));
	}

	/**
	 * A query is split into 1-3 word phrases of at least three characters.
	 *
	 * @return void
	 */
	public function testSeedsSearchesWordPhrases(): void {
		$lookup = $this->lookup([], []);

		$lookup->seeds('Mail jan@acme.nl about the Q3 plan!', 'alice', 5);

		self::assertSame([
			'mail', 'mail jan@acme.nl', 'mail jan@acme.nl about',
			'jan@acme.nl', 'jan@acme.nl about', 'jan@acme.nl about the',
			'about', 'about the', 'about the q3',
			'the', 'the q3', 'the q3 plan',
			'q3 plan',
			'plan',
		], $this->asked);
	}

	/**
	 * Seeds are de-duplicated by node and capped at the limit.
	 *
	 * @return void
	 */
	public function testSeedsAreUniqueAndLimited(): void {
		$lookup = $this->lookup(
			['acme' => ['e-1', 'e-2'], 'acme corp' => ['e-1', 'e-3'], 'corp' => ['e-4']],
			['e-1', 'e-2', 'e-3', 'e-4']
		);

		self::assertSame(['e-1', 'e-2', 'e-3'], array_column($lookup->seeds('acme corp', 'alice', 3), 'uuid'));
	}

	/**
	 * At most sixty phrases are searched.
	 *
	 * @return void
	 */
	public function testAtMostSixtyPhrases(): void {
		$words = array_map(static fn (int $i): string => 'word' . $i, range(1, 40));

		$this->lookup([], [])->seeds(implode(' ', $words), 'alice', 10);

		self::assertCount(60, $this->asked);
	}
}
