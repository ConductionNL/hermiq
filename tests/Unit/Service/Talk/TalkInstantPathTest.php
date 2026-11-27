<?php

/**
 * Unit tests for TalkInstantPath: the instant path's switch and cap.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
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

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkInstantPath;
use OCA\Hermiq\Service\Talk\TalkPostResponseRunner;
use OCA\Hermiq\Service\Talk\TalkTurnClaims;
use OCA\Hermiq\Service\Talk\TalkTurnExecutor;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see TalkInstantPath}.
 *
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */
final class TalkInstantPathTest extends TestCase {

	/**
	 * The path reads its switch (default on) and its cap (default 2) from the app config.
	 *
	 * @return void
	 */
	public function testTheSwitchAndCapComeFromTheAppConfigWithTheirDefaults(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->expects($this->once())->method('getValueBool')->with('hermiq', TalkInstantPath::ENABLED_KEY, true)->willReturn(false);
		$config->expects($this->once())->method('getValueInt')->with('hermiq', TalkInstantPath::CAP_KEY, TalkInstantPath::DEFAULT_CAP)->willReturn(4);

		$path = new TalkInstantPath(
			executor: $this->createMock(originalClassName: TalkTurnExecutor::class),
			claims: $this->createMock(originalClassName: TalkTurnClaims::class),
			postResponse: $this->createMock(originalClassName: TalkPostResponseRunner::class),
			appConfig: $config
		);

		$this->assertFalse(condition: $path->enabled());
		$this->assertSame(expected: 4, actual: $path->cap());
	}//end testTheSwitchAndCapComeFromTheAppConfigWithTheirDefaults()
}//end class
