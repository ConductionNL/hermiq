<?php

/**
 * Unit tests for AppRegisterScope::appOf() (agents-bound-to-their-app, task 5).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\AppRegisterScope;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The record's app is the app that imported its register.
 */
final class AppRegisterScopeTest extends TestCase {

	public function testARegisterNamesTheAppThatImportedIt(): void {
		$register = new Register();
		$register->setApplication('Subsidies');
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->expects(self::once())->method('find')->with('12')->willReturn($register);

		self::assertSame('subsidies', (new AppRegisterScope(registerMapper: $mapper, logger: new NullLogger()))->appOf(register: '12'));
	}//end testARegisterNamesTheAppThatImportedIt()

	public function testAnUnknownOrUnreadableRegisterNamesNoApp(): void {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$scope = new AppRegisterScope(registerMapper: $mapper, logger: new NullLogger());

		self::assertSame('', $scope->appOf(register: '99'));
		self::assertSame('', $scope->appOf(register: ''));
	}//end testAnUnknownOrUnreadableRegisterNamesNoApp()

	public function testARegisterWithoutAnAppNamesNoApp(): void {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturn(new Register());

		self::assertSame('', (new AppRegisterScope(registerMapper: $mapper, logger: new NullLogger()))->appOf(register: '3'));
	}//end testARegisterWithoutAnAppNamesNoApp()
}//end class
