<?php

/**
 * Unit tests for TraceExportQueue, TraceExportJob and TraceExportSender.
 *
 * Export never slows or changes a run: a run is only queued when export is on, the job
 * sends batches off the request path, a collector answering 503 is retried three times
 * and then dropped with one warning and an error report, the egress guard refuses an
 * unsafe endpoint, and the brokered secret only ever reaches the Authorization header.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\TraceExport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential {

	if (class_exists(CredentialBrokerService::class) === false) {
		/**
		 * Stand-in so the sender sees the broker class.
		 */
		class CredentialBrokerService {
		}
	}
}

namespace OCA\Hermiq\Tests\Unit\Service\TraceExport {

	use OCA\Hermiq\BackgroundJob\TraceExportJob;
	use OCA\Hermiq\Kernel\Contracts\GuardrailPolicyServiceInterface;
	use OCA\Hermiq\Kernel\Contracts\WebResearchEgressGuardInterface;
	use OCA\Hermiq\Service\Connection\ConnectionReporter;
	use OCA\Hermiq\Service\RedactionService;
	use OCA\Hermiq\Service\TraceExport\OtlpPayloadBuilder;
	use OCA\Hermiq\Service\TraceExport\TraceExporter;
	use OCA\Hermiq\Service\TraceExport\TraceExportQueue;
	use OCA\Hermiq\Service\TraceExport\TraceExportSender;
	use OCA\Hermiq\Service\TraceExport\TraceExportSettings;
	use OCP\AppFramework\Utility\ITimeFactory;
	use OCP\BackgroundJob\IJobList;
	use OCP\Files\AppData\IAppDataFactory;
	use OCP\Files\IAppData;
	use OCP\Files\NotFoundException;
	use OCP\Files\SimpleFS\ISimpleFile;
	use OCP\Files\SimpleFS\ISimpleFolder;
	use OCP\Http\Client\IClient;
	use OCP\Http\Client\IClientService;
	use OCP\Http\Client\IResponse;
	use OCP\IAppConfig;
	use OCP\IConfig;
	use PHPUnit\Framework\Attributes\CoversClass;
	use PHPUnit\Framework\Attributes\UsesClass;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;
	use ReflectionMethod;
	use RuntimeException;

	/**
	 * A trace leaves the server only from the queued job, and a failing collector changes no run.
	 */
	#[CoversClass(TraceExportQueue::class)]
	#[CoversClass(TraceExportJob::class)]
	#[CoversClass(TraceExportSender::class)]
	#[UsesClass(TraceExportSettings::class)]
	#[UsesClass(TraceExporter::class)]
	#[UsesClass(OtlpPayloadBuilder::class)]
	final class TraceExportDeliveryTest extends TestCase {

		/**
		 * The app data, by folder and file name.
		 *
		 * @var array<string, array<string, string>>
		 */
		private array $files = [];

		/**
		 * The jobs added, as [class, argument].
		 *
		 * @var list<array{0: string, 1: mixed}>
		 */
		private array $jobs = [];

		/**
		 * The stored app config.
		 *
		 * @var array<string, string>
		 */
		private array $config = [];

		/**
		 * The settings over an in-memory app config, saved with the given values.
		 *
		 * @param array<string, mixed> $values The settings to save.
		 *
		 * @return TraceExportSettings
		 */
		private function settings(array $values = []): TraceExportSettings {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default): string => ($this->config[$key] ?? $default));
			$appConfig->method('setValueString')->willReturnCallback(
				function (string $app, string $key, string $value): bool {
					$this->config[$key] = $value;
					return true;
				}
			);
			$settings = new TraceExportSettings(appConfig: $appConfig);
			if ($values !== []) {
				$settings->save(input: $values);
			}

