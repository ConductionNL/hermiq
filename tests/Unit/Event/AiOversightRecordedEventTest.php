<?php

/**
 * Unit tests for AiOversightRecordedEvent.
 *
 * A consumer app dispatches this event after a human decided on an AI
 * suggestion, then reads it back to learn whether hermiq recorded the
 * decision. These tests pin that contract: the record travels unchanged, an
 * undispatched or unhandled event reports nothing recorded, and only setting
 * the Approval id marks it handled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Event;

use OCA\Hermiq\Event\AiOversightRecordedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyDispatcher;

/**
 * The decision record a consumer dispatches and the handled flag it reads back.
 */
#[CoversClass(AiOversightRecordedEvent::class)]
final class AiOversightRecordedEventTest extends TestCase {

	/**
	 * A decision record with the four required keys and some optional ones.
	 *
	 * @return array<string, mixed> The record.
	 */
	private static function record(): array {
		return [
			'originApp'   => 'dossiq',
			'subjectType' => 'case',
			'subjectId'   => 'case-42',
			'humanAction' => 'overridden',
			'confidence'  => 0.82,
			'actualValue' => 'bezwaar',
			'responseTimeMs' => 314,
		];
	}//end record()

	/**
	 * The record comes back exactly as the consumer supplied it, key order and types included.
	 *
	 * @return void
	 */
	public function testTheRecordTravelsUnchanged(): void {
		$event = new AiOversightRecordedEvent(record: self::record());

		$this->assertSame(expected: self::record(), actual: $event->getRecord());
	}//end testTheRecordTravelsUnchanged()

	/**
	 * A fresh event has recorded nothing: no Approval id and not handled.
	 *
	 * @return void
	 */
	public function testAFreshEventIsNotHandled(): void {
		$event = new AiOversightRecordedEvent(record: self::record());

		$this->assertFalse(condition: $event->isHandled());
		$this->assertNull(actual: $event->getApprovalId());
	}//end testAFreshEventIsNotHandled()

	/**
	 * Setting the Approval id both stores it and marks the event handled.
	 *
	 * @return void
	 */
	public function testSettingTheApprovalIdMarksTheEventHandled(): void {
		$event = new AiOversightRecordedEvent(record: self::record());

		$event->setApprovalId(approvalId: 'approval-7');

		$this->assertTrue(condition: $event->isHandled());
		$this->assertSame(expected: 'approval-7', actual: $event->getApprovalId());
		$this->assertSame(expected: self::record(), actual: $event->getRecord());
	}//end testSettingTheApprovalIdMarksTheEventHandled()

	/**
	 * Dispatched with no listener (hermiq absent), the consumer reads back "not recorded".
	 *
	 * @return void
	 */
	public function testADispatchNobodyHandlesStaysUnhandled(): void {
		$dispatcher = new SymfonyDispatcher();
		$event      = new AiOversightRecordedEvent(record: self::record());

		$dispatcher->dispatch($event, AiOversightRecordedEvent::class);

		$this->assertFalse(condition: $event->isHandled());
		$this->assertNull(actual: $event->getApprovalId());
	}//end testADispatchNobodyHandlesStaysUnhandled()

	/**
	 * A listener that records the decision is visible to the consumer after dispatch.
	 *
	 * @return void
	 */
	public function testAListenerThatRecordsIsVisibleAfterDispatch(): void {
		$dispatcher = new SymfonyDispatcher();
		$seen       = [];
		$dispatcher->addListener(
			AiOversightRecordedEvent::class,
			static function (AiOversightRecordedEvent $event) use (&$seen): void {
				$seen[] = $event->getRecord()['subjectId'];
				$event->setApprovalId(approvalId: 'approval-for-' . $event->getRecord()['subjectId']);
			}
		);
		$event = new AiOversightRecordedEvent(record: self::record());

		$dispatcher->dispatch($event, AiOversightRecordedEvent::class);

		$this->assertSame(expected: ['case-42'], actual: $seen);
		$this->assertTrue(condition: $event->isHandled());
		$this->assertSame(expected: 'approval-for-case-42', actual: $event->getApprovalId());
	}//end testAListenerThatRecordsIsVisibleAfterDispatch()
}//end class
