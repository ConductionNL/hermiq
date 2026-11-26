<?php

/**
 * Tests that DeliveryService leaves an approval's reviewer notice to
 * OpenRegister when the approval's mirror task already notifies that reviewer.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\Approval\ApprovalTaskNotice;
use OCA\Hermiq\Service\ApprovalPreviewBuilder;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleWebhookSecretService;
use OCA\Hermiq\Service\Talk\TalkApprovalNotifier;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\NoteToSelfService;
use OCA\Talk\Service\ParticipantService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Talk\IBroker;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * DeliveryService leaves the assigned reviewer of a mirrored approval to OpenRegister.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service's own collaborator set.
 */
class DeliveryServiceApprovalTaskNoticeTest extends TestCase {

	/**
	 * Every notification setter call, per notification, in order.
	 *
	 * @var array<int, array<int, array<int, mixed>>>
	 */
	private array $notifications = [];

	/**
	 * Every notify() outcome: the uid it was for, or 'FAILED:<uid>'.
	 *
	 * @var array<int, string>
	 */
	private array $notified = [];

	/**
	 * Every Note-to-self post, as `<owner>: <text>`.
	 *
	 * @var array<int, string>
	 */
	private array $posted = [];

	/**
	 * Every logger call, as `<level>: <message>`.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Every container id resolved.
	 *
	 * @var array<int, string>
	 */
	private array $resolved = [];

	/**
	 * The service, with Talk up or down and notify() failing for $failFor.
	 *
	 * @param bool $talk Whether Talk is available.
	 * @param array<int, string> $failFor Users whose notification fails.
	 *
	 * @return DeliveryService
	 */
	private function service(bool $talk, array $failFor = [], ?ApprovalTaskNotice $taskNotice = null): DeliveryService {
		$this->notifications = [];
		$this->notified = [];
		$this->posted = [];
		$this->logged = [];
		$this->resolved = [];

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->recordingNotification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use ($failFor): void {
				$user = (string)$notification->getUser();
				if (in_array($user, $failFor, true) === true) {
					$this->notified[] = 'FAILED:' . $user;
					throw new RuntimeException('push down for ' . $user);
				}

				$this->notified[] = $user;
			}
		);

