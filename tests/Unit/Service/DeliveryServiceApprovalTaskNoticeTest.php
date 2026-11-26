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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

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
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
 */
class DeliveryServiceApprovalTaskNoticeTest extends TestCase {

	/**
	 * The users hermiq raised a notification for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $notified = [];

	/**
	 * The Talk Note-to-self posts, as "uid: text".
	 *
	 * @var array<int, string>
	 */
	private array $posted = [];

	/**
	 * The container ids resolved.
	 *
	 * @var array<int, string>
	 */
	private array $resolved = [];

	/**
	 * A DeliveryService whose container hands out $notice, or nothing for null.
	 *
	 * @param bool                    $talk   Whether Talk is available.
	 * @param ApprovalTaskNotice|null $notice The notice the container resolves, or null for none.
	 *
	 * @return DeliveryService
	 */
	private function service(bool $talk, ?ApprovalTaskNotice $notice): DeliveryService {
		$this->notified = [];
		$this->posted = [];
		$this->resolved = [];

		$manager = $this->createMock(originalClassName: INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->recordingNotification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->notified[] = (string)$notification->getUser();
			}
		);

		$broker = $this->createMock(originalClassName: IBroker::class);
		$broker->method('hasBackend')->willReturn($talk);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $url): string => 'https://nc' . $url);

		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		return new DeliveryService(
			notificationManager: $manager,
			talkBroker: $broker,
			urlGenerator: $urls,
			container: $this->container(services: $this->talkServices(), notice: $notice),
			config: $config,
			userManager: $this->createMock(originalClassName: IUserManager::class),
			mailer: $this->createMock(originalClassName: IMailer::class),
			clientService: $this->createMock(originalClassName: IClientService::class),
			redactionService: new RedactionService(config: $config),
			scheduleWebhookSecretService: $this->createMock(originalClassName: ScheduleWebhookSecretService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			talkRoomBinding: $this->createMock(originalClassName: TalkRoomBinding::class),
			talkApprovalNotifier: $this->createMock(originalClassName: TalkApprovalNotifier::class),
		);
	}//end service()

	/**
	 * The Talk services a Note-to-self post resolves, recording each post.
	 *
	 * @return array<string, object>
	 */
	private function talkServices(): array {
		$noteToSelf = $this->createMock(originalClassName: NoteToSelfService::class);
		$noteToSelf->method('ensureNoteToSelfExistsForUser')->willReturn($this->createMock(originalClassName: Room::class));
		$participants = $this->createMock(originalClassName: ParticipantService::class);
		$participants->method('getParticipant')->willReturn($this->createMock(originalClassName: Participant::class));
		$chat = $this->createMock(originalClassName: ChatManager::class);
		$chat->method('sendMessage')->willReturnCallback(
			function (...$args) {
				$this->posted[] = (string)$args[3] . ': ' . (string)$args[4];
				return null;
			}
		);
		$preview = $this->createMock(originalClassName: ApprovalPreviewBuilder::class);
		$preview->method('toolLine')->willReturn('A line.');

		return [
			NoteToSelfService::class => $noteToSelf,
			ParticipantService::class => $participants,
			ChatManager::class => $chat,
			ApprovalPreviewBuilder::class => $preview,
		];
	}//end talkServices()

	/**
	 * A container over $services, plus the notice when one is given.
	 *
	 * @param array<string, object>   $services The services by id.
	 * @param ApprovalTaskNotice|null $notice   The notice, or null for none.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services, ?ApprovalTaskNotice $notice): ContainerInterface {
		if ($notice !== null) {
			$services[ApprovalTaskNotice::class] = $notice;
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services): object {
				$this->resolved[] = $id;
				if (isset($services[$id]) === false) {
					throw new RuntimeException('No stub for ' . $id);
				}

				return $services[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * A notification that answers getUser() with the user it was set up for.
	 *
	 * @return INotification
	 */
	private function recordingNotification(): INotification {
		$notification = $this->createMock(originalClassName: INotification::class);
		$user = '';
		foreach (['setApp', 'setDateTime', 'setObject', 'setSubject', 'setMessage', 'setLink'] as $setter) {
			$notification->method($setter)->willReturn($notification);
		}

		$notification->method('setUser')->willReturnCallback(
			function (string $uid) use ($notification, &$user): INotification {
				$user = $uid;
				return $notification;
			}
		);
		$notification->method('getUser')->willReturnCallback(
			function () use (&$user): string {
				return $user;
			}
		);

		return $notification;
	}//end recordingNotification()

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
	 * A notice that sees OpenRegister's task notifications as installed.
	 *
	 * @return ApprovalTaskNotice
	 */
	private function presentNotice(): ApprovalTaskNotice {
		return new ApprovalTaskNotice(notifierClass: stdClass::class);
	}//end presentNotice()

	/**
	 * A mirrored approval routed to one user: OpenRegister's task notifies that
	 * reviewer, so hermiq raises no notification of its own, still posts the
	 * Talk line, and reports the reviewer as reached. Every approval flavour.
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
			'schedule' => fn (DeliveryService $s) => $s->deliverApprovalRequest(
				schedule: $schedule,
				approval: $this->approval(data: $mirrored),
				reviewerUids: ['bob']
			),
			'flow' => fn (DeliveryService $s) => $s->deliverApprovalRequestForFlowRun(approval: $this->approval(data: $mirrored), reviewerUids: ['bob']),
			'webhook' => fn (DeliveryService $s) => $s->deliverApprovalRequestForWebhookRun(approval: $this->approval(data: $mirrored), reviewerUids: ['bob']),
			'skill draft' => fn (DeliveryService $s) => $s->deliverApprovalRequestForSkillDraft(
				approval: $this->approval(data: $mirrored),
				reviewerUids: ['bob'],
				skillName: 'Tender summary'
			),
			'tool' => fn (DeliveryService $s) => $s->deliverApprovalRequestForToolInvocation(
				approval: $this->approval(data: $mirrored + ['toolId' => 'files.delete']),
				reviewerUids: ['bob']
			),
		];

		foreach ($cases as $name => $deliver) {
			$result = $deliver($this->service(talk: true, notice: $this->presentNotice()));

			$this->assertSame(expected: [true, 'notification', false, null], actual: $this->outcome(result: $result), message: $name);
			$this->assertSame(expected: [], actual: $this->notified, message: $name);
			$this->assertCount(expectedCount: 1, haystack: $this->posted, message: $name);
			$this->assertStringStartsWith(prefix: 'bob: ', string: $this->posted[0], message: $name);
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
		$service = $this->service(talk: false, notice: $this->presentNotice());
		$approval = $this->approval(data: ['taskUuid' => 'task-1', 'reviewer' => 'bob', 'toolId' => 'files.delete']);

		$result = $service->deliverApprovalRequestForToolInvocation(approval: $approval, reviewerUids: ['bob', 'carol']);

		$this->assertSame(expected: [true, 'notification', false, null], actual: $this->outcome(result: $result));
		$this->assertSame(expected: ['carol'], actual: $this->notified);
		$this->assertSame(expected: [], actual: $this->posted);
	}//end testOtherRecipientsAreStillNotified()

	/**
	 * Hermiq notifies every reviewer itself for a group-routed mirror, without
	 * OpenRegister's task notifications, and when the container cannot build the
	 * notice; an approval without a mirror never asks the container at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-the-reviewer-a-mirror-task-is-assigned-to-is-notified-once-by-openregister
	 */
	public function testHermiqNotifiesWhenTheTaskDoesNot(): void {
		$cases = [
			'group mirror' => [$this->presentNotice(), ['taskUuid' => 'task-1', 'reviewer' => 'admins', 'reviewerType' => 'group'], ['bob', 'carol']],
			'no task notifications' => [
				new ApprovalTaskNotice(notifierClass: 'OCA\\Nowhere\\Listener'),
				['taskUuid' => 'task-1', 'reviewer' => 'bob'],
				['bob'],
			],
			'no notice in the container' => [null, ['taskUuid' => 'task-1', 'reviewer' => 'bob'], ['bob']],
		];

		foreach ($cases as $name => [$notice, $data, $uids]) {
			$service = $this->service(talk: false, notice: $notice);
			$result = $service->deliverApprovalRequestForFlowRun(approval: $this->approval(data: $data + ['agentId' => 'agent-1']), reviewerUids: $uids);

			$this->assertSame(expected: [true, 'notification', false, null], actual: $this->outcome(result: $result), message: $name);
			$this->assertSame(expected: $uids, actual: $this->notified, message: $name);
		}

		$service = $this->service(talk: false, notice: $this->presentNotice());
		$service->deliverApprovalRequestForFlowRun(approval: $this->approval(data: ['reviewer' => 'bob']), reviewerUids: ['bob']);
		$this->assertSame(expected: ['bob'], actual: $this->notified);
		$this->assertSame(expected: [], actual: $this->resolved);
	}//end testHermiqNotifiesWhenTheTaskDoesNot()
}//end class
