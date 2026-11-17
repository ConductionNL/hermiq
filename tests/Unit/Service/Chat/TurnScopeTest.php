<?php

/**
 * Hermiq TurnScope: the one accepted shape of a turn's scope.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
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
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/tasks.md#task-1-a-turn-carries-a-scope
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\Chat\TurnScope;
use OCA\Hermiq\Service\Chat\TurnScopeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the scope parser.
 */
class TurnScopeTest extends TestCase {

	/**
	 * No scope leaves the turn unscoped.
	 *
	 * @return void
	 */
	public function testNoScopeIsNull(): void {
		$parser = new TurnScope();

		$this->assertNull($parser->parse(value: null));
		$this->assertNull($parser->parse(value: ''));
		$this->assertNull($parser->parse(value: []));
	}

	/**
	 * A file scope and a folder scope pass unchanged, a digit string read as an id.
	 *
	 * @return void
	 */
	public function testFileAndFolderScopesAreKept(): void {
		$parser = new TurnScope();

		$this->assertSame(['type' => 'file', 'fileId' => 42], $parser->parse(value: ['type' => 'file', 'fileId' => 42]));
		$this->assertSame(['type' => 'folder', 'fileId' => 7], $parser->parse(value: ['type' => 'folder', 'fileId' => '7', 'extra' => 'x']));
	}

	/**
	 * The malformed scopes.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformed(): array {
		return [
			'a string' => ['file:42'],
			'an unknown type' => [['type' => 'register', 'fileId' => 42]],
			'no type' => [['fileId' => 42]],
			'no file id' => [['type' => 'file']],
			'a zero id' => [['type' => 'file', 'fileId' => 0]],
			'a negative id' => [['type' => 'file', 'fileId' => -3]],
			'a float id' => [['type' => 'file', 'fileId' => 4.2]],
			'a word id' => [['type' => 'folder', 'fileId' => 'abc']],
		];
	}

	/**
	 * Any other shape refuses the turn with a 400.
	 *
	 * @param mixed $value The scope.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testAnyOtherShapeIsA400(mixed $value): void {
		try {
			(new TurnScope())->parse(value: $value);
			$this->fail('A malformed scope was accepted');
		} catch (TurnScopeException $e) {
			$this->assertSame(400, $e->getCode());
			$this->assertSame(TurnScopeException::INVALID, $e->errorCode());
		}
	}

	/**
	 * The scope in a turn's RAG settings is read the same way; none there is null.
	 *
	 * @return void
	 */
	public function testTheScopeIsReadFromRagSettings(): void {
		$parser = new TurnScope();

		$this->assertNull($parser->fromRagSettings(ragSettings: ['includeFiles' => true]));
		$this->assertSame(['type' => 'file', 'fileId' => 5], $parser->fromRagSettings(ragSettings: ['scope' => ['type' => 'file', 'fileId' => 5]]));
		$this->assertSame(TurnScopeException::NOT_FOUND, (new TurnScopeException(status: 404))->errorCode());
		$this->assertSame(404, (new TurnScopeException(status: 404))->getCode());
	}
}
