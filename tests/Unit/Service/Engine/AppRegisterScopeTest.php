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
 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\AppRegisterScope;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

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

	public function testABlankAppHasNoRegistersAndReadsNothing(): void {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->expects(self::never())->method('findAll');

		self::assertSame([], (new AppRegisterScope(registerMapper: $mapper, logger: new NullLogger()))->registersFor(app: '   '));
	}//end testABlankAppHasNoRegistersAndReadsNothing()

	public function testUnreadableRegistersAreLoggedAndScopeNothing(): void {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('findAll')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('[AppRegisterScope] Registers of subsidies could not be read: db down');

		self::assertSame([], (new AppRegisterScope(registerMapper: $mapper, logger: $logger))->registersFor(app: ' subsidies '));
	}//end testUnreadableRegistersAreLoggedAndScopeNothing()

	public function testOnlySavedRegistersAreScopedByTitleElseSlug(): void {
		$titled = new Register();
		$titled->setId(4);
		$titled->setTitle('Subsidies');
		$slugOnly = new Register();
		$slugOnly->setId(7);
		$slugOnly->setSlug('subsidy-requests');
		$unsaved = new Register();
		$unsaved->setTitle('Not saved');

		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->expects(self::once())->method('findAll')->with(null, null, ['application' => 'subsidies'])
			->willReturn([$titled, 'junk', $unsaved, $slugOnly]);

		self::assertSame(
			[['id' => 4, 'name' => 'Subsidies'], ['id' => 7, 'name' => 'subsidy-requests']],
			(new AppRegisterScope(registerMapper: $mapper, logger: new NullLogger()))->registersFor(app: 'subsidies')
		);
	}//end testOnlySavedRegistersAreScopedByTitleElseSlug()
}//end class
