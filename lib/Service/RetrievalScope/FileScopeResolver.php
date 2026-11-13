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

		$conditions = [];
		$set        = false;
		if (is_array($filters['owners'] ?? null) === true && $filters['owners'] !== []) {
			$set    = true;
			$owners = $this->ownerIds(owners: $filters['owners']);
			if ($owners === []) {
				return [];
			}

			$conditions[] = new FileSearchComparison(type: ISearchComparison::COMPARE_IN, field: 'owner', value: $owners);
		}

		$pattern = trim((string)($filters['namePattern'] ?? ''));
		if ($pattern !== '') {
			$set          = true;
			$conditions[] = new FileSearchComparison(
				type: ISearchComparison::COMPARE_LIKE,
				field: 'name',
				value: self::likePattern(pattern: $pattern)
			);
		}

		$dates = ['modifiedAfter' => ISearchComparison::COMPARE_GREATER_THAN_EQUAL, 'modifiedBefore' => ISearchComparison::COMPARE_LESS_THAN];
		foreach ($dates as $key => $type) {
			if (trim((string)($filters[$key] ?? '')) === '') {
				continue;
			}

			$set  = true;
			$time = self::timestamp(date: (string)$filters[$key]);
			if ($time === null) {
				return [];
			}

			$conditions[] = new FileSearchComparison(type: $type, field: 'mtime', value: $time);
		}

		if ($set === false) {
			return null;
		}

		return $conditions;
	}//end conditions()

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

		$ids = [];
		foreach ($nodes as $node) {
			$id = $node->getId();
			if (is_int($id) === true && $id > 0) {
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}//end search()

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
			if (is_array($owner) === false || is_string($owner['id'] ?? null) === false || trim($owner['id']) === '') {
				continue;
			}

			$id = trim($owner['id']);
			if (($owner['type'] ?? 'user') === 'group') {
				foreach (($this->groups->get($id)?->getUsers() ?? []) as $member) {
					$ids[$member->getUID()] = true;
				}

				continue;
			}

			$ids[$id] = true;
		}

		return array_map('strval', array_keys($ids));
	}//end ownerIds()

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
