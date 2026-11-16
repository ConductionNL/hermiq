<?php

/**
 * Hermiq File Scope Resolver.
 *
 * Resolves an agent's file filters (memory-retrieval-scope, D1) into the set of
 * files a turn may search: one Nextcloud file search as the person in the
 * session, with the owner among the listed users or group members, the name
 * like the pattern, and the modified time inside the dates. The answer is null
 * when the agent sets no filter (the search is not scoped), and a list of file
 * ids otherwise; an empty list means that no file is searched, never an
 * unscoped search. A filter that cannot be resolved (no person, an owner list
 * that names nobody, a failing search) narrows to nothing rather than widening.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\RetrievalScope
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\RetrievalScope;

use DateTimeImmutable;
use DateTimeZone;
use OCP\Files\IRootFolder;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Agent file filters to a file id set.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */
class FileScopeResolver {

	/**
	 * The most files one scope holds.
	 */
	public const MAX_FILES = 1000;

	/**
	 * Constructor.
	 *
	 * @param IRootFolder     $rootFolder  Nextcloud's file tree.
	 * @param IUserSession    $userSession The person in the session.
	 * @param IGroupManager   $groups      Group membership for group owners.
	 * @param LoggerInterface $logger      Logger.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The file ids the filters allow, or null when the agent sets no filter.
	 *
	 * @param mixed $filters The agent's `ragFileFilters`.
	 *
	 * @return list<int>|null
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function resolve(mixed $filters): ?array {
		$conditions = $this->conditions(filters: $filters);
		if ($conditions === null) {
			return null;
		}

		$user = $this->userSession->getUser();
		if ($conditions === [] || $user === null) {
			return [];
		}

		return $this->search(user: $user, conditions: $conditions);
	}//end resolve()

	/**
	 * The search conditions, null when no filter is set, [] when a filter can match nothing.
	 *
	 * @param mixed $filters The agent's `ragFileFilters`.
	 *
	 * @return list<ISearchOperator>|null
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function conditions(mixed $filters): ?array {
		if (is_array($filters) === false) {
			return null;
		}

		$parts = [
			$this->ownerConditions(filters: $filters),
			self::nameConditions(filters: $filters),
			self::dateConditions(filters: $filters),
		];

		$parts = array_filter($parts, static fn (array|false|null $part): bool => $part !== null);
		if ($parts === []) {
			return null;
		}

		if (in_array(false, $parts, true) === true) {
			return [];
		}

		return array_merge(...array_values($parts));
	}//end conditions()

	/**
	 * The owner condition: null when no owner is set, false when none of them resolves.
	 *
	 * @param array<string, mixed> $filters The agent's file filters.
	 *
	 * @return list<FileSearchComparison>|false|null
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	private function ownerConditions(array $filters): array|false|null {
		if (is_array($filters['owners'] ?? null) === false || $filters['owners'] === []) {
			return null;
		}

		$owners = $this->ownerIds(owners: $filters['owners']);
		if ($owners === []) {
			return false;
		}

		return [new FileSearchComparison(type: ISearchComparison::COMPARE_IN, field: 'owner', value: $owners)];
	}//end ownerConditions()

	/**
	 * The file-name condition: null when no pattern is set.
	 *
	 * @param array<string, mixed> $filters The agent's file filters.
	 *
	 * @return list<FileSearchComparison>|null
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	private static function nameConditions(array $filters): ?array {
		$pattern = trim((string)($filters['namePattern'] ?? ''));
		if ($pattern === '') {
			return null;
		}

		return [
			new FileSearchComparison(
				type: ISearchComparison::COMPARE_LIKE,
				field: 'name',
				value: self::likePattern(pattern: $pattern)
			),
		];
	}//end nameConditions()

	/**
	 * The modified-date conditions: null when no date is set, false when a set date does not parse.
	 *
	 * @param array<string, mixed> $filters The agent's file filters.
	 *
	 * @return list<FileSearchComparison>|false|null
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	private static function dateConditions(array $filters): array|false|null {
		$dates      = ['modifiedAfter' => ISearchComparison::COMPARE_GREATER_THAN_EQUAL, 'modifiedBefore' => ISearchComparison::COMPARE_LESS_THAN];
		$conditions = null;
		foreach ($dates as $key => $type) {
			if (trim((string)($filters[$key] ?? '')) === '') {
				continue;
			}

			$time = self::timestamp(date: (string)$filters[$key]);
			if ($time === null) {
				return false;
			}

			$conditions[] = new FileSearchComparison(type: $type, field: 'mtime', value: $time);
		}

		return $conditions;
	}//end dateConditions()

	/**
	 * Run the search as the person and collect the file ids.
	 *
	 * @param IUser                 $user       The person.
	 * @param list<ISearchOperator> $conditions The conditions.
	 *
	 * @return list<int>
	 */
	private function search(IUser $user, array $conditions): array {
		$operation = $conditions[0];
		if (count($conditions) > 1) {
			$operation = new FileSearchAnd(arguments: $conditions);
		}

		try {
			$nodes = $this->rootFolder->getUserFolder($user->getUID())->search(
				new FileSearchQuery(operation: $operation, limit: self::MAX_FILES, user: $user)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FileScopeResolver] The file filters could not be resolved; no file is searched',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}

		return self::fileIdsOf(nodes: $nodes);
	}//end search()

	/**
	 * The distinct positive ids of the found nodes, in the order found.
	 *
	 * @param iterable<\OCP\Files\Node> $nodes The found nodes.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	private static function fileIdsOf(iterable $nodes): array {
		$ids = [];
		foreach ($nodes as $node) {
			$id = $node->getId();
			if (is_int($id) === true && $id > 0) {
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}//end fileIdsOf()

	/**
	 * The user ids the owner filter names: users as given, groups as their members.
	 *
	 * @param array<mixed> $owners Entries `{type: user|group, id}`.
	 *
	 * @return list<string>
	 */
	private function ownerIds(array $owners): array {
		$ids = [];
		foreach ($owners as $owner) {
			foreach ($this->ownerUids(owner: $owner) as $uid) {
				$ids[$uid] = true;
			}
		}

		return array_map('strval', array_keys($ids));
	}//end ownerIds()

	/**
	 * The user ids one owner entry names: a user as given, a group as its members,
	 * nothing for an entry without an id.
	 *
	 * @param mixed $owner One owner entry (`{id, type}`).
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	private function ownerUids(mixed $owner): array {
		$id = self::ownerId(owner: $owner);
		if ($id === '') {
			return [];
		}

		if (($owner['type'] ?? 'user') !== 'group') {
			return [$id];
		}

		$members = ($this->groups->get($id)?->getUsers() ?? []);

		return array_map(static fn ($member): string => (string)$member->getUID(), $members);
	}//end ownerUids()

	/**
	 * The trimmed id of an owner entry, or '' when it has none.
	 *
	 * @param mixed $owner One owner entry.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	private static function ownerId(mixed $owner): string {
		if (is_array($owner) === false || is_string($owner['id'] ?? null) === false) {
			return '';
		}

		return trim($owner['id']);
	}//end ownerId()

	/**
	 * A name pattern as a LIKE: `*` is any text, and a pattern without `*` matches anywhere in the name.
	 *
	 * @param string $pattern The pattern.
	 *
	 * @return string
	 */
	private static function likePattern(string $pattern): string {
		$escaped = addcslashes($pattern, '%_\\');
		if (str_contains($pattern, '*') === false) {
			return '%' . $escaped . '%';
		}

		return str_replace('*', '%', $escaped);
	}//end likePattern()

	/**
	 * A date (`Y-m-d`, or a full ISO time) as a UTC timestamp, null when unreadable.
	 *
	 * @param string $date The date.
	 *
	 * @return int|null
	 */
	private static function timestamp(string $date): ?int {
		$date = trim($date);
		$utc  = new DateTimeZone('UTC');
		$day  = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $utc);
		if ($day !== false && $day->format('Y-m-d') === $date) {
			return $day->getTimestamp();
		}

		try {
			return (new DateTimeImmutable($date, $utc))->getTimestamp();
		} catch (Throwable) {
			return null;
		}
	}//end timestamp()
}//end class
