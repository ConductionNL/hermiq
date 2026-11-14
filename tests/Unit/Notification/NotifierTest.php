<?php

/**
 * Hermiq Notifier subject and refusal unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Notification
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

namespace OCA\Hermiq\Tests\Unit\Notification;

use OCA\Hermiq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for every notification subject's text, the icon and the refusals.
 *
 * @covers \OCA\Hermiq\Notification\Notifier
 */
class NotifierTest extends TestCase {

	/**
	 * The language the factory was asked for.
	 *
	 * @var list<array{0: string, 1: ?string}>
	 */
	private array $languages = [];

	/**
	 * The notifier over an identity translator.
	 *
	 * @return Notifier The notifier.
	 */
	private function notifier(): Notifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, ?string $lang = null) use ($l): IL10N {
				$this->languages[] = [$app, $lang];
				return $l;
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->with('hermiq', 'app-dark.svg')->willReturn('/apps/hermiq/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		return new Notifier($factory, $urls);
	}//end notifier()

	/**
	 * Prepare a notification and capture what was set on it.
	 *
	 * @param string               $app     The app.
	 * @param string               $subject The subject key.
	 * @param array<string, mixed> $params  The subject parameters.
	 *
	 * @return array{subject: ?string, message: ?string, icon: ?string} What was set.
	 */
	private function prepare(string $app, string $subject, array $params = []): array {
		$set = ['subject' => null, 'message' => null, 'icon' => null];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setIcon' => 'icon'] as $method => $key) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use (&$set, $key, $notification): INotification {
					$set[$key] = $value;
					return $notification;
				}
			);
		}

		$this->assertSame($notification, $this->notifier()->prepare($notification, 'nl'));

		return $set;
	}//end prepare()

	/**
	 * Each subject with and without a name.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: string}>
	 */
	public static function subjects(): array {
		return [
			'run complete' => ['run_complete', [], 'Scheduled agent run complete', 'Your scheduled agent run has produced output.'],
			'run complete named' => ['run_complete', ['name' => 'Rapport'], '“Rapport” finished', 'Your scheduled agent run has produced output.'],
			'approval' => ['approval_requested', [], 'Approval needed for an agent run', 'An agent run is waiting for your approval before it can execute.'],
			'approval named' => ['approval_requested', ['name' => 'Rapport'], 'Approval needed: “Rapport”', 'An agent run is waiting for your approval before it can execute.'],
			'budget' => ['budget_soft_threshold', ['name' => 'ignored'], 'Budget threshold reached', 'A budget has crossed its soft threshold for the current period.'],
			'budget labelled' => ['budget_soft_threshold', ['label' => 'Team A'], 'Budget threshold reached: “Team A”', 'A budget has crossed its soft threshold for the current period.'],
			'dead letter' => ['run_dead_letter', [], 'A scheduled run failed permanently', 'All retries have been exhausted. Review the run history and re-run manually if needed.'],
			'dead letter named' => ['run_dead_letter', ['name' => 'Rapport'], '“Rapport” failed permanently', 'All retries have been exhausted. Review the run history and re-run manually if needed.'],
			'paused' => ['schedule_paused_circuit_breaker', [], 'A schedule was paused automatically', 'It was disabled after repeated failures. Review it and re-enable when ready.'],
			'paused named' => ['schedule_paused_circuit_breaker', ['name' => 'Nacht'], '“Nacht” was paused automatically', 'It was disabled after repeated failures. Review it and re-enable when ready.'],
			'behind' => ['skill_published_behind', [], 'A published skill is behind its accepted version', 'A newer version was accepted locally. Republish it to GitHub when you are ready — nothing is pushed automatically.'],
			'behind named' => ['skill_published_behind', ['name' => 'Samenvatten'], 'Published copy of “Samenvatten” is behind', 'A newer version was accepted locally. Republish it to GitHub when you are ready — nothing is pushed automatically.'],
			'rollback' => ['skill_rollback_suggested', [], 'A skill may need a rollback', 'The first eval run after acceptance failed the regression gate. Review the versions and roll back if you agree.'],
			'rollback named' => ['skill_rollback_suggested', ['name' => 'Samenvatten'], '“Samenvatten” regressed after its last accepted version', 'The first eval run after acceptance failed the regression gate. Review the versions and roll back if you agree.'],
			'participant' => ['session_participant_added', ['name' => 'Sessie'], 'You were invited to a chat session', 'Open Chat and find it under Shared with me. You can ask the agent questions in it.'],
			'participant full' => ['session_participant_added', ['owner' => 'Alice', 'name' => 'Sessie'], 'Alice invited you to the session Sessie', 'Open Chat and find it under Shared with me. You can ask the agent questions in it.'],
			'shared' => ['session_shared', ['name' => 'Offerte'], 'A chat session was shared with you', 'You can read it in Chat under Shared with you. You cannot add to it.'],
			'shared full' => ['session_shared', ['owner' => 'Anne de Vries', 'name' => 'Offerte dakrenovatie'], "Anne de Vries shared the session 'Offerte dakrenovatie' with you", 'You can read it in Chat under Shared with you. You cannot add to it.'],
		];
	}//end subjects()

	/**
	 * Each subject renders its own subject line and message, with the app icon, in the requested language.
	 *
	 * @param string                $subjectKey The subject key.
	 * @param array<string, string> $params     The subject parameters.
	 * @param string                $subject    The expected subject line.
	 * @param string                $message    The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('subjects')]
	public function testEachSubjectRendersItsText(string $subjectKey, array $params, string $subject, string $message): void {
		$set = $this->prepare('hermiq', $subjectKey, $params);

		$this->assertSame(
			['subject' => $subject, 'message' => $message, 'icon' => 'https://cloud.example/apps/hermiq/img/app-dark.svg'],
			$set
		);
		$this->assertSame([['hermiq', 'nl']], $this->languages);
	}//end testEachSubjectRendersItsText()

	/**
	 * Another app's notification is refused before anything is translated.
	 *
	 * @return void
	 */
	public function testAnotherAppsNotificationIsRefused(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->expectExceptionMessage('Notification not handled by Hermiq');

		try {
			$this->prepare('files', 'run_complete');
		} finally {
			$this->assertSame([], $this->languages);
		}
	}//end testAnotherAppsNotificationIsRefused()

	/**
	 * An unknown subject is refused rather than rendered as a run completion.
	 *
	 * @return void
	 */
	public function testAnUnknownSubjectIsRefused(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->expectExceptionMessage('Unknown Hermiq notification subject');

		$this->prepare('hermiq', 'something_else');
	}//end testAnUnknownSubjectIsRefused()

	/**
	 * The notifier identifies itself as the hermiq app.
	 *
	 * @return void
	 */
	public function testIdAndName(): void {
		$this->assertSame('hermiq', $this->notifier()->getID());
		$this->assertSame('Hermiq', $this->notifier()->getName());
	}//end testIdAndName()

	/**
	 * A shared session's notification links to its read-only page; other subjects set no link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	public function testASharedSessionLinksToItsReadOnlyPage(): void {
		$links = [];
		foreach (['session_shared', 'session_participant_added'] as $subject) {
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
			$factory = $this->createMock(IFactory::class);
			$factory->method('get')->willReturn($l);
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('imagePath')->willReturn('/apps/hermiq/img/app-dark.svg');
			$urls->method('linkToRoute')->with('hermiq.dashboard.page')->willReturn('/index.php/apps/hermiq/');
			$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

			$notification = $this->createMock(INotification::class);
			$notification->method('getApp')->willReturn('hermiq');
			$notification->method('getSubject')->willReturn($subject);
			$notification->method('getSubjectParameters')->willReturn(['owner' => 'Anne de Vries', 'name' => 'Offerte']);
			$notification->method('getObjectId')->willReturn('sess-1');
			$notification->method('setLink')->willReturnCallback(
				function (string $link) use (&$links, $subject, $notification): INotification {
					$links[$subject] = $link;
					return $notification;
				}
			);

			(new Notifier($factory, $urls))->prepare($notification, 'nl');
		}

		$this->assertSame(['session_shared' => 'https://cloud.example/index.php/apps/hermiq/chat/shared/sess-1'], $links);
	}//end testASharedSessionLinksToItsReadOnlyPage()
}//end class
