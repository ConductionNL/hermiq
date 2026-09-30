<?php

/**
 * Hermiq Working with AI controller (compliance-ai-literacy).
 *
 * The lessons and the answer check for the signed-in person, always for their
 * own record, and the organisation report and requirement switch for an
 * instance admin or the organisation's owner.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Literacy\LiteracyCourse;
use OCA\Hermiq\Service\Literacy\LiteracyReport;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Lessons, answers, report and requirement.
 *
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
 */
class LiteracyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The signed-in person.
	 * @param IL10N $l10n Their language.
	 * @param LiteracyCourse $course The course.
	 * @param LiteracyRequirement $requirement The requirement and the admin rule.
	 * @param LiteracyReport $report The organisation report.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LiteracyCourse $course,
		private readonly LiteracyRequirement $requirement,
		private readonly LiteracyReport $report,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The lessons in the person's language, with their own progress.
	 *
	 * @return JSONResponse The lessons.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	#[NoAdminRequired]
	public function lessons(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = $this->requirement->organisationOf(uid: $uid);

		return new JSONResponse(
			$this->course->lessons(uid: $uid, locale: $this->l10n->getLanguageCode()) + [
				'required' => $this->requirement->isRequired(organisation: $organisation),
				'mayAdminister' => $this->requirement->mayAdminister(organisation: $organisation, uid: $uid),
			]
		);
	}//end lessons()

	/**
	 * Check the person's answer to one lesson; a right one records their completion.
	 *
	 * The slug names a lesson, which every signed-in person may read; the
	 * completion is always written for the session user, never for a user in
	 * the request.
	 *
	 * @param string $slug The lesson.
	 *
	 * @return JSONResponse The result.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	#[NoAdminRequired]
	public function answer(string $slug): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->course->answer(
				uid: $uid,
				organisation: $this->requirement->organisationOf(uid: $uid),
				slug: $slug,
				locale: $this->l10n->getLanguageCode(),
				choice: (int)$this->request->getParam('choice', -1)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end answer()

	/**
	 * Completion per person in the admin's organisation.
	 *
	 * @return JSONResponse The rows, or 403.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$organisation = $this->administeredOrganisation();
		if ($organisation === null) {
			return new JSONResponse(['error' => 'Only an admin of this organisation may see the course report.'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			[
				'organisation' => $organisation,
				'required' => $this->requirement->isRequired(organisation: $organisation),
				'people' => $this->report->overview(organisation: $organisation),
			]
		);
	}//end overview()

	/**
	 * The same report as CSV.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or 403.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	#[NoAdminRequired]
	public function overviewCsv(): DataDownloadResponse|JSONResponse {
		$organisation = $this->administeredOrganisation();
		if ($organisation === null) {
			return new JSONResponse(['error' => 'Only an admin of this organisation may see the course report.'], Http::STATUS_FORBIDDEN);
		}

		return new DataDownloadResponse($this->report->csv(organisation: $organisation), 'working-with-ai.csv', 'text/csv');
	}//end overviewCsv()

	/**
	 * Switch whether the organisation requires the course.
	 *
	 * @return JSONResponse The stored value, or 403.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	#[NoAdminRequired]
	public function setRequirement(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = $this->requirement->organisationOf(uid: $user->getUID());
		try {
			$required = $this->requirement->setRequired(
				organisation: $organisation,
				required: ($this->request->getParam('required') === true),
				actor: $user
			);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['organisation' => $organisation, 'required' => $required]);
	}//end setRequirement()

	/**
	 * The session user's id, or null.
	 *
	 * @return string|null The uid.
	 */
	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end uid()

	/**
	 * The session user's organisation when they may administer it, else null.
	 *
	 * @return string|null The organisation.
	 */
	private function administeredOrganisation(): ?string {
		$uid = $this->uid();
		if ($uid === null) {
			return null;
		}

		$organisation = $this->requirement->organisationOf(uid: $uid);
		if ($this->requirement->mayAdminister(organisation: $organisation, uid: $uid) === false) {
			return null;
		}

		return $organisation;
	}//end administeredOrganisation()

}//end class
