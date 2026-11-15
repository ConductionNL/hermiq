<?php

/**
 * Unit tests for the remaining paths of AssistantPromptController.
 *
 * The prompt library is read by every assistant and written by an administrator
 * only. What these tests pin is what each endpoint answers once the caller is
 * through the gate: the scope a read is narrowed to, the fields a save passes
 * on and nothing else, the scope a disable-all applies to, and that a failing
 * library is a 500 that is logged, never a partial answer.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AssistantPromptController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\Assistant\AssistantPromptLibrary;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The prompt library controller once the caller is through the gate.
 */
#[CoversClass(AssistantPromptController::class)]
class AssistantPromptControllerPathsTest extends TestCase {

	/**
	 * A request answering these parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest The request.
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		return $request;
	}//end request()

	/**
	 * A session for one signed-in user.
	 *
	 * @param string|null $uid The user id, or null for nobody.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid='admin'): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The controller over these collaborators, with an action gate that admits.
	 *
	 * @param array<string, mixed>   $params  The request parameters.
	 * @param AssistantPromptLibrary $library The library.
	 * @param LoggerInterface|null   $logger  The logger.
	 * @param IUserSession|null      $session The session.
	 *
	 * @return AssistantPromptController The controller.
	 */
	private function controller(array $params, AssistantPromptLibrary $library, ?LoggerInterface $logger=null, ?IUserSession $session=null): AssistantPromptController {
		return new AssistantPromptController(
			$this->request($params),
			$library,
			$this->createMock(ActionAuthService::class),
			$session ?? $this->session(),
			$logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A scoped read asks the library for the trimmed scope only.
	 *
	 * @return void
	 */
	public function testAScopedReadAsksForTheTrimmedScope(): void {
		$prompts = [['id' => 'p1', 'label' => 'Summarise']];
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->once())->method('forScope')->with('lesson')->willReturn($prompts);
		$library->expects($this->never())->method('all');

		$response = $this->controller(['scope' => '  lesson  '], $library)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $prompts, 'total' => 1], $response->getData());
	}//end testAScopedReadAsksForTheTrimmedScope()

	/**
	 * A blank or non-string scope reads the whole library.
	 *
	 * @return void
	 */
	public function testABlankScopeReadsTheWholeLibrary(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->never())->method('forScope');
		$library->expects($this->exactly(2))->method('all')->willReturn([['id' => 'a'], ['id' => 'b']]);

		$this->assertSame(['results' => [['id' => 'a'], ['id' => 'b']], 'total' => 2], $this->controller(['scope' => '   '], $library)->index()->getData());
		$this->assertSame(2, $this->controller(['scope' => ['lesson']], $library)->index()->getData()['total']);
	}//end testABlankScopeReadsTheWholeLibrary()

	/**
	 * A library that cannot be read is a logged 500.
	 *
	 * @return void
	 */
	public function testAnUnreadableLibraryIsALogged500(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->method('all')->willThrowException(new RuntimeException('register gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Hermiq prompt library read failed: register gone');

		$response = $this->controller([], $library, $logger)->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not read the prompt library'], $response->getData());
	}//end testAnUnreadableLibraryIsALogged500()

	/**
	 * A save passes on only the prompt fields that were sent.
	 *
	 * @return void
	 */
	public function testASavePassesOnOnlyTheSentPromptFields(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->once())->method('upsert')
			->with('p1', ['label' => 'Summarise', 'enabled' => false, 'order' => 3])
			->willReturn(['id' => 'p1', 'label' => 'Summarise']);

		$response = $this->controller(
			['label' => 'Summarise', 'enabled' => false, 'order' => 3, 'owner' => 'mallory', 'id' => 'p9'],
			$library
		)->save('p1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'p1', 'label' => 'Summarise'], $response->getData());
	}//end testASavePassesOnOnlyTheSentPromptFields()

	/**
	 * A new prompt is saved without an id.
	 *
	 * @return void
	 */
	public function testANewPromptIsSavedWithoutAnId(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->once())->method('upsert')
			->with(null, ['label' => 'New', 'prompt' => 'Do it', 'usageScope' => 'lesson'])
			->willReturn(['id' => 'p2']);

		$this->assertSame(['id' => 'p2'], $this->controller(['label' => 'New', 'prompt' => 'Do it', 'usageScope' => 'lesson'], $library)->save()->getData());
	}//end testANewPromptIsSavedWithoutAnId()

	/**
	 * A failed save is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsALogged500(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->method('upsert')->willThrowException(new RuntimeException('conflict'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Hermiq prompt save failed: conflict');

		$response = $this->controller(['label' => 'X'], $library, $logger)->save('p1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not save the prompt'], $response->getData());
	}//end testAFailedSaveIsALogged500()

	/**
	 * Nobody signed in saves nothing.
	 *
	 * @return void
	 */
	public function testNobodySignedInSavesNothing(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->never())->method('upsert');

		$response = $this->controller(['label' => 'X'], $library, null, $this->session(null))->save('p1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testNobodySignedInSavesNothing()

	/**
	 * Disabling all narrows to the trimmed scope, else every scope, as the actor.
	 *
	 * @return void
	 */
	public function testDisableAllNarrowsToTheScopeAsTheActor(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->exactly(2))->method('disableAll')
			->willReturnCallback(
				static fn (?string $usageScope, string $actor): int => ($usageScope === 'lesson' && $actor === 'admin') ? 4 : (($usageScope === null && $actor === 'admin') ? 9 : -1)
			);

		$scoped = $this->controller(['scope' => ' lesson '], $library)->disableAll();
		$every = $this->controller(['scope' => ''], $library)->disableAll();

		$this->assertSame(['disabled' => 4, 'scope' => 'lesson'], $scoped->getData());
		$this->assertSame(['disabled' => 9, 'scope' => '*'], $every->getData());
	}//end testDisableAllNarrowsToTheScopeAsTheActor()

	/**
	 * A failed disable-all is a logged 500, and nobody signed in disables nothing.
	 *
	 * @return void
	 */
	public function testAFailedDisableAllIsALogged500(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->method('disableAll')->willThrowException(new RuntimeException('locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Hermiq prompt disable-all failed: locked');

		$response = $this->controller([], $library, $logger)->disableAll();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not disable the prompts'], $response->getData());

		$anonymous = $this->controller([], $this->createMock(AssistantPromptLibrary::class), null, $this->session(null))->disableAll();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->getStatus());
	}//end testAFailedDisableAllIsALogged500()
}//end class
