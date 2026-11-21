<?php

/**
 * Unit tests for FormFillController (forms-smart-paste-fill): authentication,
 * the allowlist and the limits, before the engine is called.
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
 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-a-signed-in-user-asks-for-proposals-req-ffill-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\FormFillController;
use OCA\Hermiq\Kernel\Contracts\FormFillEngineInterface;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Controller\FormFillController
 */
class FormFillControllerTest extends TestCase {

	/**
	 * The engine double.
	 *
	 * @var FormFillEngineInterface&MockObject
	 */
	private FormFillEngineInterface&MockObject $engine;

	protected function setUp(): void {
		parent::setUp();
		$this->engine = $this->createMock(FormFillEngineInterface::class);
	}//end setUp()

	/**
	 * A controller for this user (null: nobody signed in) and these request parameters.
	 *
	 * @param string|null $uid The signed-in user.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return FormFillController
	 */
	private function controller(?string $uid, array $params): FormFillController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		return new FormFillController(request: $request, userSession: $session, engine: $this->engine, logger: $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * A valid request body.
	 *
	 * @return array<string, mixed>
	 */
	private static function valid(): array {
		return [
			'text' => 'Jansen BV, Kerkstraat 1',
			'fields' => [
				['key' => 'naam', 'label' => "Naam\nvan het bedrijf", 'type' => 'text'],
				['key' => 'soort', 'label' => 'Soort', 'type' => 'select', 'options' => ['particulier', ['value' => 'zakelijk', 'label' => 'Zakelijk']]],
			],
			'language' => 'nl',
			'organisation' => 'not read',
		];
	}//end valid()

	public function testNoSignedInUserIsRefusedBeforeAnythingIsRead(): void {
		$this->engine->expects($this->never())->method('fill');
		$this->engine->expects($this->never())->method('isAvailable');

		$this->assertSame(401, $this->controller(uid: null, params: self::valid())->fill()->getStatus());
		$this->assertSame(401, $this->controller(uid: null, params: [])->available()->getStatus());
	}//end testNoSignedInUserIsRefusedBeforeAnythingIsRead()

	public function testOnlyTheAllowlistedFieldsReachTheEngine(): void {
		$this->engine->expects($this->once())->method('fill')->with(
			'Jansen BV, Kerkstraat 1',
			[
				['key' => 'naam', 'label' => 'Naam van het bedrijf', 'type' => 'text', 'options' => []],
				['key' => 'soort', 'label' => 'Soort', 'type' => 'select', 'options' => ['particulier', 'zakelijk']],
			],
			'nl',
			'alice'
		)->willReturn(['available' => true, 'values' => [], 'draft' => true, 'notice' => 'n', 'provider' => 'p']);

		$response = $this->controller(uid: 'alice', params: self::valid())->fill();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['draft']);
	}//end testOnlyTheAllowlistedFieldsReachTheEngine()

	/**
	 * Each unusable input answers 400 and never reaches the engine.
	 *
	 * @return void
	 */
	public function testInputOutsideTheLimitsIs400BeforeTheEngine(): void {
		$tooMany = [];
		for ($i = 0; $i < 51; $i++) {
			$tooMany[] = ['key' => 'f' . $i, 'label' => 'F', 'type' => 'text'];
		}

		$cases = [
			'10,001 characters' => ['text' => str_repeat('a', 10001)],
			'no text' => ['text' => ' '],
			'no fields' => ['fields' => []],
			'51 fields' => ['fields' => $tooMany],
			'a bad key' => ['fields' => [['key' => 'naam; drop', 'label' => 'N', 'type' => 'text']]],
			'a repeated key' => ['fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text'], ['key' => 'a', 'label' => 'B', 'type' => 'text']]],
			'a bad type' => ['fields' => [['key' => 'a', 'label' => 'A', 'type' => 'Text Area!']]],
			'an option that is no text' => ['fields' => [['key' => 'a', 'label' => 'A', 'type' => 'select', 'options' => [true]]]],
			'a bad language' => ['language' => 'nl; en'],
		];
		$this->engine->expects($this->never())->method('fill');

		foreach ($cases as $name => $override) {
			$response = $this->controller(uid: 'alice', params: array_merge(self::valid(), $override))->fill();
			$this->assertSame(400, $response->getStatus(), $name);
			$this->assertArrayHasKey('error', $response->getData(), $name);
		}
	}//end testInputOutsideTheLimitsIs400BeforeTheEngine()

	public function testExactlyTheLimitsAreAccepted(): void {
		$fifty = [];
		for ($i = 0; $i < 50; $i++) {
			$fifty[] = ['key' => 'f' . $i, 'label' => 'F', 'type' => 'text'];
		}

		$this->engine->expects($this->once())->method('fill')->willReturn(['available' => false, 'reason' => 'feature-not-enabled']);

		$response = $this->controller(uid: 'alice', params: ['text' => str_repeat('a', 10000), 'fields' => $fifty])->fill();

		$this->assertSame(200, $response->getStatus());
	}//end testExactlyTheLimitsAreAccepted()

	public function testAvailabilityAsksTheGateOnly(): void {
		$this->engine->expects($this->never())->method('fill');
		$this->engine->method('isAvailable')->willReturn(false);

		$response = $this->controller(uid: 'alice', params: [])->available();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['available' => false], $response->getData());
	}//end testAvailabilityAsksTheGateOnly()

	public function testAnEngineFailureIs500WithoutTheContent(): void {
		$this->engine->method('fill')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller(uid: 'alice', params: self::valid())->fill();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Could not propose form values'], $response->getData());
	}//end testAnEngineFailureIs500WithoutTheContent()

	public function testTheEndpointsDeclareTheirAuthPostureAndRateLimit(): void {
		$fill = new ReflectionMethod(FormFillController::class, 'fill');
		$this->assertCount(1, $fill->getAttributes(NoAdminRequired::class));
		$this->assertCount(1, $fill->getAttributes(UserRateLimit::class));
		$this->assertSame(['limit' => 30, 'period' => 60], $fill->getAttributes(UserRateLimit::class)[0]->getArguments());
		$this->assertCount(1, (new ReflectionMethod(FormFillController::class, 'available'))->getAttributes(NoAdminRequired::class));
	}//end testTheEndpointsDeclareTheirAuthPostureAndRateLimit()
}//end class
