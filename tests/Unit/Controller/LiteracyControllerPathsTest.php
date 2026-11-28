<?php

/**
 * Unit tests for LiteracyController's lesson list and refusal paths.
 *
 * LiteracyControllerTest covers answering, the admin-only report and setting
 * the requirement. This file pins the lesson list (the course's lessons plus
 * whether the course is required and whether the person may administer it),
 * the CSV download, an unknown lesson, and what each route answers without a
 * session user.
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
 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\LiteracyController;
use OCA\Hermiq\Kernel\Contracts\LiteracyCourseInterface;
use OCA\Hermiq\Kernel\Contracts\LiteracyReportInterface;
use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Lesson list, CSV download and unauthenticated answers of LiteracyController.
 *
 * @covers \OCA\Hermiq\Controller\LiteracyController
 *
 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
 */
final class LiteracyControllerPathsTest extends TestCase {

	/**
	 * @var LiteracyCourseInterface&MockObject
	 */
	private LiteracyCourseInterface $course;

	/**
	 * @var LiteracyRequirementInterface&MockObject
	 */
	private LiteracyRequirementInterface $requirement;

	/**
	 * @var LiteracyReportInterface&MockObject
	 */
	private LiteracyReportInterface $report;

	/**
	 * Set up the course, requirement and report doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->course = $this->createMock(LiteracyCourseInterface::class);
		$this->requirement = $this->createMock(LiteracyRequirementInterface::class);
		$this->report = $this->createMock(LiteracyReportInterface::class);
	}//end setUp()

	/**
	 * The controller for a session user, or for no session at all.
	 *
	 * @param string|null $uid The session user, null for none.
	 *
	 * @return LiteracyController
	 */
	private function controller(?string $uid): LiteracyController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('de');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => $default);

		return new LiteracyController($request, $session, $l10n, $this->course, $this->requirement, $this->report);
	}//end controller()

	/**
	 * The lesson list is the course's lessons in the person's language, with the
	 * organisation's requirement and whether the person administers it.
	 *
	 * @return void
	 */
	public function testTheLessonsCarryTheRequirementAndAdminFlag(): void {
		$this->requirement->method('organisationOf')->with('alice')->willReturn('org-a');
		$this->requirement->expects(self::once())->method('isRequired')->with('org-a')->willReturn(true);
		$this->requirement->expects(self::once())->method('mayAdminister')->with('org-a', 'alice')->willReturn(false);
		$this->course->expects(self::once())->method('lessons')->with('alice', 'de')->willReturn(['lessons' => [['slug' => 'one']]]);

		$response = $this->controller(uid: 'alice')->lessons();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['lessons' => [['slug' => 'one']], 'required' => true, 'mayAdminister' => false],
			$response->getData()
		);
	}//end testTheLessonsCarryTheRequirementAndAdminFlag()

	/**
	 * The course's own keys win over the added flags (array union keeps the left side).
	 *
	 * @return void
	 */
	public function testTheCoursesOwnKeysAreKept(): void {
		$this->requirement->method('organisationOf')->willReturn('org-a');
		$this->requirement->method('isRequired')->willReturn(true);
		$this->requirement->method('mayAdminister')->willReturn(true);
		$this->course->method('lessons')->willReturn(['required' => 'from-course']);

		$data = $this->controller(uid: 'alice')->lessons()->getData();

		self::assertSame('from-course', $data['required']);
		self::assertTrue($data['mayAdminister']);
	}//end testTheCoursesOwnKeysAreKept()

	/**
	 * Without a session user the lessons, answers and requirement are refused 401
	 * and the course is never read.
	 *
	 * @return void
	 */
	public function testWithoutASessionUserTheRoutesAreUnauthenticated(): void {
		$this->course->expects(self::never())->method('lessons');
		$this->course->expects(self::never())->method('answer');
		$this->requirement->expects(self::never())->method('setRequired');
		$controller = $this->controller(uid: null);

		foreach ([$controller->lessons(), $controller->answer('one'), $controller->setRequirement()] as $response) {
			self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			self::assertSame(['error' => 'Unauthenticated'], $response->getData());
		}
	}//end testWithoutASessionUserTheRoutesAreUnauthenticated()

	/**
	 * Without a session user the report is refused as for a non-admin.
	 *
	 * @return void
	 */
	public function testWithoutASessionUserTheReportIsForbidden(): void {
		$this->report->expects(self::never())->method('overview');
		$this->report->expects(self::never())->method('csv');
		$controller = $this->controller(uid: null);

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->overview()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->overviewCsv()->getStatus());
	}//end testWithoutASessionUserTheReportIsForbidden()

	/**
	 * An unknown lesson is a 404 carrying the course's message, with the default choice.
	 *
	 * @return void
	 */
	public function testAnUnknownLessonIsNotFound(): void {
		$this->requirement->method('organisationOf')->willReturn('org-a');
		$this->course->expects(self::once())->method('answer')
			->with('alice', 'org-a', 'nope', 'de', -1)
			->willThrowException(new InvalidArgumentException('Unknown lesson.'));

		$response = $this->controller(uid: 'alice')->answer('nope');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Unknown lesson.'], $response->getData());
	}//end testAnUnknownLessonIsNotFound()

	/**
	 * An organisation admin downloads the report as CSV for their organisation.
	 *
	 * @return void
	 */
	public function testAnAdminDownloadsTheReportAsCsv(): void {
		$this->requirement->method('organisationOf')->willReturn('org-a');
		$this->requirement->method('mayAdminister')->with('org-a', 'alice')->willReturn(true);
		$this->report->expects(self::once())->method('csv')->with('org-a')->willReturn("userId,done\nbob,1\n");

		$response = $this->controller(uid: 'alice')->overviewCsv();

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame("userId,done\nbob,1\n", $response->render());
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnAdminDownloadsTheReportAsCsv()
}//end class
