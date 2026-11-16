<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\KnowledgeBaseController;
use OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseException;
use OCA\Hermiq\Kernel\Contracts\KnowledgeBaseServiceInterface;
use OCA\Hermiq\Service\KnowledgeBase\SourceSharingInspector;
use OCA\Hermiq\Service\KnowledgeBase\VectorFacadeProbe;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The knowledge base routes: the caller, the upload slots and the refusals.
 */
#[CoversClass(KnowledgeBaseController::class)]
#[UsesClass(KnowledgeBaseException::class)]
class KnowledgeBaseControllerTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var KnowledgeBaseServiceInterface&MockObject */
	private KnowledgeBaseServiceInterface $service;

	/** @var SourceSharingInspector&MockObject */
	private SourceSharingInspector $inspector;

	/** @var VectorFacadeProbe&MockObject */
	private VectorFacadeProbe $probe;

	protected function setUp(): void {
		$this->request   = $this->createMock(IRequest::class);
		$this->service   = $this->createMock(KnowledgeBaseServiceInterface::class);
		$this->inspector = $this->createMock(SourceSharingInspector::class);
		$this->probe     = $this->createMock(VectorFacadeProbe::class);
	}//end setUp()

	private function controller(?string $uid = 'alice'): KnowledgeBaseController {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new KnowledgeBaseController(request: $this->request, service: $this->service, inspector: $this->inspector, probe: $this->probe, userSession: $session);
	}//end controller()

	public function testSignedOutIsRefusedWithoutTouchingTheService(): void {
		$this->service->expects($this->never())->method('upload');

		self::assertSame(401, $this->controller(uid: null)->upload(id: 'kb-1')->getStatus());
	}//end testSignedOutIsRefusedWithoutTouchingTheService()

	public function testAMultiFileUploadIsSplitIntoSlotsForTheCaller(): void {
		$this->request->method('getUploadedFile')->willReturnMap([
			['files', ['name' => ['a.pdf', 'b.pdf'], 'tmp_name' => ['/t/a', '/t/b'], 'size' => [1, 2], 'error' => [0, 0]]],
		]);
		$this->service->expects($this->once())->method('upload')->with(
			'kb-1',
			'alice',
			[
				['name' => 'a.pdf', 'tmp_name' => '/t/a', 'size' => 1, 'error' => 0],
				['name' => 'b.pdf', 'tmp_name' => '/t/b', 'size' => 2, 'error' => 0],
			]
		)->willReturn([['type' => 'file', 'fileId' => 7]]);

		$response = $this->controller()->upload(id: 'kb-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['results' => [['type' => 'file', 'fileId' => 7]]], $response->getData());
	}//end testAMultiFileUploadIsSplitIntoSlotsForTheCaller()

	public function testANonOwnersUploadIsNotFound(): void {
		$single = ['name' => 'a.pdf', 'tmp_name' => '/t/a', 'size' => 1, 'error' => 0];
		$this->request->method('getUploadedFile')->willReturnMap([['files', null], ['file', $single]]);
		$this->service->expects($this->once())->method('upload')->with('kb-1', 'bob', [$single])
			->willThrowException(new KnowledgeBaseException('Knowledge base not found.', 404));

		$response = $this->controller(uid: 'bob')->upload(id: 'kb-1');

		self::assertSame(404, $response->getStatus());
		self::assertSame(['error' => 'Knowledge base not found.'], $response->getData());
	}//end testANonOwnersUploadIsNotFound()

	public function testTheSourcesCarryTheirIndexStateAndWhetherSearchIsAvailable(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['sources' => []]);
		$this->service->expects($this->once())->method('owned')->with('kb-1', 'alice')->willReturn($entity);
		$this->inspector->method('inspect')->with($this->callback(static fn (array $kb): bool => ($kb['sources'] ?? null) === []), 'alice')->willReturn([
			['type' => 'file', 'fileId' => 7, 'onlyYou' => true, 'missing' => false],
			['type' => 'objects', 'register' => 'woo', 'schema' => 'besluit'],
		]);
		$this->probe->expects($this->once())->method('indexStatus')->with([7])->willReturn([7 => 'indexed']);
		$this->probe->method('canSearchFiles')->willReturn(false);

		$response = $this->controller()->sources(id: 'kb-1');

		self::assertSame(
			[
				'results' => [
					['type' => 'file', 'fileId' => 7, 'onlyYou' => true, 'missing' => false, 'indexState' => 'indexed'],
					['type' => 'objects', 'register' => 'woo', 'schema' => 'besluit', 'indexState' => null],
				],
				'searchAvailable' => false,
			],
			$response->getData()
		);
	}//end testTheSourcesCarryTheirIndexStateAndWhetherSearchIsAvailable()

	public function testANonOwnerReadsNoSources(): void {
		$this->service->method('owned')->willThrowException(new KnowledgeBaseException('Knowledge base not found.', 404));
		$this->probe->expects($this->never())->method('canSearchFiles');

		self::assertSame(404, $this->controller(uid: 'bob')->sources(id: 'kb-1')->getStatus());
	}//end testANonOwnerReadsNoSources()
}//end class
