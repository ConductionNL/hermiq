<?php

/**
 * Hermiq: an organisation may require the course Working with AI
 * (compliance-ai-literacy).
 *
 * The switch is `aiLiteracyRequired` on the organisation's TenantControl object,
 * off by default. Only an instance admin or the organisation's owner may change
 * it. `assertMayUseAgents()` is called where a person starts a chat, a run by
 * hand or a Talk session; scheduled and flow runs never call it, because they
 * run as their owner, who needed the course to set them up.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Literacy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Literacy;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use RuntimeException;
use Throwable;

/**
 * The organisation's course requirement and its guard.
 *
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
 */
class LiteracyRequirement {

	private const CONTROL_SCHEMA = 'tenantcontrol';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister's object service.
	 * @param LiteracyCourse $course The course.
	 * @param OrganisationMapper $organisations Active organisation and owner lookup.
	 * @param IGroupManager $groups Instance-admin check.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly LiteracyCourse $course,
		private readonly OrganisationMapper $organisations,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * Refuse a person who must finish the course first.
	 *
	 * @param string $uid The person.
	 *
	 * @return void
	 *
	 * @throws LiteracyRequiredException When the organisation requires the course and it is not done.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function assertMayUseAgents(string $uid): void {
		if ($this->isRequired(organisation: $this->organisationOf(uid: $uid)) === false) {
			return;
		}

		if ($this->course->isComplete(uid: $uid) === true) {
			return;
		}

		throw new LiteracyRequiredException();
	}//end assertMayUseAgents()

	/**
	 * The refusal a person gets instead of a run, or null when they may go ahead:
	 * the course message, its stable code and the course link.
	 *
	 * @param string $uid The person.
	 *
	 * @return array{message: string, errorCode: string, courseUrl: string}|null The refusal.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function refusal(string $uid): ?array {
		try {
			$this->assertMayUseAgents(uid: $uid);
		} catch (LiteracyRequiredException $e) {
			return [
				'message' => $e->getMessage(),
				'errorCode' => LiteracyRequiredException::ERROR_CODE,
				'courseUrl' => LiteracyRequiredException::COURSE_PATH,
			];
		}

		return null;
	}//end refusal()

	/**
	 * Whether an organisation requires the course.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return bool True when required.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function isRequired(string $organisation): bool {
		$control = $this->control(organisation: $organisation);

		return $control !== null && ($control->getObject()['aiLiteracyRequired'] ?? false) === true;
	}//end isRequired()

	/**
	 * Switch the requirement, as an instance admin or the organisation's owner.
	 *
	 * @param string $organisation The organisation.
	 * @param bool $required Whether the course is required.
	 * @param IUser $actor Who switches it.
	 *
	 * @return bool The stored value.
	 *
	 * @throws RuntimeException When the actor may not administer the organisation.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function setRequired(string $organisation, bool $required, IUser $actor): bool {
		if ($this->mayAdminister(organisation: $organisation, uid: $actor->getUID()) === false) {
			throw new RuntimeException('Only an admin of this organisation may change the course requirement.', 403);
		}

		$control = $this->control(organisation: $organisation);
		$data = ['engaged' => false];
		$uuid = null;
		if ($control !== null) {
			$data = $control->getObject();
			$uuid = (string)$control->getUuid();
		}

		$data['aiLiteracyRequired'] = $required;
		$data['@self'] = ['organisation' => $organisation];
		$this->objects->saveObject(
			object: $data,
			register: LiteracyCourse::REGISTER_SLUG,
			schema: self::CONTROL_SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return $required;
	}//end setRequired()

	/**
	 * Whether a person may administer an organisation: an instance admin, or its owner.
	 *
	 * @param string $organisation The organisation.
	 * @param string $uid The person.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function mayAdminister(string $organisation, string $uid): bool {
		if ($organisation === '') {
			return false;
		}

		if ($this->groups->isAdmin($uid) === true) {
			return true;
		}

		try {
			return (string)($this->organisations->findByUuid($organisation)->getOwner() ?? '') === $uid;
		} catch (Throwable) {
			return false;
		}
	}//end mayAdminister()

	/**
	 * The person's active organisation, '' when none.
	 *
	 * @param string $uid The person.
	 *
	 * @return string The organisation.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function organisationOf(string $uid): string {
		try {
			return (string)($this->organisations->getActiveOrganisationWithFallback($uid) ?? '');
		} catch (Throwable) {
			return '';
		}
	}//end organisationOf()

	/**
	 * The organisation's TenantControl object, or null.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function control(string $organisation): ?ObjectEntity {
		if ($organisation === '') {
			return null;
		}

		$objects = $this->objects->setRegister(LiteracyCourse::REGISTER_SLUG)->setSchema(self::CONTROL_SCHEMA)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity && (string)($object->getOrganisation() ?? '') === $organisation) {
				return $object;
			}
		}

		return null;
	}//end control()

}//end class
