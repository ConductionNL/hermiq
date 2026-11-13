<?php

/**
 * Hermiq LiteracyRequirement unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Literacy
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

namespace OCA\Hermiq\Tests\Unit\Service\Literacy;

use OCA\Hermiq\Service\Literacy\LiteracyCourse;
use OCA\Hermiq\Service\Literacy\LiteracyRequiredException;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for who must finish the course, and who may switch the requirement.
 *
 * @covers \OCA\Hermiq\Service\Literacy\LiteracyRequirement
 * @uses   \OCA\Hermiq\Service\Literacy\LiteracyRequiredException
 */
class LiteracyRequirementTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The course double.
	 *
	 * @var LiteracyCourse&MockObject
	 */
	private LiteracyCourse&MockObject $course;

	/**
	 * The organisation mapper double.
	 *
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper&MockObject $organisations;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * Wire the doubles over these tenant controls.
	 *
	 * @param list<mixed> $controls The stored tenant controls.
	 *
	 * @return LiteracyRequirement The requirement.
	 */
	private function requirement(array $controls = []): LiteracyRequirement {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willReturn($controls);
		$this->course = $this->createMock(LiteracyCourse::class);
		$this->organisations = $this->createMock(OrganisationMapper::class);
		$this->groups = $this->createMock(IGroupManager::class);

		return new LiteracyRequirement($this->objects, $this->course, $this->organisations, $this->groups);
	}//end requirement()

	/**
	 * A tenant control.
	 *
	 * @param string               $organisation The organisation.
	 * @param array<string, mixed> $data         The payload.
	 *
	 * @return ObjectEntity The control.
	 */
	private function control(string $organisation, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ctl-' . $organisation);
		$entity->setOrganisation($organisation);
		$entity->setObject($data);
		return $entity;
	}//end control()

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Only a control with aiLiteracyRequired === true requires the course; no organisation never does.
	 *
	 * @return void
	 */
	public function testOnlyAStrictTrueFlagRequiresTheCourse(): void {
		$requirement = $this->requirement(
			[
				'junk',
				$this->control('org-on', ['aiLiteracyRequired' => true]),
				$this->control('org-truthy', ['aiLiteracyRequired' => 1]),
				$this->control('org-off', []),
			]
		);

		$this->assertTrue($requirement->isRequired('org-on'));
		$this->assertFalse($requirement->isRequired('org-truthy'));
		$this->assertFalse($requirement->isRequired('org-off'));
		$this->assertFalse($requirement->isRequired('org-none'));
		$this->assertFalse($requirement->isRequired(''));
	}//end testOnlyAStrictTrueFlagRequiresTheCourse()

	/**
	 * A user whose organisation requires the course and who has not finished it is refused.
	 *
	 * @return void
	 */
	public function testAnUnfinishedCourseIsRefusedWhereRequired(): void {
		$requirement = $this->requirement([$this->control('org-on', ['aiLiteracyRequired' => true])]);
		$this->organisations->method('getActiveOrganisationWithFallback')->willReturnMap([['alice', 'org-on'], ['bob', 'org-on']]);
		$this->course->method('isComplete')->willReturnMap([['alice', false], ['bob', true]]);

		$this->assertSame(
			['message' => 'Finish the short course Working with AI first.', 'errorCode' => 'ai_literacy_required', 'courseUrl' => '/apps/hermiq/ai-literacy'],
			$requirement->refusal('alice')
		);
		$this->assertNull($requirement->refusal('bob'));

		$this->expectException(LiteracyRequiredException::class);
		$requirement->assertMayUseAgents('alice');
	}//end testAnUnfinishedCourseIsRefusedWhereRequired()

	/**
	 * Where the course is not required, completion is never checked; an unresolvable organisation counts as none.
	 *
	 * @return void
	 */
	public function testNoRequirementSkipsTheCourseCheck(): void {
		$requirement = $this->requirement([$this->control('org-on', ['aiLiteracyRequired' => true])]);
		$this->organisations->method('getActiveOrganisationWithFallback')->willThrowException(new RuntimeException('no session'));
		$this->course->expects($this->never())->method('isComplete');

		$this->assertSame('', $requirement->organisationOf('alice'));
		$this->assertNull($requirement->refusal('alice'));
	}//end testNoRequirementSkipsTheCourseCheck()

	/**
	 * Instance admins and the organisation owner may administer; others, a failed lookup and no organisation may not.
	 *
	 * @return void
	 */
	public function testWhoMayAdminister(): void {
		$requirement = $this->requirement();
		$this->groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$owned = $this->createMock(Organisation::class);
		$owned->method('getOwner')->willReturn('owner');
		$this->organisations->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($owned): Organisation {
				if ($uuid !== 'org-a') {
					throw new RuntimeException('not found');
				}

				return $owned;
			}
		);

		$this->assertTrue($requirement->mayAdminister('org-a', 'root'));
		$this->assertTrue($requirement->mayAdminister('org-a', 'owner'));
		$this->assertFalse($requirement->mayAdminister('org-a', 'member'));
		$this->assertFalse($requirement->mayAdminister('org-x', 'owner'));
		$this->assertFalse($requirement->mayAdminister('', 'root'));
	}//end testWhoMayAdminister()

	/**
	 * A non-administrator cannot change the requirement and nothing is saved.
	 *
	 * @return void
	 */
	public function testANonAdministratorCannotChangeTheRequirement(): void {
		$requirement = $this->requirement();
		$this->groups->method('isAdmin')->willReturn(false);
		$this->organisations->method('findByUuid')->willThrowException(new RuntimeException('not found'));
		$this->objects->expects($this->never())->method('saveObject');

		try {
			$requirement->setRequired('org-a', true, $this->user('member'));
			$this->fail('Expected a refusal');
		} catch (RuntimeException $e) {
			$this->assertSame('Only an admin of this organisation may change the course requirement.', $e->getMessage());
			$this->assertSame(403, $e->getCode());
		}
	}//end testANonAdministratorCannotChangeTheRequirement()

	/**
	 * An admin creates a control pinned to the organisation, or updates the existing one in place.
	 *
	 * @return void
	 */
	public function testAnAdminCreatesOrUpdatesTheControl(): void {
		$requirement = $this->requirement([$this->control('org-b', ['engaged' => true, 'reason' => 'x'])]);
		$this->groups->method('isAdmin')->willReturn(true);
		$saved = [];
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $rbac = true, bool $multi = true) use (&$saved): ObjectEntity {
				$saved[] = [$object, $register, $schema, $uuid, $rbac, $multi];
				return new ObjectEntity();
			}
		);

		$this->assertTrue($requirement->setRequired('org-a', true, $this->user('root')));
		$this->assertFalse($requirement->setRequired('org-b', false, $this->user('root')));

		$this->assertSame(
			[['engaged' => false, 'aiLiteracyRequired' => true, '@self' => ['organisation' => 'org-a']], 'hermiq', 'tenantcontrol', null, false, false],
			$saved[0]
		);
		$this->assertSame('ctl-org-b', $saved[1][3]);
		$this->assertTrue($saved[1][0]['engaged']);
		$this->assertSame('x', $saved[1][0]['reason']);
		$this->assertFalse($saved[1][0]['aiLiteracyRequired']);
	}//end testAnAdminCreatesOrUpdatesTheControl()
}//end class