			return $settings;

		}//end settings()

		/**
		 * Settings with export on.
		 *
		 * @param array<string, mixed> $extra Values beyond the defaults used here.
		 *
		 * @return TraceExportSettings
		 */
		private function enabled(array $extra = []): TraceExportSettings {
			return $this->settings(
				values: $extra + [
					'enabled' => true,
					'endpoint' => 'https://collector.example/v1/traces',
					'credentialId' => 'cred-1',
					'authScheme' => 'bearer',
				]
			);

		}//end enabled()

		/**
		 * An in-memory app data folder.
		 *
		 * @param string $name The folder name.
		 *
		 * @return ISimpleFolder
		 */
		private function folder(string $name): ISimpleFolder {
			$folder = $this->createMock(ISimpleFolder::class);
			$folder->method('newFile')->willReturnCallback(
				function (string $file, $content) use ($name): ISimpleFile {
					$this->files[$name][$file] = (string)$content;
					return $this->file(folder: $name, name: $file);
				}
			);
			$folder->method('getFile')->willReturnCallback(
				function (string $file) use ($name): ISimpleFile {
					if (isset($this->files[$name][$file]) === false) {
						throw new NotFoundException($file);
					}

					return $this->file(folder: $name, name: $file);
				}
			);
			$folder->method('getDirectoryListing')->willReturnCallback(
				fn (): array => array_map(fn (string $file): ISimpleFile => $this->file(folder: $name, name: $file), array_keys($this->files[$name] ?? []))
			);

			return $folder;

		}//end folder()

		/**
		 * An in-memory app data file.
		 *
		 * @param string $folder The folder name.
		 * @param string $name   The file name.
		 *
		 * @return ISimpleFile
		 */
		private function file(string $folder, string $name): ISimpleFile {
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$folder][$name]);
			$file->method('delete')->willReturnCallback(
				function () use ($folder, $name): void {
					unset($this->files[$folder][$name]);
				}
			);

			return $file;

		}//end file()

		/**
		 * The queue over in-memory app data and job list.
		 *
		 * @param TraceExportSettings $settings The settings.
		 *
		 * @return TraceExportQueue
		 */
		private function queue(TraceExportSettings $settings): TraceExportQueue {
			$appData = $this->createMock(IAppData::class);
			$appData->method('getFolder')->willReturnCallback(fn (string $name): ISimpleFolder => $this->folder(name: $name));
			$factory = $this->createMock(IAppDataFactory::class);
			$factory->method('get')->with('hermiq')->willReturn($appData);
			$guardrails = $this->createMock(GuardrailPolicyServiceInterface::class);
			$exporter = new TraceExporter(guardrails: $guardrails, redaction: $this->createMock(RedactionService::class), config: $this->createMock(IConfig::class));

			return new TraceExportQueue(settings: $settings, exporter: $exporter, jobList: $this->jobList(), appDataFactory: $factory, logger: $this->createMock(LoggerInterface::class));

		}//end queue()

		/**
		 * A job list that records what is added.
		 *
		 * @return IJobList
		 */
		private function jobList(): IJobList {
			$jobList = $this->createMock(IJobList::class);
			$jobList->method('add')->willReturnCallback(
				function (string $class, mixed $argument = null): void {
					$this->jobs[] = [$class, $argument];
				}
			);

			return $jobList;

		}//end jobList()

		/**
		 * A finished run's audit context.
		 *
		 * @return array<string, mixed>
		 */
		private function runContext(): array {
			return ['status' => 'success', 'agentId' => 'agent-1', 'startedAt' => '2026-11-14T02:00:00+00:00', 'endedAt' => '2026-11-14T02:00:01+00:00'];

		}//end runContext()

		/**
		 * With export off nothing is kept and no job is queued; with it on, the trace waits and one job is queued.
		 *
		 * @return void
		 */
		public function testARunIsQueuedOnlyWhenExportIsOn(): void {
			self::assertFalse($this->queue(settings: $this->settings())->enqueueRun(context: $this->runContext(), trigger: 'schedule'));
			self::assertSame([], $this->files);
			self::assertSame([], $this->jobs);

			$queue = $this->queue(settings: $this->enabled(['sources' => ['runs']]));
			self::assertTrue($queue->enqueueRun(context: $this->runContext(), trigger: 'schedule'));
			self::assertFalse($queue->enqueueChatTurn(turn: ['message' => 'hi']), 'chat is not a chosen source');

			self::assertCount(1, $this->files[TraceExportQueue::PENDING_FOLDER]);
			self::assertSame([[TraceExportJob::class, []]], $this->jobs);
			$trace = json_decode((string)current($this->files[TraceExportQueue::PENDING_FOLDER]), true);
			self::assertSame('schedule', $trace['root']['attributes']['hermiq.trigger']);

		}//end testARunIsQueuedOnlyWhenExportIsOn()

		/**
		 * A queue that cannot write swallows the failure: the run is not affected.
		 *
		 * @return void
		 */
		public function testAQueueFailureNeverReachesTheRun(): void {
			$factory = $this->createMock(IAppDataFactory::class);
			$factory->method('get')->willThrowException(new RuntimeException('disk full'));
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects(self::once())->method('warning');
			$guardrails = $this->createMock(GuardrailPolicyServiceInterface::class);
			$queue = new TraceExportQueue(
				settings: $this->enabled(),
				exporter: new TraceExporter(guardrails: $guardrails, redaction: $this->createMock(RedactionService::class)),
				jobList: $this->jobList(),
				appDataFactory: $factory,
				logger: $logger
			);

			self::assertFalse($queue->enqueueRun(context: $this->runContext(), trigger: 'flow'));

		}//end testAQueueFailureNeverReachesTheRun()

		/**
		 * A batch takes at most fifty traces, and the rest wait for the next one.
		 *
		 * @return void
		 */
		public function testABatchHoldsAtMostFiftyTraces(): void {
			$queue = $this->queue(settings: $this->enabled());
			for ($i = 0; $i < 52; $i++) {
				$queue->enqueueRun(context: $this->runContext(), trigger: 'schedule');
			}

			$batch = (string)$queue->takeBatch();

			self::assertCount(TraceExportQueue::BATCH_SIZE, (array)$queue->readBatch(name: $batch));
			self::assertTrue($queue->hasPending());
			$queue->dropBatch(name: $batch);
			self::assertNull($queue->readBatch(name: $batch));

		}//end testABatchHoldsAtMostFiftyTraces()

		/**
		 * A sender whose HTTP client answers with the given status, recording what was posted.
		 *
		 * @param int                     $status  The HTTP status.
		 * @param array<string, mixed>    $verdict The egress guard's verdict.
		 * @param string|null             $secret  What the broker resolves, null for nothing.
		 * @param array<int, mixed>       $posted  Filled with the post's arguments.
		 *
		 * @return TraceExportSender
		 */
		private function sender(int $status, array $verdict, ?string $secret, array &$posted): TraceExportSender {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$client = $this->createMock(IClient::class);
			$client->method('post')->willReturnCallback(
				function (string $url, array $options) use (&$posted, $response): IResponse {
					$posted = [$url, $options];
					return $response;
				}
			);
			$clients = $this->createMock(IClientService::class);
			$clients->method('newClient')->willReturn($client);
			$guard = $this->createMock(WebResearchEgressGuardInterface::class);
			$guard->method('assertSafe')->willReturn($verdict);
			$broker = new class ($secret) {
				/**
				 * Keep the secret.
				 *
				 * @param string|null $secret The secret.
				 */
				public function __construct(private ?string $secret) {
				}

				/**
				 * Resolve the secret.
				 *
				 * @param string      $credentialId The credential.
				 * @param string      $appId        The app.
				 * @param string|null $uid          The user.
				 * @param string|null $organisation The organisation.
				 *
				 * @return string|null
				 */
				public function resolveInjectable(string $credentialId, string $appId, ?string $uid = null, ?string $organisation = null): ?string {
					return $this->secret;
				}
			};
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($broker);

			return new TraceExportSender(clientService: $clients, egressGuard: $guard, container: $container);

		}//end sender()

		/**
		 * The secret reaches only the Authorization header, in the credential's scheme.
		 *
		 * @return void
		 */
		public function testTheBrokeredSecretGoesIntoTheAuthorizationHeader(): void {
			$posted = [];
			$sender = $this->sender(status: 200, verdict: ['allowed' => true], secret: 'sk-lf-secret', posted: $posted);

			$outcome = $sender->send(payload: ['resourceSpans' => []], settings: $this->enabled(['authScheme' => 'basic', 'username' => 'pk-lf'])->get());

			self::assertTrue($outcome['ok']);
			self::assertSame('https://collector.example/v1/traces', $posted[0]);
			self::assertSame('Basic ' . base64_encode('pk-lf:sk-lf-secret'), $posted[1]['headers']['Authorization']);
			self::assertSame(TraceExportSender::TIMEOUT_SECONDS, $posted[1]['timeout']);
			self::assertStringNotContainsString('sk-lf-secret', $posted[1]['body']);

			$sender->send(payload: [], settings: $this->enabled(['authScheme' => 'bearer'])->get());
			self::assertSame('Bearer sk-lf-secret', $posted[1]['headers']['Authorization']);
			$sender->send(payload: [], settings: $this->enabled(['authScheme' => 'apikey'])->get());
			self::assertSame('sk-lf-secret', $posted[1]['headers']['Authorization']);

		}//end testTheBrokeredSecretGoesIntoTheAuthorizationHeader()

		/**
		 * An endpoint the egress guard refuses, or a credential the broker cannot resolve, is not retried and nothing is sent.
		 *
		 * @return void
		 */
		public function testARefusedEndpointOrMissingCredentialSendsNothing(): void {
			$posted = [];
			$refused = $this->sender(status: 200, verdict: ['allowed' => false, 'code' => 'private_address', 'message' => 'A private address.'], secret: 's', posted: $posted);
			$outcome = $refused->send(payload: [], settings: $this->enabled()->get());
			self::assertFalse($outcome['ok']);
			self::assertFalse($outcome['retry']);
			self::assertStringContainsString('A private address.', $outcome['message']);

			$noSecret = $this->sender(status: 200, verdict: ['allowed' => true], secret: null, posted: $posted);
			$outcome = $noSecret->send(payload: [], settings: $this->enabled()->get());
			self::assertFalse($outcome['ok']);
			self::assertFalse($outcome['retry']);
			self::assertSame([], $posted);

		}//end testARefusedEndpointOrMissingCredentialSendsNothing()

		/**
		 * The job over a sender that answers with the given status.
		 *
		 * @param TraceExportQueue    $queue    The queue.
		 * @param TraceExportSettings $settings The settings.
		 * @param int                 $status   The collector's status.
		 * @param ConnectionReporter  $reporter The connection reporter.
		 * @param LoggerInterface     $logger   The logger.
		 * @param int                 $now      The clock.
		 *
		 * @return TraceExportJob
		 */
		private function job(TraceExportQueue $queue, TraceExportSettings $settings, int $status, ConnectionReporter $reporter, LoggerInterface $logger, int $now): TraceExportJob {
			$posted = [];
			$time = $this->createMock(ITimeFactory::class);
			$time->method('getTime')->willReturn($now);

			return new TraceExportJob(
				time: $time,
				queue: $queue,
				settings: $settings,
				builder: new OtlpPayloadBuilder(),
				sender: $this->sender(status: $status, verdict: ['allowed' => true], secret: 's', posted: $posted),
				jobList: $this->jobList(),
				reporter: $reporter,
				logger: $logger
			);

		}//end job()

		/**
		 * Run the job's protected `run()`.
		 *
		 * @param TraceExportJob $job      The job.
		 * @param mixed          $argument Its argument.
		 *
		 * @return void
		 */
		private function runJob(TraceExportJob $job, mixed $argument): void {
			(new ReflectionMethod(TraceExportJob::class, 'run'))->invoke($job, $argument);

		}//end runJob()

		/**
		 * A collector answering 503: three retries with growing backoff, then the batch is dropped,
		 * one warning is logged and the connection reports the error.
		 *
		 * @return void
		 */
		public function testAFailingCollectorIsRetriedThreeTimesThenDropped(): void {
			$settings = $this->enabled();
			$queue = $this->queue(settings: $settings);
			$queue->enqueueRun(context: $this->runContext(), trigger: 'schedule');
			$this->jobs = [];

			$reporter = $this->createMock(ConnectionReporter::class);
			$reporter->expects(self::once())->method('reportThrottled')->with('trace-export', 'error', 'The collector answered HTTP 503.');
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects(self::once())->method('warning')->with(self::stringContains('dropped after 4 tries'));

			$now = 1000;
			$argument = [];
			for ($try = 0; $try <= TraceExportJob::MAX_RETRIES; $try++) {
				$this->runJob(job: $this->job(queue: $queue, settings: $settings, status: 503, reporter: $reporter, logger: $logger, now: $now), argument: $argument);
				if ($try < TraceExportJob::MAX_RETRIES) {
					[$class, $argument] = array_pop($this->jobs);
					self::assertSame(TraceExportJob::class, $class);
					self::assertSame($try + 1, $argument['attempt']);
					self::assertSame($now + (TraceExportJob::BACKOFF_SECONDS * (2 ** $try)), $argument['notBefore']);
					$now = $argument['notBefore'];
				}
			}

			self::assertSame([], $this->jobs, 'no retry after the last try');
			self::assertSame([], $this->files[TraceExportQueue::BATCH_FOLDER]);

		}//end testAFailingCollectorIsRetriedThreeTimesThenDropped()

		/**
		 * A retry before its time is put back unchanged; a sent batch is forgotten and reported.
		 *
		 * @return void
		 */
		public function testARetryWaitsForItsTimeAndASentBatchIsReported(): void {
			$settings = $this->enabled();
			$queue = $this->queue(settings: $settings);
			$queue->enqueueRun(context: $this->runContext(), trigger: 'webhook');
			$batch = (string)$queue->takeBatch();
			$this->jobs = [];

			$reporter = $this->createMock(ConnectionReporter::class);
			$reporter->expects(self::once())->method('reportThrottled')->with('trace-export', 'configured', self::stringStartsWith('Last batch sent at'));
			$early = ['batch' => $batch, 'attempt' => 1, 'notBefore' => 2000];

			$this->runJob(job: $this->job(queue: $queue, settings: $settings, status: 200, reporter: $reporter, logger: $this->createMock(LoggerInterface::class), now: 1999), argument: $early);
			self::assertSame([[TraceExportJob::class, $early]], $this->jobs);

			$this->jobs = [];
			$this->runJob(job: $this->job(queue: $queue, settings: $settings, status: 200, reporter: $reporter, logger: $this->createMock(LoggerInterface::class), now: 2000), argument: $early);
			self::assertNull($queue->readBatch(name: $batch));
			self::assertSame([], $this->jobs);

		}//end testARetryWaitsForItsTimeAndASentBatchIsReported()

		/**
		 * Export switched off: a waiting retry batch is dropped and nothing is sent.
		 *
		 * @return void
		 */
		public function testSwitchingExportOffDropsWhatWaits(): void {
			$queue = $this->queue(settings: $this->enabled());
			$queue->enqueueRun(context: $this->runContext(), trigger: 'schedule');
			$batch = (string)$queue->takeBatch();
			$off = $this->settings(['enabled' => false]);

			$reporter = $this->createMock(ConnectionReporter::class);
			$reporter->expects(self::never())->method('reportThrottled');
			$this->runJob(job: $this->job(queue: $queue, settings: $off, status: 200, reporter: $reporter, logger: $this->createMock(LoggerInterface::class), now: 5), argument: ['batch' => $batch, 'attempt' => 1, 'notBefore' => 0]);

			self::assertNull($queue->readBatch(name: $batch));

		}//end testSwitchingExportOffDropsWhatWaits()
	}//end class
}
