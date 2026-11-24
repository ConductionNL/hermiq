<?php

/**
 * Hermiq FederatedCatalogSearch.
 *
 * The store's repository search through OpenRegister's shared engine,
 * `FederatedConfigService::discoverOutcome()`, when this instance has it.
 * The engine sends the free-text term to GitHub, names hermiq and the acting
 * user on a brokered call, falls back to an anonymous read when the broker
 * refuses, and says how the search went. Its answer is handed back in the shape
 * GitHubTemplateCatalogService reads a GitHub answer in, so cards, cache and the
 * connection report stay one code path. Without the engine (an older
 * OpenRegister), or when it throws, there is no answer and the store searches
 * GitHub itself as before.
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
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Search the store through OpenRegister's federated config engine.
 *
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
 */
final class FederatedCatalogSearch {

	/**
	 * The engine outcome for a rate-limited search.
	 */
	private const RATE_LIMITED = 'rate_limited';

	/**
	 * Constructor.
	 *
	 * @param FederatedEngineLocator $locator Finds the engine and builds the broker identity.
	 * @param LoggerInterface        $logger  Where a failed engine search is noted.
	 */
	public function __construct(
		private readonly FederatedEngineLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Search one topic through the engine.
	 *
	 * @param string      $topic        The discovery topic, with or without the `topic:` qualifier.
	 * @param string      $term         The trimmed free-text term, '' for none.
	 * @param CatalogCaller $caller     Who the search reads for.
	 * @param int         $max          The most hits to keep.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool,items:array<int,array<string,mixed>>}|null
	 *         The answer in the catalogue's read shape, or null when the engine is absent or failed.
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
	 */
	public function search(string $topic, string $term, CatalogCaller $caller, int $max): ?array {
		$engine = $this->locator->engineWith(method: 'discoverOutcome');
		if ($engine === null) {
			return null;
		}

		try {
			$identity = $this->locator->identityFor(actingUserId: $caller->actingUserId);
			$answer = $engine->discoverOutcome(
				topic: self::bareTopic(topic: $topic),
				term: $term,
				credentialId: $caller->credentialId,
				identity: $identity
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FederatedCatalogSearch] engine search failed, searching GitHub directly: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return self::readShape(answer: (array)$answer, max: $max);
	}//end search()

	/**
	 * A topic without its `topic:` qualifier: the engine adds it itself.
	 *
	 * @param string $topic The topic.
	 *
	 * @return string The bare topic.
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
	 */
	public static function bareTopic(string $topic): string {
		$topic = trim($topic);
		if (str_starts_with($topic, 'topic:') === true) {
			return substr($topic, strlen('topic:'));
		}

		return $topic;
	}//end bareTopic()

	/**
	 * The engine's answer in the catalogue's read shape: the outcome flags, and
	 * each card back as the search hit the catalogue builds its own card from.
	 *
	 * @param array<string,mixed> $answer The engine's `{outcome, cards, brokerUsed, rateLimited, status}`.
	 * @param int                 $max    The most hits to keep.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool,items:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
	 */
	public static function readShape(array $answer, int $max): array {
		$rateLimited = (($answer['rateLimited'] ?? false) === true) || (($answer['outcome'] ?? '') === self::RATE_LIMITED);
		$items = [];
		foreach (array_slice((array)($answer['cards'] ?? []), 0, $max) as $card) {
			if (is_array($card) === true) {
				$items[] = self::hitOf(card: $card);
			}
		}

		return [
			'ok' => (($answer['outcome'] ?? '') === 'ok'),
			'status' => (int)($answer['status'] ?? 0),
			'body' => '',
			'rateLimited' => $rateLimited,
			'brokerUsed' => (($answer['brokerUsed'] ?? false) === true),
			'items' => $items,
		];
	}//end readShape()

	/**
	 * One engine card as a GitHub search hit.
	 *
	 * @param array<string,mixed> $card `{repo, name, description, url, stars, updated, topics}`.
	 *
	 * @return array<string,mixed> The hit.
	 */
	private static function hitOf(array $card): array {
		return [
			'full_name' => (string)($card['repo'] ?? ''),
			'name' => (string)($card['name'] ?? ''),
			'description' => (string)($card['description'] ?? ''),
			'html_url' => (string)($card['url'] ?? ''),
			'stargazers_count' => (int)($card['stars'] ?? 0),
			'updated_at' => (string)($card['updated'] ?? ''),
			'topics' => array_values((array)($card['topics'] ?? [])),
		];
	}//end hitOf()
}//end class
