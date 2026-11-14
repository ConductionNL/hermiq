<?php

/**
 * Hermiq AiLabelController: marking a labelled file as reviewed, and the counts.
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
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AiLabelController;
use OCA\Hermiq\Service\AiLabelStatsService;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the AI label routes.
 */
class AiLabelControllerTest extends TestCase {

	/**
	 * The marker double.
	 *
	 * @var AgentArtefactMarker&MockObject
	 */
	private AgentArtefactMarker $marker;

	/**
	 * Set up the marker double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->marker = $this->createMock(AgentArtefactMarker::class);
	}//end setUp()

	/**
	 * The controller for alice, whose Files hold file 42 (readable or not).
	 *
	 * @param bool                     $readable Whether alice can read file 42.
	 * @param AiLabelStatsService|null $stats    The counts service.
	 * @param bool                     $signedIn Whether anyone is signed in.
	 *
	 * @return AiLabelController
	 */
	private function controller(bool $readable = true, ?AiLabelStatsService $stats = null, bool $signedIn = true): AiLabelController {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn($readable);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(static fn (int $id): array => ($id === 42 ? [$file] : []));
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($folder);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		return new AiLabelController(
			request: $this->createMock(IRequest::class),
			marker: $this->marker,
			stats: ($stats ?? $this->createMock(AiLabelStatsService::class)),
			rootFolder: $root,
			userSession: $session
		);
	}//end controller()

	/**
	 * An agent-authored file the caller can read is marked as reviewed.
	 *
	 * @return void
	 */
	public function testAReadableLabelledFileIsMarked(): void {
		$this->marker->method('isFileMarked')->with(42)->willReturn(true);
		$this->marker->expects($this->once())->method('markReviewed')->with(42);

		$response = $this->controller()->markReviewed(fileId: 42);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['fileId' => 42, 'reviewed' => true], $response->getData());
	}

	/**
	 * A file the caller cannot read, or cannot find, is 404 and its tags are not read or changed.
	 *
	 * @return void
	 */
	public function testAFileTheCallerCannotReadIs404(): void {
		$this->marker->expects($this->never())->method('isFileMarked');
		$this->marker->expects($this->never())->method('markReviewed');

		$this->assertSame(404, $this->controller(readable: false)->markReviewed(fileId: 42)->getStatus());
		$this->assertSame(404, $this->controller()->markReviewed(fileId: 7)->getStatus());
	}

	/**
	 * A readable file without the agent-authored tag is 409 and gets no reviewed tag.
	 *
	 * @return void
	 */
	public function testAFileWithoutTheLabelIs409(): void {
		$this->marker->method('isFileMarked')->willReturn(false);
		$this->marker->expects($this->never())->method('markReviewed');

		$response = $this->controller()->markReviewed(fileId: 42);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'This file is not marked as made with AI.'], $response->getData());
	}

	/**
	 * A failed tag write is an error, not a success.
	 *
	 * @return void
	 */
	public function testAFailedMarkIs500(): void {
		$this->marker->method('isFileMarked')->willReturn(true);
		$this->marker->method('markReviewed')->willThrowException(new ArtefactMarkingFailedException('The file could not be marked as reviewed.'));

		$this->assertSame(500, $this->controller()->markReviewed(fileId: 42)->getStatus());
	}

	/**
	 * The counts are the caller's; nobody signed in gets 401 on both routes.
	 *
	 * @return void
	 */
	public function testStatsAreTheCallersAndNeedASignIn(): void {
		$stats = $this->createMock(AiLabelStatsService::class);
		$stats->expects($this->once())->method('stats')->with('alice')
			->willReturn(['files' => 4, 'reviewed' => 1, 'reviewedShare' => 25, 'tagId' => '3', 'truncated' => false]);

		$this->assertSame(25, $this->controller(stats: $stats)->stats()->getData()['reviewedShare']);
		$this->assertSame(401, $this->controller(signedIn: false)->stats()->getStatus());
		$this->assertSame(401, $this->controller(signedIn: false)->markReviewed(fileId: 42)->getStatus());
	}
}
