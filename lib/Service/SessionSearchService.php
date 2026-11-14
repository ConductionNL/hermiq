<?php

/**
 * SessionSearchService: find a person's own sessions by their title or what was said in them.
 *
 * Search runs over the sessions the caller may already list, and over the turns
 * OpenRegister's text search finds among the caller's own turns, so a shared
 * session owned by someone else never answers a search.
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
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Matches a term against the titles of the caller's own sessions and the text
 * of the caller's own turns in them.
 *
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
 */
class SessionSearchService {

	/**
	 * The shortest term that searches; a shorter one leaves the list as it is.
	 *
	 * @var integer
	 */
	public const MIN_TERM_LENGTH = 2;

	/**
	 * The most turns one search reads.
	 *
	 * @var integer
	 */
	public const MAX_TURN_SCAN = 1000;

	/**
	 * Characters of context on each side of the term in an excerpt.
	 *
	 * @var integer
	 */
	public const EXCERPT_CONTEXT = 60;

	/**
	 * The register that holds sessions and turns.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The turn schema.
	 *
	 * @var string
	 */
	private const TURN_SCHEMA = 'agentsessionturn';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object reads.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Whether a term is long enough to search with.
	 *
	 * @param string $term The term as given.
	 *
	 * @return bool True when it searches.
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
	 */
	public static function searches(string $term): bool {
		return mb_strlen(trim($term)) >= self::MIN_TERM_LENGTH;
	}//end searches()

	/**
	 * The caller's own sessions among `$sessions` that match the term, in the
	 * order given, each with its match: the first matching turn and an excerpt,
	 * else `title: true` for a title-only hit.
	 *
	 * @param string         $userId   The caller.
	 * @param string         $term     The search term.
	 * @param ObjectEntity[] $sessions The sessions the caller may list in the current tab.
	 *
	 * @return list<array{session: ObjectEntity, match: array<string, mixed>}> The hits.
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
	 */
	public function search(string $userId, string $term, array $sessions): array {
		$term = trim($term);
		$own = [];
		foreach ($sessions as $session) {
			if (($session instanceof ObjectEntity) === true && ($session->getObject()['userId'] ?? null) === $userId) {
				$own[(string)$session->getUuid()] = $session;
			}
		}

		if ($own === [] || self::searches(term: $term) === false) {
			return [];
		}

		$turnMatches = $this->turnMatches(userId: $userId, term: $term, sessionIds: array_keys($own));

		$hits = [];
		foreach ($own as $uuid => $session) {
			$match = ($turnMatches[$uuid] ?? null);
			if ($match === null && mb_stripos((string)($session->getObject()['title'] ?? ''), $term) !== false) {
				$match = ['title' => true];
			}

			if ($match !== null) {
				$hits[] = ['session' => $session, 'match' => $match];
			}
		}

		return $hits;
	}//end search()

	/**
	 * The first matching turn per session, among the caller's own turns in the given sessions.
	 *
	 * A turn counts only when both its owner is the caller and its session is
	 * one of the caller's own: neither key alone keeps a participant's turn in
	 * someone else's session out.
	 *
	 * @param string             $userId     The caller.
	 * @param string             $term       The search term.
	 * @param array<int, string> $sessionIds The caller's own session uuids.
	 *
	 * @return array<string, array{turnUuid: string, excerpt: string}> By session uuid.
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
	 */
	private function turnMatches(string $userId, string $term, array $sessionIds): array {
		$turns = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::TURN_SCHEMA)
			->findAll(
				config: [
					'filters' => ['@self.owner' => $userId],
					'search' => $term,
					'sort' => ['created' => 'ASC'],
					'limit' => self::MAX_TURN_SCAN,
				]
			);

		$allowed = array_flip($sessionIds);
		$matches = [];
		foreach ($turns as $turn) {
			if (($turn instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $turn->getObject();
			$sessionId = (string)($data['sessionId'] ?? '');
			if (isset($allowed[$sessionId]) === false || isset($matches[$sessionId]) === true) {
				continue;
			}

			$matches[$sessionId] = [
				'turnUuid' => (string)$turn->getUuid(),
				'excerpt' => self::excerpt(text: (string)($data['content'] ?? ''), term: $term),
			];
		}

		return $matches;
	}//end turnMatches()

	/**
	 * A short excerpt of a text around the first occurrence of a term, on one line.
	 *
	 * Starts at the beginning when the term is not in the text itself (the
	 * search matched another field of the turn).
	 *
	 * @param string $text The turn text.
	 * @param string $term The term.
	 *
	 * @return string The excerpt, with an ellipsis where text was cut.
	 *
	 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
	 */
	public static function excerpt(string $text, string $term): string {
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));
		$position = mb_stripos($text, $term);
		if ($position === false) {
			$position = 0;
		}

		$start = max(0, ($position - self::EXCERPT_CONTEXT));
		$length = (mb_strlen($term) + (2 * self::EXCERPT_CONTEXT));
		$excerpt = mb_substr($text, $start, $length);

		if ($start > 0) {
			$excerpt = '…' . ltrim($excerpt);
		}

		if (($start + $length) < mb_strlen($text)) {
			$excerpt = rtrim($excerpt) . '…';
		}

		return $excerpt;
	}//end excerpt()
}//end class
