<?php

/**
 * Pins `hermiq.listDeckBoards` against the `__call()` magic-accessor defect.
 *
 * ⚠️ THE DOUBLE HERE IS DELIBERATELY AWKWARD, AND THAT IS THE WHOLE POINT.
 * `OCA\Deck\Db\Board` declares `protected $title` and NO `getTitle()` — the getter
 * is routed by `OCP\AppFramework\Db\Entity::__call()`. A double that simply
 * declares `public function getTitle()` would make `method_exists()` return TRUE
 * in the suite and FALSE in production, inverting the exact predicate under test
 * and keeping this test green against the bug. So `MagicOnlyBoard` reaches its
 * getter the way the real collaborator does.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use BadFunctionCallException;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A board whose `getTitle()` exists ONLY through `__call()`, exactly as
 * `OCA\Deck\Db\Board` does. `method_exists($b, 'getTitle')` is FALSE here,
 * `property_exists($b, 'title')` is TRUE — the real thing behaves identically,
 * verified live on the dev instance with `jsonSerialize` as a concrete control.
 */
class MagicOnlyBoard {

	/**
	 * The board title, reachable only magically.
	 *
	 * @var string
	 */
	protected $title = '';

	/**
	 * Construct with a title.
	 *
	 * @param string $title The title.
	 */
	public function __construct(string $title) {
		$this->title = $title;

	}//end __construct()

	/**
	 * Route `getX()` to property `x`, the way Entity::getter() does.
	 *
	 * @param string $name The method name.
	 * @param array $args The arguments.
	 *
	 * @return mixed The property value.
	 *
	 * @throws BadFunctionCallException When no such property exists.
	 */
	public function __call(string $name, array $args) {
		if (str_starts_with($name, 'get') === true) {
			$prop = lcfirst(substr($name, 3));
			if (property_exists($this, $prop) === true) {
				return $this->$prop;
			}
		}

		throw new BadFunctionCallException($name . ' is not a valid attribute');
	}//end __call()

}//end class

/**
 * A stand-in for Deck's BoardService.
 */
class FakeBoardService {

	/**
	 * The boards to return.
	 *
	 * @var array
	 */
	private array $boards;

	/**
	 * Construct with boards.
	 *
	 * @param array $boards The boards.
	 */
	public function __construct(array $boards) {
		$this->boards = $boards;

	}//end __construct()

	/**
	 * Return the boards.
	 *
	 * @return array The boards.
	 */
	public function findAll(): array {
		return $this->boards;
	}//end findAll()

}//end class

/**
 * Tests that Deck boards actually reach the tool's answer.
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
 */
class DeckBoardMagicAccessorTest extends TestCase {

	/**
	 * Build a provider whose container serves a Deck BoardService with $boards.
	 *
	 * @param array $boards The boards Deck reports.
	 *
	 * @return HermiqToolProvider The provider under test.
	 */
	private function providerWithBoards(array $boards): HermiqToolProvider {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($boards) {
				if ($id === 'OCA\\Deck\\Service\\BoardService') {
					return new FakeBoardService($boards);
				}

				throw new \RuntimeException('not available in this test: ' . $id);
			}
		);

		return new HermiqToolProvider(
			$session,
			$this->createMock(IRootFolder::class),
			$this->createMock(IContactsManager::class),
			$this->createMock(ICalendarManager::class),
			$this->createMock(IMailer::class),
			$apps,
			$container,
			$this->createMock(CourseRecommendationEngine::class),
			$this->createMock(MemoryService::class),
			$this->createMock(WebSearchClient::class),
			$this->createMock(WebFetchService::class),
			$this->createMock(DelegationService::class),
			$this->createMock(NcNativeWriteService::class),
			$this->createMock(MailReadService::class),
			$this->createMock(ToolAccessRequestService::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end providerWithBoards()

	/**
	 * 🔴 THE REGRESSION. A board whose getter is magic must still be listed.
	 *
	 * With the old `method_exists($board, 'getTitle')` guard this returns an
	 * EMPTY list — which is indistinguishable from "this user has no boards",
	 * which is why it went unnoticed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
	 */
	public function testABoardWhoseGetterIsMagicIsStillListed(): void {
		$result = $this->providerWithBoards(
			[new MagicOnlyBoard('Fleet Green'), new MagicOnlyBoard('Backlog')]
		)->invokeTool('hermiq.listDeckBoards', []);

		$this->assertSame(
			[['title' => 'Fleet Green'], ['title' => 'Backlog']],
			$result['boards'],
			'A Deck board reaches its title through __call(); method_exists() cannot see it.'
		);

	}//end testABoardWhoseGetterIsMagicIsStillListed()

	/**
	 * The double is FAITHFUL — this is the control that makes the test above
	 * mean something. If `method_exists()` were true on the double, the test
	 * would pass against the very bug it exists to catch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
	 */
	public function testTheDoubleReachesItsGetterMagicallyLikeTheRealBoard(): void {
		$board = new MagicOnlyBoard('Fleet Green');

		$this->assertFalse(method_exists($board, 'getTitle'), 'The double must NOT declare getTitle().');
		$this->assertTrue(property_exists($board, 'title'), 'The property is what the framework consults.');
		$this->assertSame('Fleet Green', $board->getTitle(), 'The getter still works, magically.');

	}//end testTheDoubleReachesItsGetterMagicallyLikeTheRealBoard()

	/**
	 * An object with neither the property nor a usable getter is skipped rather
	 * than fatalling the whole tool — `property_exists()` is the decision and the
	 * call is exception-safe, so a stray object cannot take the listing down.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
	 */
	public function testAnUnreadableEntryIsSkippedRatherThanFatal(): void {
		$result = $this->providerWithBoards(
			[new \stdClass(), new MagicOnlyBoard('Survivor')]
		)->invokeTool('hermiq.listDeckBoards', []);

		$this->assertSame([['title' => 'Survivor']], $result['boards']);

	}//end testAnUnreadableEntryIsSkippedRatherThanFatal()

}//end class
