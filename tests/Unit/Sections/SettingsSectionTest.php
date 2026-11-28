<?php

/**
 * Unit tests for SettingsSection, the Hermiq admin settings section.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Sections
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Sections;

use OCA\Hermiq\Sections\SettingsSection;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsSection.
 */
#[CoversClass(SettingsSection::class)]
#[UsesClass(AdminSettings::class)]
class SettingsSectionTest extends TestCase {
	/**
	 * The section is an icon section with a fixed id and priority.
	 *
	 * @return void
	 */
	public function testIdAndPriority(): void {
		$section = new SettingsSection($this->createMock(IL10N::class), $this->createMock(IURLGenerator::class));

		$this->assertInstanceOf(IIconSection::class, $section);
		$this->assertSame('hermiq', $section->getID());
		$this->assertSame(75, $section->getPriority());
	}//end testIdAndPriority()

	/**
	 * The name is the translated app name.
	 *
	 * @return void
	 */
	public function testNameIsTranslated(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with('Hermiq')->willReturn('Hermiq (vertaald)');

		$section = new SettingsSection($l10n, $this->createMock(IURLGenerator::class));

		$this->assertSame('Hermiq (vertaald)', $section->getName());
	}//end testNameIsTranslated()

	/**
	 * The icon is the dark app icon of the hermiq app.
	 *
	 * @return void
	 */
	public function testIconIsTheDarkAppIcon(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('imagePath')->with('hermiq', 'app-dark.svg')->willReturn('/apps/hermiq/img/app-dark.svg');

		$section = new SettingsSection($this->createMock(IL10N::class), $urls);

		$this->assertSame('/apps/hermiq/img/app-dark.svg', $section->getIcon());
	}//end testIconIsTheDarkAppIcon()

	/**
	 * The admin settings panel registers under this section's id, so the panel
	 * appears in the section rather than nowhere.
	 *
	 * @return void
	 */
	public function testAdminSettingsPointAtThisSection(): void {
		$section = new SettingsSection($this->createMock(IL10N::class), $this->createMock(IURLGenerator::class));
		$settings = new AdminSettings(
			appManager: $this->createMock(IAppManager::class),
			initialState: $this->createMock(IInitialState::class),
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->createMock(IUserSession::class),
		);

		$this->assertSame($section->getID(), $settings->getSection());
	}//end testAdminSettingsPointAtThisSection()
}//end class
