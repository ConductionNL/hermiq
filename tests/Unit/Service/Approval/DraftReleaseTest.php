<?php

/**
 * Unit tests for DraftRelease (oversight-ask-and-hold): a release sends the
 * agent's text as is or with edits to the editable fields, records both
 * texts, and refuses a change to the recipients or any other fixed field.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\DraftEditRefused;
use OCA\Hermiq\Service\Approval\DraftRelease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DraftRelease::class)]
#[CoversClass(DraftEditRefused::class)]
class DraftReleaseTest extends TestCase {

	/**
	 * A held weekly report.
	 *
	 * @return array<string, mixed>
	 */
	private function report(): array {
		return ['kind' => 'delivery', 'channel' => 'email', 'output' => 'Open tickets: 41', 'conversationUuid' => 'conv-1'];
	}//end report()

	/**
	 * A held mail.
	 *
	 * @return array<string, mixed>
	 */
	private function mail(): array {
		return [
			'kind' => 'tool',
			'toolId' => 'hermiq.sendMail',
			'arguments' => ['to' => 'council@example.org', 'subject' => 'Weekly', 'body' => 'Open tickets: 41'],
			'editable' => ['subject', 'body'],
		];
	}//end mail()

	public function testAReleaseWithoutEditsSendsTheAgentsText(): void {
		$release = (new DraftRelease(draft: $this->report()))->released(edits: []);

		$this->assertSame($this->report(), $release['draft']);
		$this->assertSame('Open tickets: 41', $release['sent']);
		$this->assertFalse($release['edited']);
	}

	public function testAnEditedReportSendsTheEditAndKeepsTheOriginal(): void {
		$release = (new DraftRelease(draft: $this->report()))->released(edits: ['output' => 'Open tickets: 14']);

		$this->assertSame('Open tickets: 14', $release['draft']['output']);
		$this->assertSame('email', $release['draft']['channel']);
		$this->assertSame('Open tickets: 41', $release['original']);
		$this->assertSame('Open tickets: 14', $release['sent']);
		$this->assertTrue($release['edited']);
	}

	public function testAReportReleaseRefusesAChangedChannel(): void {
		$this->expectException(DraftEditRefused::class);
		$this->expectExceptionMessage('Only the text of this draft can be changed.');

		(new DraftRelease(draft: $this->report()))->released(edits: ['channel' => 'talk']);
	}

	public function testAnEditedMailChangesOnlyTheEditableFields(): void {
		$release = (new DraftRelease(draft: $this->mail()))->released(edits: ['body' => 'Open tickets: 14']);

		$this->assertSame(
			['to' => 'council@example.org', 'subject' => 'Weekly', 'body' => 'Open tickets: 14'],
			$release['draft']['arguments']
		);
		$this->assertSame("Subject: Weekly\n\nOpen tickets: 41", $release['original']);
		$this->assertSame("Subject: Weekly\n\nOpen tickets: 14", $release['sent']);
		$this->assertTrue($release['edited']);
	}

	public function testAMailReleaseRefusesADifferentRecipient(): void {
		$this->expectException(DraftEditRefused::class);
		$this->expectExceptionMessage('The field "to" of this draft cannot be changed.');

		(new DraftRelease(draft: $this->mail()))->released(edits: ['to' => 'someone@elsewhere.example', 'body' => 'x']);
	}

	public function testAMailReleaseAcceptsTheSameRecipientSentBack(): void {
		$release = (new DraftRelease(draft: $this->mail()))->released(edits: ['to' => 'council@example.org']);

		$this->assertSame('council@example.org', $release['draft']['arguments']['to']);
		$this->assertFalse($release['edited']);
	}

	public function testAnEditThatIsNotTextIsRefused(): void {
		$this->expectException(DraftEditRefused::class);
		$this->expectExceptionMessage('The field "body" must be text.');

		(new DraftRelease(draft: $this->mail()))->released(edits: ['body' => ['x']]);
	}

	public function testTheEditableFieldsDefaultToSubjectAndBody(): void {
		$draft = $this->mail();
		unset($draft['editable']);

		$this->assertSame(['subject', 'body'], (new DraftRelease(draft: $draft))->editable());
		$this->assertSame(['body'], (new DraftRelease(draft: ['editable' => ['body', 3, '']]))->editable());
	}

	public function testTheKindIsADeliveryUnlessItSaysTool(): void {
		$this->assertSame('tool', (new DraftRelease(draft: $this->mail()))->kind());
		$this->assertSame('delivery', (new DraftRelease(draft: []))->kind());
	}
}//end class
