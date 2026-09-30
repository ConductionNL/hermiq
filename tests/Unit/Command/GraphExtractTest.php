<?php

/**
 * The manual enqueue point of graph extraction and the job it enqueues
 * (knowledge-graph): `occ hermiq:graph:extract <user>` lists the chosen objects AS
 * that user, queues batches carrying the user, and the job hands each batch to the
 * extraction service unchanged.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Command;

use OCA\Hermiq\BackgroundJob\GraphExtractionJob;
use OCA\Hermiq\Command\GraphExtract;
use OCA\Hermiq\Service\Graph\ActingUserScope;
use OCA\Hermiq\Service\Graph\GraphExtractionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for GraphExtract and GraphExtractionJob.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
class GraphExtractTest extends TestCase {

	/**
	 * The session uid.
	 *
	 * @var string|null
	 */
	private ?string $sessionUid = null;

	/**
	 * A command over doubles; objects are listed only for alice.
	 *
	 * @param array<int, array<string, mixed>> $queued Filled with every queued job argument.
	 *
	 * @return CommandTester
	 */
	private function tester(array &$queued): CommandTester {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => ($this->sessionUid === null) ? null : $this->user($this->sessionUid));
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->sessionUid = $user?->getUID();
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'alice') ? $this->user($uid) : null);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true): array {
				$this->assertTrue($_rbac);
				if ($this->sessionUid !== 'alice') {
					return [];
				}

				$out = [];
				foreach (['c-1', 'c-2', 'c-3'] as $uuid) {
					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$out[] = $entity;
				}

				return $out;
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function (string $job, mixed $argument) use (&$queued): void {
				$this->assertSame(GraphExtractionJob::class, $job);
				$queued[] = $argument;
			}
		);

		return new CommandTester(new GraphExtract($objects, $jobs, $users, new ActingUserScope($session, $users)));

	}//end tester()

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Objects are listed as the user, and batches of sources carrying that user are queued.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-extraction-cannot-read-beyond-its-user
	 */
	public function testObjectsAreListedAsTheUserAndQueuedInBatches(): void {
		$queued = [];
		$tester = $this->tester($queued);
		$this->sessionUid = 'admin';

		$code = $tester->execute(['user' => 'alice', '--register' => 'crm', '--schema' => 'contact', '--file' => ['7'], '--conversation' => ['conv-1'], '--batch' => '2']);

		$this->assertSame(0, $code);
		$this->assertSame('admin', $this->sessionUid, 'the prior identity is restored');
		$this->assertCount(3, $queued);
		$sources = array_merge(...array_column($queued, 'sources'));
		$this->assertSame(['alice', 'alice', 'alice'], array_column($queued, 'userId'));
		$this->assertSame(['register' => 'crm', 'schema' => 'contact', 'uuid' => 'c-1'], $sources[0]['sourceRef']);
		$this->assertSame(['sourceType' => 'file', 'sourceRef' => ['fileId' => 7]], $sources[3]);
		$this->assertSame(['sourceType' => 'conversation', 'sourceRef' => ['conversationUuid' => 'conv-1']], $sources[4]);

	}//end testObjectsAreListedAsTheUserAndQueuedInBatches()

	/**
	 * An unknown user, or nothing to extract, queues nothing and fails.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function testNothingIsQueuedWithoutAUserOrSources(): void {
		$queued = [];
		$tester = $this->tester($queued);

		$this->assertSame(1, $tester->execute(['user' => 'mallory', '--file' => ['7']]));
		$this->assertSame(1, $tester->execute(['user' => 'alice']));
		$this->assertSame([], $queued);

	}//end testNothingIsQueuedWithoutAUserOrSources()

	/**
	 * The job hands its batch to the extraction service as the queued user, and ignores
	 * a malformed argument.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function testTheJobRunsTheBatchAsItsUser(): void {
		$sources = [['sourceType' => 'file', 'sourceRef' => ['fileId' => 7]]];
		$service = $this->createMock(GraphExtractionService::class);
		$service->expects($this->once())->method('extract')->with('alice', $sources)
			->willReturn(['entities' => 0, 'relations' => 0, 'skipped' => 0]);

		$job = new class($this->createMock(ITimeFactory::class), $service) extends GraphExtractionJob {
			/**
			 * Run the protected body.
			 *
			 * @param mixed $argument The argument.
			 *
			 * @return void
			 */
			public function runNow(mixed $argument): void {
				$this->run($argument);
			}
		};

		$job->runNow(['userId' => 'alice', 'sources' => $sources]);
		$job->runNow(['userId' => '', 'sources' => $sources]);
		$job->runNow('garbage');

	}//end testTheJobRunsTheBatchAsItsUser()
}//end class
