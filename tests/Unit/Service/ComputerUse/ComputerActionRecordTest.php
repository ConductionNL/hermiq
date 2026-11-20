<?php

/**
 * A computer action is recorded with its machine, action, coordinates, redacted text
 * and approval id, and a screenshot only as its hash and size.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ComputerUse;

use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use OCA\Hermiq\Service\ComputerUse\ComputerActionRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\ComputerUse\ComputerActionRecord
 */
final class ComputerActionRecordTest extends TestCase {

	/**
	 * A redactor that marks what it was given.
	 *
	 * @return callable(string): string
	 */
	private static function redact(): callable {
		return static fn (string $text): string => 'R(' . $text . ')';
	}

	public function testAClickKeepsMachineCoordinatesApprovalAndTheScreenshotHash(): void {
		$result = ['target' => 'Werkplek Burgerzaken 03', 'image' => base64_encode('PNG-BYTES'), 'mimeType' => 'image/png'];

		$extra = ComputerActionRecord::traceExtra(
			toolId: ComputerToolDescriptors::CLICK,
			arguments: ['target' => 'Werkplek Burgerzaken 03', 'x' => 640, 'y' => 410, 'kind' => 'double_click'],
			result: $result,
			approvalId: 'appr-7',
			redact: self::redact()
		);

		self::assertSame(
			[
				'computer' => [
					'target' => 'Werkplek Burgerzaken 03',
					'action' => 'double_click',
					'x' => 640,
					'y' => 410,
					'approvalId' => 'appr-7',
					'screenshot' => ['sha256' => hash('sha256', 'PNG-BYTES'), 'bytes' => 9],
				],
			],
			$extra
		);
		self::assertStringNotContainsString(base64_encode('PNG-BYTES'), (string)json_encode($extra));
	}

	public function testTypedTextIsRedactedAndKeysAreKept(): void {
		$typed = ComputerActionRecord::traceExtra(
			toolId: ComputerToolDescriptors::TYPE,
			arguments: ['target' => 'ct-1', 'text' => 'BSN 123456782'],
			result: ['error' => ['code' => 'computer_refused']],
			approvalId: null,
			redact: self::redact()
		);
		self::assertSame(['computer' => ['target' => 'ct-1', 'action' => 'type', 'text' => 'R(BSN 123456782)']], $typed);

		$key = ComputerActionRecord::traceExtra(
			toolId: ComputerToolDescriptors::KEY,
			arguments: ['target' => 'ct-1', 'keys' => 'ctrl+s'],
			result: null,
			approvalId: '',
			redact: self::redact()
		);
		self::assertSame(['computer' => ['target' => 'ct-1', 'action' => 'key', 'keys' => 'ctrl+s']], $key);
	}

	public function testAScreenshotAndMalformedArgumentsAreRecordedSafely(): void {
		$extra = ComputerActionRecord::traceExtra(
			toolId: ComputerToolDescriptors::SCREENSHOT,
			arguments: ['target' => ['nested' => 'x']],
			result: ['image' => 'not base64 !!'],
			approvalId: null,
			redact: self::redact()
		);
		self::assertSame(['computer' => ['target' => '', 'action' => 'screenshot']], $extra);

		$click = ComputerActionRecord::traceExtra(
			toolId: ComputerToolDescriptors::CLICK,
			arguments: ['target' => 'ct-1', 'x' => '5'],
			result: null,
			approvalId: null,
			redact: self::redact()
		);
		self::assertSame(['computer' => ['target' => 'ct-1', 'action' => 'click', 'x' => null, 'y' => null]], $click);
	}

	public function testAnyOtherToolRecordsNothingHere(): void {
		self::assertSame([], ComputerActionRecord::traceExtra(toolId: 'hermiq.webFetch', arguments: ['url' => 'x'], result: null, approvalId: 'a', redact: self::redact()));
	}

	public function testTheStreamedResultCarriesTheHashInsteadOfTheImage(): void {
		$result = ['target' => 'ct-1', 'image' => base64_encode('PNG'), 'width' => 10];

		self::assertSame(
			['target' => 'ct-1', 'image' => ['sha256' => hash('sha256', 'PNG'), 'bytes' => 3], 'width' => 10],
			ComputerActionRecord::withoutImage(result: $result)
		);
		self::assertSame(['files' => []], ComputerActionRecord::withoutImage(result: ['files' => []]));
		self::assertSame('text', ComputerActionRecord::withoutImage(result: 'text'));
	}
}
