<?php

/**
 * Tests for LiteracyController (compliance-ai-literacy).
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\LiteracyController;
use OCA\Hermiq\Service\Literacy\LiteracyCourse;
use OCA\Hermiq\Service\Literacy\LiteracyReport;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The person's own record, and the admin-only report.
 *
 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
 */
final class LiteracyControllerTest extends TestCase {

	/**
	 * The controller for alice in org-a.
	 *
	 * @param LiteracyCourse $course The course double.
	 * @param bool $mayAdminister Whether alice administers org-a.
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @return LiteracyController
	 */
	private function controller(LiteracyCourse $course, bool $mayAdminister, array $params = []): LiteracyController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('nl');
		$requirement = $this->createMock(LiteracyRequirement::class);
		$requirement->method('organisationOf')->willReturn('org-a');
		$requirement->method('mayAdminister')->willReturn($mayAdminister);
		$requirement->method('setRequired')->willReturnCallback(
			static function (string $organisation, bool $required) use ($mayAdminister): bool {
				if ($mayAdminister === false) {
					throw new RuntimeException('Only an admin of this organisation may change the course requirement.', 403);
				}

				return $required;
			}
		);
		$report = $this->createMock(LiteracyReport::class);
		$report->method('overview')->willReturn([['userId' => 'bob', 'done' => 1, 'total' => 6, 'complete' => false]]);

		return new LiteracyController($request, $session, $l10n, $course, $requirement, $report);

	}//end controller()

	/**
	 * An answer is always recorded for the session user, in their language and organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function testAnAnswerIsRecordedForTheSessionUser(): void {
		$course = $this->createMock(LiteracyCourse::class);
		$course->expects($this->once())->method('answer')
			->with('alice', 'org-a', 'check-the-sources', 'nl', 1)
			->willReturn(['correct' => true]);

		$response = $this->controller($course, false, ['choice' => '1', 'userId' => 'bob'])->answer('check-the-sources');

		$this->assertSame(['correct' => true], $response->getData());

	}//end testAnAnswerIsRecordedForTheSessionUser()

	/**
	 * The report is for the organisation's admin only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function testTheReportIsForTheOrganisationAdminOnly(): void {
		$course = $this->createMock(LiteracyCourse::class);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller($course, false)->overview()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller($course, false)->overviewCsv()->getStatus());

		$allowed = $this->controller($course, true)->overview();
		$this->assertSame(Http::STATUS_OK, $allowed->getStatus());
		$this->assertSame('bob', $allowed->getData()['people'][0]['userId']);

	}//end testTheReportIsForTheOrganisationAdminOnly()

	/**
	 * Only an organisation admin may switch the requirement; the stored value comes back.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function testOnlyTheOrganisationAdminMaySetTheRequirement(): void {
		$course = $this->createMock(LiteracyCourse::class);

		$refused = $this->controller($course, false, ['required' => true])->setRequirement();
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());

		$stored = $this->controller($course, true, ['required' => true])->setRequirement();
		$this->assertSame(Http::STATUS_OK, $stored->getStatus());
		$this->assertSame(['organisation' => 'org-a', 'required' => true], $stored->getData());

	}//end testOnlyTheOrganisationAdminMaySetTheRequirement()

}//end class
