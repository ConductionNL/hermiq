<?php

/**
 * CustomToolController rights tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\CustomToolController;
use OCA\Hermiq\Service\CustomTool\CustomToolService;
use OCA\Hermiq\Tests\Unit\Service\CustomTool\CustomToolDefinitionTest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Only an organisation admin or an instance admin creates or edits a custom tool.
 */
final class CustomToolControllerTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private array $saves = [];

	/**
	 * The controller, for a caller in the voorbeeld organisation.
	 *
	 * @param string               $uid     The caller.
	 * @param bool                 $isAdmin Whether the caller is an instance admin.
	 * @param array<string, mixed> $params  The request parameters.
	 *
	 * @return CustomToolController
	 */
	private function controller(string $uid, bool $isAdmin=false, array $params=[]): CustomToolController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		// The voorbeeld organisation is owned by "beheerder"; see the guardrail test for why this is a subclass.
		$organisation = new class ('beheerder') extends Organisation {
			/**
			 * @param string $ownerUid The owning uid.
			 */
			public function __construct(private string $ownerUid) {
			}

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return $this->ownerUid;
			}
		};
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($organisation): Organisation {
				if ($uuid !== 'org-voorbeeld') {
					throw new \RuntimeException('not found');
				}

				return $organisation;
			}
		);
		$mapper->method('getActiveOrganisationWithFallback')->willReturn('org-voorbeeld');

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn([]);
		$objects->method('find')->willReturnCallback(
			static function (mixed $id): ?ObjectEntity {
				if ($id !== 'tool-other') {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('tool-other');
				$entity->setOrganisation('org-other');
				$entity->setObject(CustomToolDefinitionTest::vergunningcheck());
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null): ObjectEntity {
				$this->saves[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'new-uuid');
				$entity->setOrganisation((string)($object['@self']['organisation'] ?? ''));
				$entity->setObject($object);
				return $entity;
			}
		);

		return new CustomToolController(
			request: $request,
			customTools: new CustomToolService(objectService: $objects, logger: new NullLogger()),
			userSession: $session,
			groupManager: $groups,
			organisationMapper: $mapper,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * The owner of the organisation adds the permit check.
	 *
	 * @return void
	 */
	public function testTheOrganisationAdminCreatesATool(): void {
		$response = $this->controller(uid: 'beheerder', params: CustomToolDefinitionTest::vergunningcheck())->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('hermiq.custom_vergunningcheck', $response->getData()['toolId']);
		$this->assertSame('org-voorbeeld', $response->getData()['organisation']);
	}//end testTheOrganisationAdminCreatesATool()

	/**
	 * An agent owner who does not administer the organisation is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnAgentOwnerCannotCreateOrList(): void {
		$controller = $this->controller(uid: 'agent-eigenaar', params: CustomToolDefinitionTest::vergunningcheck());

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->create()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->index()->getStatus());
		$this->assertSame([], $this->saves);
	}//end testAnAgentOwnerCannotCreateOrList()

	/**
	 * Naming another organisation does not widen the caller's rights.
	 *
	 * @return void
	 */
	public function testNamingAnotherOrganisationIsRefused(): void {
		$params = CustomToolDefinitionTest::vergunningcheck() + ['organisation' => 'org-other'];

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'beheerder', params: $params)->create()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'beheerder', params: CustomToolDefinitionTest::vergunningcheck())->update(id: 'tool-other')->getStatus());
		$this->assertSame([], $this->saves);
	}//end testNamingAnotherOrganisationIsRefused()

	/**
	 * An instance admin may edit a tool of any organisation; an unknown tool is 404.
	 *
	 * @return void
	 */
	public function testAnInstanceAdminEditsAnyOrganisationsTool(): void {
		$controller = $this->controller(uid: 'admin', isAdmin: true, params: CustomToolDefinitionTest::vergunningcheck());

		$this->assertSame(Http::STATUS_OK, $controller->update(id: 'tool-other')->getStatus());
		$this->assertSame(['organisation' => 'org-other'], $this->saves[0]['@self']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->update(id: 'no-such-tool')->getStatus());
	}//end testAnInstanceAdminEditsAnyOrganisationsTool()

	/**
	 * A nested input is refused with the allowed types named.
	 *
	 * @return void
	 */
	public function testAnInvalidToolIsABadRequest(): void {
		$params = CustomToolDefinitionTest::vergunningcheck();
		$params['inputSchema']['properties']['adres'] = ['type' => 'object'];

		$response = $this->controller(uid: 'beheerder', params: $params)->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('string, number, integer, boolean', $response->getData()['error']);
		$this->assertSame([], $this->saves);
	}//end testAnInvalidToolIsABadRequest()
}//end class
