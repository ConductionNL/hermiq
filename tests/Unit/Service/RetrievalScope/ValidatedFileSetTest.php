<?php

/**
 * The files carrying the validated tag.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\RetrievalScope;

use OCA\Hermiq\Kernel\Contracts\ValidatedTagSettingsInterface;
use OCA\Hermiq\Service\RetrievalScope\ValidatedFileSet;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ValidatedFileSet::class)]
class ValidatedFileSetTest extends TestCase {

	private function set(?string $tagId, ?ISystemTagObjectMapper $mapper = null): ValidatedFileSet {
		$settings = $this->createMock(ValidatedTagSettingsInterface::class);
		$settings->method('tagId')->willReturn($tagId);

		return new ValidatedFileSet($settings, ($mapper ?? $this->createMock(ISystemTagObjectMapper::class)), new NullLogger());
	}//end set()

	public function testTheTaggedFilesAreListedAsIds(): void {
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects($this->once())->method('getObjectIdsForTags')->with('17', 'files')->willReturn(['12', '40', '12']);

		$this->assertSame([12, 40], $this->set('17', $mapper)->fileIds());
	}//end testTheTaggedFilesAreListedAsIds()

	public function testNoTagIsNoFile(): void {
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects($this->never())->method('getObjectIdsForTags');

		$this->assertSame([], $this->set(null, $mapper)->fileIds());
		$this->assertSame([], $this->set('', $mapper)->fileIds());
	}//end testNoTagIsNoFile()

	public function testAFailingLookupIsNoFile(): void {
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getObjectIdsForTags')->willThrowException(new RuntimeException('tag gone'));

		$this->assertSame([], $this->set('17', $mapper)->fileIds());
	}//end testAFailingLookupIsNoFile()
}//end class