		$broker = $this->createMock(IBroker::class);
		$broker->method('hasBackend')->willReturn($talk);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $url): string => 'https://nc' . $url);

		$noteToSelf = $this->createMock(NoteToSelfService::class);
		$noteToSelf->method('ensureNoteToSelfExistsForUser')->willReturn($this->createMock(Room::class));
		$participants = $this->createMock(ParticipantService::class);
		$participants->method('getParticipant')->willReturn($this->createMock(Participant::class));
		$chat = $this->createMock(ChatManager::class);
		$chat->method('sendMessage')->willReturnCallback(
			function (...$args) {
				$this->posted[] = (string)$args[3] . ': ' . (string)$args[4];
				return null;
			}
		);
		$preview = $this->createMock(ApprovalPreviewBuilder::class);
		$preview->method('toolLine')->willReturnCallback(fn (string $toolId): string => 'Line for ' . $toolId . '.');
		$services = [
			NoteToSelfService::class => $noteToSelf,
			ParticipantService::class => $participants,
			ChatManager::class => $chat,
			ApprovalPreviewBuilder::class => $preview,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services): object {
				$this->resolved[] = $id;
				if (isset($services[$id]) === false) {
					throw new RuntimeException('No stub for ' . $id);
				}

				return $services[$id];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'error', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = $level . ': ' . $message;
				}
			);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		return new DeliveryService(
			notificationManager: $manager,
			talkBroker: $broker,
			urlGenerator: $urls,
			container: $container,
			config: $config,
			userManager: $this->createMock(IUserManager::class),
			mailer: $this->createMock(IMailer::class),
			clientService: $this->createMock(IClientService::class),
			redactionService: new RedactionService(config: $config),
			scheduleWebhookSecretService: $this->createMock(ScheduleWebhookSecretService::class),
			logger: $logger,
			talkRoomBinding: $this->createMock(TalkRoomBinding::class),
			talkApprovalNotifier: $this->createMock(TalkApprovalNotifier::class),
			approvalTaskNotice: $taskNotice,
		);
	}//end service()

	/**
	 * A notification that records every setter call and answers getUser().
	 *
	 * @return INotification
	 */
	private function recordingNotification(): INotification {
		$index = count($this->notifications);
		$this->notifications[$index] = [];
		$notification = $this->createMock(INotification::class);
		$user = '';
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setMessage', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (...$args) use ($setter, $index, $notification, &$user): INotification {
					if ($setter === 'setUser') {
						$user = $args[0];
					}

					if ($setter === 'setDateTime') {
						$args = [($args[0] instanceof DateTime) === true ? 'DateTime' : 'other'];
					}

					$this->notifications[$index][] = array_merge([$setter], $args);
					return $notification;
				}
			);
		}

		$notification->method('getUser')->willReturnCallback(function () use (&$user): string {
			return $user;
		});

		return $notification;
	}//end recordingNotification()

	/**
	 * The setter calls of a notification for $uid about $object.
	 *
	 * @param string $uid The recipient.
	 * @param array<int, mixed> $tail The setObject/setSubject/setMessage/setLink calls.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function calls(string $uid, array $tail): array {
		return array_merge([['setApp', 'hermiq'], ['setUser', $uid], ['setDateTime', 'DateTime']], $tail);
	}//end calls()

	/**
	 * The DeliveryResult as a comparable list.
	 *
	 * @param DeliveryResult $result The result.
	 *
	 * @return array<int, mixed>
	 */
	private function outcome(DeliveryResult $result): array {
		return [$result->isDelivered(), $result->getChannel(), $result->didFallBack(), $result->getWarning()];
	}//end outcome()

	/**
	 * An approval entity.
	 *
	 * @param array<string, mixed> $data The approval data.
	 *
	 * @return ObjectEntity
	 */
	private function approval(array $data): ObjectEntity {
		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject($data);
		return $approval;
	}//end approval()

	/**
	 * The approval tail for a display name and message params.
	 *
	 * @param string $name The display name.
	 * @param array<string, string> $params The message params.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function approvalTail(string $name, array $params): array {
		return [
			['setObject', 'approval', 'appr-1'],
			['setSubject', 'approval_requested', ['name' => $name]],
			['setMessage', 'approval_summary', $params],
			['setLink', 'https://nc/index.php/apps/hermiq/approvals/appr-1'],
		];
	}//end approvalTail()

	/**
	 * A mirrored approval routed to one user: OpenRegister's task notifies that
	 * reviewer, so hermiq raises no notification of its own, still posts the
	 * Talk line, and reports the reviewer as reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
	 */
	public function testTheMirrorTaskNotifiesTheAssignedReviewer(): void {
		$schedule = new ObjectEntity();
		$schedule->setUuid('sched-1');
		$schedule->setObject(['name' => 'Permit drafts']);
		$mirrored = ['taskUuid' => 'task-1', 'reviewer' => 'bob', 'reviewerType' => 'user', 'agentId' => 'agent-1'];

		$cases = [
			'schedule' => fn (DeliveryService $s) => $s->deliverApprovalRequest($schedule, $this->approval($mirrored), ['bob']),
			'flow' => fn (DeliveryService $s) => $s->deliverApprovalRequestForFlowRun($this->approval($mirrored), ['bob']),
			'webhook' => fn (DeliveryService $s) => $s->deliverApprovalRequestForWebhookRun($this->approval($mirrored), ['bob']),
			'skill draft' => fn (DeliveryService $s) => $s->deliverApprovalRequestForSkillDraft($this->approval($mirrored), ['bob'], 'Tender summary'),
			'tool' => fn (DeliveryService $s) => $s->deliverApprovalRequestForToolInvocation($this->approval($mirrored + ['toolId' => 'files.delete']), ['bob']),
		];

		foreach ($cases as $name => $deliver) {
			$service = $this->service(talk: true, taskNotice: new ApprovalTaskNotice(notifierClass: stdClass::class));
			$result = $deliver($service);

			$this->assertSame([true, 'notification', false, null], $this->outcome($result), $name);
			$this->assertSame([], $this->notified, $name);
			$this->assertSame([], $this->notifications, $name);
			$this->assertCount(1, $this->posted, $name);
			$this->assertStringStartsWith('bob: ', $this->posted[0], $name);
			$this->assertSame([], $this->logged, $name);
		}
	}//end testTheMirrorTaskNotifiesTheAssignedReviewer()

	/**
	 * Only the task's assignee is spared: any other recipient of the round is
	 * still notified by hermiq.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
	 */
	public function testOtherRecipientsAreStillNotified(): void {
		$service = $this->service(talk: false, taskNotice: new ApprovalTaskNotice(notifierClass: stdClass::class));
		$approval = $this->approval(['taskUuid' => 'task-1', 'reviewer' => 'bob', 'toolId' => 'files.delete']);

		$result = $service->deliverApprovalRequestForToolInvocation($approval, ['bob', 'carol']);

		$this->assertSame([true, 'notification', false, null], $this->outcome($result));
		$this->assertSame(['carol'], $this->notified);
		$this->assertSame([], $this->posted);
	}//end testOtherRecipientsAreStillNotified()

	/**
	 * Hermiq notifies every reviewer itself for a group-routed mirror, for an
	 * approval without a mirror, without OpenRegister's task notifications, and
	 * when built without the notice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
	 */
	public function testHermiqNotifiesWhenTheTaskDoesNot(): void {
		$present = new ApprovalTaskNotice(notifierClass: stdClass::class);
		$cases = [
			'group mirror' => [$present, ['taskUuid' => 'task-1', 'reviewer' => 'admins', 'reviewerType' => 'group'], ['bob', 'carol']],
			'no mirror' => [$present, ['reviewer' => 'bob'], ['bob']],
			'no task notifications' => [new ApprovalTaskNotice(notifierClass: 'OCA\\Nowhere\\Listener'), ['taskUuid' => 'task-1', 'reviewer' => 'bob'], ['bob']],
			'built without the notice' => [null, ['taskUuid' => 'task-1', 'reviewer' => 'bob'], ['bob']],
		];

		foreach ($cases as $name => [$notice, $data, $uids]) {
			$service = $this->service(talk: false, taskNotice: $notice);
			$result = $service->deliverApprovalRequestForFlowRun($this->approval($data + ['agentId' => 'agent-1']), $uids);

			$this->assertSame([true, 'notification', false, null], $this->outcome($result), $name);
			$this->assertSame($uids, $this->notified, $name);
		}
	}//end testHermiqNotifiesWhenTheTaskDoesNot()

}//end class
