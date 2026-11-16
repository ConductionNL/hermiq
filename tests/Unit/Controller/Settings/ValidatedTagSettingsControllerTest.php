<?php

/**
 * Unit tests for ValidatedTagSettingsController (memory-retrieval-scope, task 2).
 *
 * Admin only (every method carries AuthorizedAdminSetting, which Nextcloud's
 * middleware turns into a 403 for anyone else), the parameters handed through,
 * and the answers for a refused value and a failure.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Controller\Settings\ValidatedTagSettingsController;
use OCA\Hermiq\Kernel\Contracts\ValidatedTagSettingsInterface;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The admin routes of the validated tag.
 */
class ValidatedTagSettingsControllerTest extends TestCase {

	/**
	 * The controller over a settings double.
	 *
	 * @param array<string, mixed>          $params   Request parameters.
	 * @param ValidatedTagSettingsInterface $settings The settings.
	 *
	 * @return ValidatedTagSettingsController
	 */
	private function controller(array $params, ValidatedTagSettingsInterface $settings): ValidatedTagSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new ValidatedTagSettingsController($request, $settings, new NullLogger());
	}//end controller()

	/**
	 * Every route is an admin setting.
	 *
	 * @return void
	 */
	public function testEveryRouteIsAdminOnly(): void {
		foreach (['get', 'update', 'create'] as $method) {
			$attributes = (new ReflectionMethod(ValidatedTagSettingsController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method . ' must be admin only');
			$this->assertSame([AdminSettings::class], $attributes[0]->getArguments());
		}
	}//end testEveryRouteIsAdminOnly()

	/**
	 * Create hands the name and groups through, with "Validated" as the default name.
	 *
	 * @return void
	 */
	public function testCreateHandsNameAndGroupsThrough(): void {
		$settings = $this->createMock(ValidatedTagSettingsInterface::class);
		$settings->expects($this->exactly(2))->method('create')->willReturnCallback(
			static fn (string $name, array $groups): array => ['tagId' => '8', 'name' => $name, 'groups' => $groups]
		);

		$named = $this->controller(['name' => 'Gevalideerd', 'groups' => ['informatiebeheer', 3]], $settings)->create();
		$default = $this->controller([], $settings)->create();

		$this->assertSame(['tagId' => '8', 'name' => 'Gevalideerd', 'groups' => ['informatiebeheer']], $named->getData());
		$this->assertSame(['tagId' => '8', 'name' => 'Validated', 'groups' => []], $default->getData());
	}//end testCreateHandsNameAndGroupsThrough()

	/**
	 * Update needs a tag; groups are passed only when given.
	 *
	 * @return void
	 */
	public function testUpdateNeedsATag(): void {
		$settings = $this->createMock(ValidatedTagSettingsInterface::class);
		$settings->expects($this->once())->method('choose')->with('7', null)->willReturn(['tagId' => '7', 'name' => 'Gevalideerd', 'groups' => []]);

		$missing = $this->controller([], $settings)->update();
		$chosen = $this->controller(['tagId' => 7], $settings)->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $missing->getStatus());
		$this->assertSame(Http::STATUS_OK, $chosen->getStatus());
		$this->assertSame('7', $chosen->getData()['tagId']);
	}//end testUpdateNeedsATag()

	/**
	 * A refused value is 422 with its reason; any other failure is 500 without internals.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailures(): void {
		$refusing = $this->createMock(ValidatedTagSettingsInterface::class);
		$refusing->method('choose')->willThrowException(new InvalidArgumentException('No system tag with this id exists.'));
		$failing = $this->createMock(ValidatedTagSettingsInterface::class);
		$failing->method('all')->willThrowException(new RuntimeException('db password in here'));

		$refused = $this->controller(['tagId' => '404'], $refusing)->update();
		$failed = $this->controller([], $failing)->get();

		$this->assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, ['error' => 'No system tag with this id exists.']], [$refused->getStatus(), $refused->getData()]);
		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Failed to read the validated tag setting']], [$failed->getStatus(), $failed->getData()]);
	}//end testRefusalsAndFailures()
}//end class
