<?php

/**
 * Unit tests for the InstallTalkBot repair step.
 *
 * The step reports whether the Talk bot was registered, reports Talk being
 * absent, and turns an installer failure into a warning instead of failing
 * the upgrade.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\InstallTalkBot;
use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for InstallTalkBot.
 *
 * @covers \OCA\Hermiq\Repair\InstallTalkBot
 */
class InstallTalkBotTest extends TestCase {

	/**
	 * The step has a human-readable name.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new InstallTalkBot($this->createMock(TalkBotInstaller::class), $this->createMock(LoggerInterface::class));
		self::assertSame('Register the Hermiq Talk bot', $step->getName());
	}

	/**
	 * A successful install says the bot is registered.
	 *
	 * @return void
	 */
	public function testReportsARegisteredBot(): void {
		$installer = $this->createMock(TalkBotInstaller::class);
		$installer->expects(self::once())->method('install')->willReturn(true);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with('Hermiq Talk bot registered — enable it per conversation in Talk.');
		$output->expects(self::never())->method('warning');

		(new InstallTalkBot($installer, $this->createMock(LoggerInterface::class)))->run($output);
	}

	/**
	 * Without Talk the step skips and says so.
	 *
	 * @return void
	 */
	public function testReportsTalkBeingAbsent(): void {
		$installer = $this->createMock(TalkBotInstaller::class);
		$installer->method('install')->willReturn(false);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with('Talk is not available — skipping the Hermiq Talk bot registration.');

		(new InstallTalkBot($installer, $this->createMock(LoggerInterface::class)))->run($output);
	}

	/**
	 * An installer failure is logged and reported as a warning, not thrown.
	 *
	 * @return void
	 */
	public function testAFailureBecomesAWarning(): void {
		$installer = $this->createMock(TalkBotInstaller::class);
		$installer->method('install')->willThrowException(new RuntimeException('spreed broke'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'[InstallTalkBot] Could not register the Hermiq Talk bot',
			self::callback(static fn (array $context): bool => $context['error'] === 'spreed broke'
				&& str_ends_with($context['file'], 'InstallTalkBot.php')
				&& is_int($context['line']))
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');
		$output->expects(self::once())->method('warning')->with('Could not register the Hermiq Talk bot: spreed broke');

		(new InstallTalkBot($installer, $logger))->run($output);
	}
}
