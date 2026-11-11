<?php

/**
 * Golden tests for DeliveryService's notices: the approval requests (schedule,
 * flow run, webhook run, skill draft, tool invocation), the budget warning, the
 * failure and circuit-breaker alerts, and the two skill notices.
 *
 * Pins, per method and per recipient, every setter call made on the Nextcloud
 * notification in order, the Talk Note-to-self text posted (and when none is),
 * the warnings a failing recipient produces, the DeliveryResult and the
 * degraded-delivery log line, before the shared notify/collect loop is
 * extracted.
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
 * @spec openspec/changes/talk-delivery/tasks.md#4-tests
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
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

/**
 * Golden notices of DeliveryService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service's own collaborator set.
 */
class DeliveryServiceNoticeGoldenTest extends TestCase {

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
	private function service(bool $talk, array $failFor = []): DeliveryService {
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
	 * Every approval-request flavour notifies each non-empty reviewer, posts to Talk, and collects failures.
	 *
	 * @return void
	 */
	public function testApprovalRequests(): void {
		$schedule = new ObjectEntity();
		$schedule->setUuid('sched-1');
		$schedule->setObject(['name' => 'Permit drafts']);

		$cases = [
			'schedule' => [
				fn (DeliveryService $s) => $s->deliverApprovalRequest($schedule, $this->approval([]), ['bob', '', 'carol', 7]),
				'Permit drafts',
				['scheduleId' => 'sched-1'],
			],
			'flow' => [
				fn (DeliveryService $s) => $s->deliverApprovalRequestForFlowRun(
					$this->approval(['agentId' => 'agent-1', 'flowContext' => ['flowName' => 'classify', 'correlationId' => 'c-1']]),
					['bob', '', 'carol', 7]
				),
				'classify',
				['correlationId' => 'c-1'],
			],
			'flow without context' => [
				fn (DeliveryService $s) => $s->deliverApprovalRequestForFlowRun($this->approval(['agentId' => 'agent-1', 'flowContext' => 'x']), ['bob', '', 'carol', 7]),
				'agent-1',
				['correlationId' => ''],
			],
			'webhook' => [
				fn (DeliveryService $s) => $s->deliverApprovalRequestForWebhookRun(
					$this->approval(['agentId' => 'agent-2', 'webhookContext' => ['correlationId' => 'w-1']]),
					['bob', '', 'carol', 7]
				),
				'agent-2',
				['correlationId' => 'w-1'],
			],
			'skill draft' => [
				fn (DeliveryService $s) => $s->deliverApprovalRequestForSkillDraft($this->approval(['draftId' => 'd-1']), ['bob', '', 'carol', 7], 'Tender summary'),
				'Tender summary',
				['draftId' => 'd-1'],
			],
		];

		foreach ($cases as $name => [$deliver, $display, $params]) {
			foreach ([true, false] as $talk) {
				$service = $this->service(talk: $talk, failFor: ['carol']);
				$result = $deliver($service);

				$this->assertSame([true, 'notification', false, 'notify carol failed: push down for carol'], $this->outcome($result), $name);
				$this->assertSame(['bob', 'FAILED:carol', '7'], $this->notified, $name);
				$this->assertSame(
					[
						$this->calls('bob', $this->approvalTail($display, $params)),
						$this->calls('carol', $this->approvalTail($display, $params)),
						$this->calls('7', $this->approvalTail($display, $params)),
					],
					$this->notifications,
					$name
				);
				$this->assertSame(['warning: Hermiq schedule appr-1 delivery via approval degraded: notify carol failed: push down for carol'], $this->logged, $name);
				$expectedPosts = [];
				if ($talk === true) {
					$text = 'Approval needed for “' . $display . '”. Review it in Hermiq.';
					$expectedPosts = ['bob: ' . $text, 'carol: ' . $text, '7: ' . $text];
				}

				$this->assertSame($expectedPosts, $this->posted, $name);
			}
		}

		$service = $this->service(talk: false);
		$this->assertSame([false, 'notification', false, null], $this->outcome($service->deliverApprovalRequestForFlowRun($this->approval([]), ['', ''])));
		$this->assertSame([], $this->logged);
	}//end testApprovalRequests()

	/**
	 * A tool-invocation approval names the tool (or the agent), and resolves its Talk line per post only.
	 *
	 * @return void
	 */
	public function testToolInvocationApproval(): void {
		$service = $this->service(talk: true, failFor: ['bob']);
		$result = $service->deliverApprovalRequestForToolInvocation($this->approval(['toolId' => 'files.delete', 'agentId' => 'a']), ['bob', 'carol']);
		$this->assertSame([true, 'notification', false, 'notify bob failed: push down for bob'], $this->outcome($result));
		$tail = $this->approvalTail('files.delete', ['toolId' => 'files.delete']);
		$this->assertSame([$this->calls('bob', $tail), $this->calls('carol', $tail)], $this->notifications);
		$this->assertSame(
			[
				'bob: Approval needed to run “files.delete”. Line for files.delete. Review it in Hermiq.',
				'carol: Approval needed to run “files.delete”. Line for files.delete. Review it in Hermiq.',
			],
			$this->posted
		);
		$this->assertSame(2, count(array_keys($this->resolved, ApprovalPreviewBuilder::class, true)));
		$this->assertSame(['warning: Hermiq schedule appr-1 delivery via approval degraded: notify bob failed: push down for bob'], $this->logged);

		$service = $this->service(talk: false);
		$service->deliverApprovalRequestForToolInvocation($this->approval(['agentId' => 'agent-9']), ['bob']);
		$this->assertSame([$this->calls('bob', $this->approvalTail('agent-9', ['toolId' => 'agent-9']))], $this->notifications);
		$this->assertSame([], $this->resolved);
		$this->assertSame([], $this->posted);
	}//end testToolInvocationApproval()

	/**
	 * A budget warning labels an organisation budget by organisation and an agent budget by agent.
	 *
	 * @return void
	 */
	public function testBudgetWarning(): void {
		$cases = [
			'organisation' => [['scope' => 'organisation', 'agentId' => 'agent-1'], 'organisation', 'org-a'],
			'default scope' => [[], 'organisation', 'org-a'],
			'agent' => [['scope' => 'agent', 'agentId' => 'agent-1'], 'agent', 'agent-1'],
		];
		foreach ($cases as $name => [$data, $scope, $label]) {
			$budget = new ObjectEntity();
			$budget->setUuid('bud-1');
			$budget->setOrganisation('org-a');
			$budget->setObject($data);

			$service = $this->service(talk: true, failFor: ['dave']);
			$result = $service->deliverBudgetWarning($budget, ['dave', '', 'erin']);
			$this->assertSame([true, 'notification', false, 'notify dave failed: push down for dave'], $this->outcome($result), $name);
			$tail = [
				['setObject', 'budget', 'bud-1'],
				['setSubject', 'budget_soft_threshold', ['scope' => $scope, 'label' => $label]],
				['setMessage', 'budget_soft_threshold_summary', ['budgetId' => 'bud-1']],
				['setLink', 'https://nc/index.php/apps/hermiq/tenant-ops?budget=bud-1'],
			];
			$this->assertSame([$this->calls('dave', $tail), $this->calls('erin', $tail)], $this->notifications, $name);
			$text = 'Budget "' . $label . '" crossed its soft threshold. Review it in Hermiq.';
			$this->assertSame(['dave: ' . $text, 'erin: ' . $text], $this->posted, $name);
			$this->assertSame(['warning: Hermiq schedule bud-1 delivery via budget degraded: notify dave failed: push down for dave'], $this->logged, $name);
		}

		$service = $this->service(talk: false, failFor: ['dave']);
		$budget = new ObjectEntity();
		$budget->setUuid('bud-2');
		$this->assertSame(
			[false, 'notification', false, 'notify dave failed: push down for dave'],
			$this->outcome($service->deliverBudgetWarning($budget, ['dave']))
		);
	}//end testBudgetWarning()

	/**
	 * The failure and circuit-breaker alerts go to the schedule owner only, and need one.
	 *
	 * @return void
	 */
	public function testScheduleAlerts(): void {
		$schedule = new ObjectEntity();
		$schedule->setUuid('sched-1');
		$schedule->setOwner('olga');
		$schedule->setObject(['name' => 'Nightly']);
		$link = 'https://nc/index.php/apps/hermiq/runs?schedule=sched-1';

		$cases = [
			'failure' => [
				fn (DeliveryService $s, ObjectEntity $o) => $s->deliverFailureAlert($o, 'timeout'),
				[['setObject', 'schedule', 'sched-1'], ['setSubject', 'run_dead_letter', ['name' => 'Nightly']], ['setMessage', 'run_dead_letter_summary', ['reason' => 'timeout']], ['setLink', $link]],
				'olga: Schedule “Nightly” failed permanently after exhausting its retries: timeout',
				'dead_letter',
			],
			'circuit breaker' => [
				fn (DeliveryService $s, ObjectEntity $o) => $s->deliverCircuitBreakerAlert($o),
				[['setObject', 'schedule', 'sched-1'], ['setSubject', 'schedule_paused_circuit_breaker', ['name' => 'Nightly']], ['setMessage', 'schedule_paused_circuit_breaker_summary', ['scheduleId' => 'sched-1']], ['setLink', $link]],
				'olga: Schedule “Nightly” was automatically paused after repeated failures. Review it in Hermiq.',
				'circuit_breaker',
			],
		];
		foreach ($cases as $name => [$deliver, $tail, $post, $channel]) {
			$service = $this->service(talk: true);
			$this->assertSame([true, 'notification', false, null], $this->outcome($deliver($service, $schedule)), $name);
			$this->assertSame([$this->calls('olga', $tail)], $this->notifications, $name);
			$this->assertSame([$post], $this->posted, $name);
			$this->assertSame([], $this->logged, $name);

			$service = $this->service(talk: false, failFor: ['olga']);
			$this->assertSame([false, 'notification', false, 'notify olga failed: push down for olga'], $this->outcome($deliver($service, $schedule)), $name);
			$this->assertSame([], $this->posted, $name);
			$this->assertSame(['warning: Hermiq schedule sched-1 delivery via ' . $channel . ' degraded: notify olga failed: push down for olga'], $this->logged, $name);

			$ownerless = new ObjectEntity();
			$ownerless->setUuid('sched-2');
			$service = $this->service(talk: true);
			$this->assertSame([false, 'notification', false, 'Schedule has no owner to notify'], $this->outcome($deliver($service, $ownerless)), $name);
			$this->assertSame([], $this->notifications, $name);
		}
	}//end testScheduleAlerts()

	/**
	 * The two skill notices notify one recipient without Talk or logging, and need one.
	 *
	 * @return void
	 */
	public function testSkillNotices(): void {
		$cases = [
			'published behind' => [
				fn (DeliveryService $s, string $uid) => $s->deliverSkillPublishedBehind('skill-1', 'Tender', $uid),
				'skill_published_behind',
				'No publisher to notify.',
			],
			'rollback suggestion' => [
				fn (DeliveryService $s, string $uid) => $s->deliverSkillRollbackSuggestion('skill-1', 'Tender', $uid),
				'skill_rollback_suggested',
				'No reviewer to notify.',
			],
		];
		foreach ($cases as $name => [$deliver, $subject, $empty]) {
			$service = $this->service(talk: true);
			$this->assertSame([true, 'notification', false, null], $this->outcome($deliver($service, 'pia')), $name);
			$this->assertSame(
				[
					$this->calls(
						'pia',
						[
							['setObject', 'skill', 'skill-1'],
							['setSubject', $subject, ['name' => 'Tender']],
							['setMessage', $subject . '_summary', []],
							['setLink', 'https://nc/index.php/apps/hermiq/skills/skill-1'],
						]
					),
				],
				$this->notifications,
				$name
			);
			$this->assertSame([], $this->posted, $name);
			$this->assertSame([], $this->logged, $name);

			$service = $this->service(talk: true, failFor: ['pia']);
			$this->assertSame([false, 'none', false, 'notify pia failed: push down for pia'], $this->outcome($deliver($service, 'pia')), $name);
			$this->assertSame([], $this->logged, $name);

			$service = $this->service(talk: true);
			$this->assertSame([false, 'none', false, $empty], $this->outcome($deliver($service, '')), $name);
			$this->assertSame([], $this->notifications, $name);
		}
	}//end testSkillNotices()
}//end class
