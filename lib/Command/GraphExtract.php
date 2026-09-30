<?php

/**
 * Hermiq GraphExtract command.
 *
 * `occ hermiq:graph:extract <user>` is the manual enqueue point of knowledge-graph
 * extraction. It names the records to extract from (the objects of one register and
 * schema, file ids, conversation uuids), lists the objects AS the user so only what
 * that user can read is queued, and queues GraphExtractionJob batches that carry the
 * user. Running it again is safe: extraction upserts, so nothing is duplicated.
 *
 * @category Command
 * @package  OCA\Hermiq\Command
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Command;

use OCA\Hermiq\BackgroundJob\GraphExtractionJob;
use OCA\Hermiq\Service\Graph\ActingUserScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue knowledge-graph extraction for one user.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
class GraphExtract extends Command {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's object service.
	 * @param IJobList $jobs The job list.
	 * @param IUserManager $users The user manager.
	 * @param ActingUserScope $actingUser Impersonate-and-restore.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IJobList $jobs,
		private readonly IUserManager $users,
		private readonly ActingUserScope $actingUser,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, arguments and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'hermiq:graph:extract')
			->setDescription(description: 'Queue knowledge-graph extraction from records one user can read, as that user')
			->addArgument(name: 'user', mode: InputArgument::REQUIRED, description: 'The user whose records are read')
			->addOption(name: 'register', shortcut: null, mode: InputOption::VALUE_REQUIRED, description: 'Register of the objects to extract from')
			->addOption(name: 'schema', shortcut: null, mode: InputOption::VALUE_REQUIRED, description: 'Schema of the objects to extract from')
			->addOption(name: 'limit', shortcut: null, mode: InputOption::VALUE_REQUIRED, description: 'The most objects to queue', default: '200')
			->addOption(
				name: 'file',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				description: 'A file id to extract from'
			)
			->addOption(
				name: 'conversation',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				description: 'A session uuid to extract from'
			)
			->addOption(name: 'batch', shortcut: null, mode: InputOption::VALUE_REQUIRED, description: 'Records per queued job', default: '20');
	}//end configure()

	/**
	 * Queue the batches.
	 *
	 * @param InputInterface $input The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0 when something was queued.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#scenario-extraction-cannot-read-beyond-its-user
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('user');
		if ($this->users->get($uid) === null) {
			$output->writeln('<error>There is no user ' . $uid . '.</error>');

			return 1;
		}

		$sources = $this->objectSources(
			uid: $uid,
			register: (string)$input->getOption('register'),
			schema: (string)$input->getOption('schema'),
			limit: (int)$input->getOption('limit')
		);
		foreach ((array)$input->getOption('file') as $fileId) {
			$sources[] = ['sourceType' => 'file', 'sourceRef' => ['fileId' => (int)$fileId]];
		}

		foreach ((array)$input->getOption('conversation') as $uuid) {
			$sources[] = ['sourceType' => 'conversation', 'sourceRef' => ['conversationUuid' => (string)$uuid]];
		}

		if ($sources === []) {
			$output->writeln('<error>Nothing to extract: name a register and schema, a file or a conversation.</error>');

			return 1;
		}

		$batches = array_chunk($sources, max(1, (int)$input->getOption('batch')));
		foreach ($batches as $batch) {
			$this->jobs->add(GraphExtractionJob::class, ['userId' => $uid, 'sources' => $batch]);
		}

		$output->writeln('Queued ' . count($sources) . ' records in ' . count($batches) . ' extraction jobs for ' . $uid . '.');

		return 0;
	}//end execute()

	/**
	 * The objects of one register and schema the user can read, as sources.
	 *
	 * @param string $uid The user.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param int $limit The most objects.
	 *
	 * @return array<int, array<string, mixed>> The sources.
	 */
	private function objectSources(string $uid, string $register, string $schema, int $limit): array {
		if ($register === '' || $schema === '') {
			return [];
		}

		$objects = $this->actingUser->run(
			uid: $uid,
			work: fn (): array => $this->objectService->setRegister($register)->setSchema($schema)->findAll(config: ['limit' => max(1, $limit)])
		);

		$sources = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$sources[] = ['sourceType' => 'object', 'sourceRef' => ['register' => $register, 'schema' => $schema, 'uuid' => (string)$object->getUuid()]];
			}
		}

		return $sources;
	}//end objectSources()
}//end class
