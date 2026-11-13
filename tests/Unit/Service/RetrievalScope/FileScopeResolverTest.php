<?php

/**
 * Unit tests for FileScopeResolver (memory-retrieval-scope, task 1).
 *
 * The query built from an agent's file filters (owners expanded from groups,
 * name pattern, dates), the search run as the person in the session, and the
 * cases that narrow to nothing instead of widening.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\RetrievalScope
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

namespace OCA\Hermiq\Tests\Unit\Service\RetrievalScope;

use OCA\Hermiq\Service\RetrievalScope\FileScopeResolver;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchQuery;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * File filters to a file set.
 */
class FileScopeResolverTest extends TestCase {

	/**
	 * Every query the folder was searched with.
	 *
	 * @var list<ISearchQuery>
	 */
	private array $queries = [];

	/**
	 * Every user folder asked for.
	 *
	 * @var list<string>
	 */
	private array $folders = [];

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * The resolver over a folder that answers the given file ids.
	 *
	 * @param list<int>   $fileIds  The ids the search returns.
	 * @param string|null $session  The uid in the session, or null.
	 * @param bool        $fail     Whether the search throws.
	 *
	 * @return FileScopeResolver
	 */
	private function resolver(array $fileIds = [], ?string $session = 'adviseur', bool $fail = false): FileScopeResolver {
		$folder = $this->createMock(Folder::class);
		$folder->method('search')->willReturnCallback(
			function (ISearchQuery $query) use ($fileIds, $fail): array {
				$this->queries[] = $query;
				if ($fail === true) {
					throw new RuntimeException('file cache gone');
				}

				return array_map(
					function (int $id): Node {
						$node = $this->createMock(Node::class);
						$node->method('getId')->willReturn($id);
						return $node;
					},
					$fileIds
				);
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->folders[] = $uid;
				return $folder;
			}
		);

		$session_ = $this->createMock(IUserSession::class);
		$session_->method('getUser')->willReturn($session === null ? null : $this->user($session));

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('jurist-a'), $this->user('jurist-b')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($gid === 'juridische-zaken') ? $group : null);

		return new FileScopeResolver($root, $session_, $groups, new NullLogger());
	}//end resolver()

	/**
	 * An owner group and a date become one AND search as the person, and the ids come back.
	 *
	 * @return void
	 */
	public function testOwnerGroupAndDateBecomeOneSearchAsThePerson(): void {
		$ids = $this->resolver(fileIds: [12, 40, 12])->resolve(
			filters: ['owners' => [['type' => 'group', 'id' => 'juridische-zaken'], ['type' => 'user', 'id' => 'jurist-a']], 'modifiedAfter' => '2025-01-01']
		);

		$this->assertSame([12, 40], $ids);
		$this->assertSame(['adviseur'], $this->folders);
		$this->assertCount(1, $this->queries);
		$query = $this->queries[0];
		$this->assertSame('adviseur', $query->getUser()->getUID());
		$this->assertFalse($query->limitToHome());
		$this->assertSame(FileScopeResolver::MAX_FILES, $query->getLimit());

		$and = $query->getSearchOperation();
		$this->assertInstanceOf(ISearchBinaryOperator::class, $and);
		$this->assertSame(ISearchBinaryOperator::OPERATOR_AND, $and->getType());
		[$owner, $after] = $and->getArguments();
		$this->assertSame([ISearchComparison::COMPARE_IN, 'owner', ['jurist-a', 'jurist-b']], [$owner->getType(), $owner->getField(), $owner->getValue()]);
		$this->assertSame([ISearchComparison::COMPARE_GREATER_THAN_EQUAL, 'mtime', 1735689600], [$after->getType(), $after->getField(), $after->getValue()]);
	}//end testOwnerGroupAndDateBecomeOneSearchAsThePerson()

	/**
	 * A name pattern is a LIKE: `*` is any text, plain text matches anywhere, and LIKE's own wildcards are escaped.
	 *
	 * @return void
	 */
	public function testANamePatternIsALike(): void {
		$resolver = $this->resolver(fileIds: [7]);

		$resolver->resolve(filters: ['namePattern' => 'mandaat*.pdf']);
		$resolver->resolve(filters: ['namePattern' => '100%_besluit', 'modifiedBefore' => '2026-01-01']);

		$single = $this->queries[0]->getSearchOperation();
		$this->assertSame([ISearchComparison::COMPARE_LIKE, 'name', 'mandaat%.pdf'], [$single->getType(), $single->getField(), $single->getValue()]);
		[$name, $before] = $this->queries[1]->getSearchOperation()->getArguments();
		$this->assertSame('%100\\%\\_besluit%', $name->getValue());
		$this->assertSame([ISearchComparison::COMPARE_LESS_THAN, 1767225600], [$before->getType(), $before->getValue()]);
	}//end testANamePatternIsALike()

	/**
	 * No filter: the search is unscoped (null) and nothing is searched.
	 *
	 * @return void
	 */
	public function testNoFilterIsUnscoped(): void {
		$resolver = $this->resolver(fileIds: [1]);

		$this->assertNull($resolver->resolve(filters: null));
		$this->assertNull($resolver->resolve(filters: []));
		$this->assertNull($resolver->resolve(filters: ['owners' => [], 'namePattern' => '  ']));
		$this->assertSame([], $this->queries);
	}//end testNoFilterIsUnscoped()

	/**
	 * Filters that can match nothing narrow to no file at all, and never run an unscoped search.
	 *
	 * @return void
	 */
	public function testFiltersThatMatchNothingSearchNoFile(): void {
		$this->assertSame([], $this->resolver(fileIds: [1])->resolve(filters: ['owners' => [['type' => 'group', 'id' => 'unknown-group']]]));
		$this->assertSame([], $this->resolver(fileIds: [1])->resolve(filters: ['modifiedAfter' => 'not a date']));
		$this->assertSame([], $this->resolver(fileIds: [1], session: null)->resolve(filters: ['namePattern' => 'x']));
		$this->assertSame([], $this->queries, 'No search runs when the filters cannot match.');

		$this->assertSame([], $this->resolver(fileIds: [])->resolve(filters: ['namePattern' => 'x']));
		$this->assertSame([], $this->resolver(fileIds: [1], fail: true)->resolve(filters: ['namePattern' => 'x']));
	}//end testFiltersThatMatchNothingSearchNoFile()
}//end class
