<?php

/**
 * Hermiq BackupWriter.
 *
 * Writes one backup file per organisation into the administrator's Files
 * (operations-backup-and-restore, design D2): a manifest (time, hermiq
 * version, register version, organisation, schemas, count per schema) and
 * every object of that organisation per schema slug, gzipped JSON named
 * `hermiq-backup-<organisation>-<yyyy-mm-dd-hhmm>.json.gz`. Then deletes the
 * organisation's backups beyond the number to keep, oldest first.
 *
 * The schema set is `components.schemas` of the shipped register, not the
 * register's `schemas` list, which leaves some declared schemas out. Sessions
 * and turns (and the retired conversations and messages) are only included
 * when the administrator chose so.
 *
 * THE ORGANISATION BOUNDARY: objects are read system-wide, as hermiq's other
 * background work does (there is no session), and every object is put in the
 * file of the organisation it carries, and only there. An object with no
 * organisation belongs to none and is in no file. No hermiq object holds a
 * secret (hydra ADR-064), so nothing is filtered out of an object.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Backup
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Backup;

use DateTimeInterface;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Writes and prunes the per-organisation backup files.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */
class BackupWriter {

	public const REGISTER_SLUG = 'hermiq';

	/**
	 * Chats: in a backup only when the administrator included sessions.
	 *
	 * @var list<string>
	 */
	public const SESSION_SLUGS = ['agentsession', 'agentsessionturn', 'conversation', 'message'];

	public const FILE_PREFIX = 'hermiq-backup-';

	public const FILE_SUFFIX = '.json.gz';

	private const PAGE_SIZE = 500;

	private const MAX_PAGES = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister's object service.
	 * @param IRootFolder     $rootFolder    Nextcloud Files.
	 * @param IAppManager     $appManager    Tells hermiq's installed version.
	 * @param ITimeFactory    $time          The clock.
	 * @param LoggerInterface $logger        The logger.
	 * @param string          $registerFile  The shipped register descriptor.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IRootFolder $rootFolder,
		private readonly IAppManager $appManager,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly string $registerFile=__DIR__ . '/../../Settings/hermiq_register.json',
	) {
	}//end __construct()

	/**
	 * Back up every organisation with hermiq objects, then prune each.
	 *
	 * @param BackupSettings $settings The settings.
	 *
	 * @return array<string, array{file: string, counts: array<string, int>, deleted: list<string>}> Per organisation.
	 *
	 * @throws RuntimeException When no administrator owns the backup folder.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function backUp(BackupSettings $settings): array {
		if ($settings->ownerUid === '') {
			throw new RuntimeException('No administrator owns the backup folder; save the backup settings first.');
		}

		$descriptor = $this->descriptor();
		$slugs = $this->schemaSlugs(descriptor: $descriptor, includeSessions: $settings->includeSessions);
		$byOrganisation = $this->collect(slugs: $slugs);
		$folder = $this->folder(settings: $settings);
		$stamp = $this->time->getDateTime('now');

		$report = [];
		foreach ($byOrganisation as $organisation => $objects) {
			$organisation = (string)$organisation;
			$name = self::fileName(organisation: $organisation, stamp: $stamp);
			$document = [
				'manifest' => $this->manifest(organisation: $organisation, objects: $objects, descriptor: $descriptor, stamp: $stamp),
				'objects'  => $objects,
			];
			$json = (string)json_encode($document, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			$this->writeFile(folder: $folder, name: $name, content: (string)gzencode($json));

			$report[$organisation] = [
				'file'    => $name,
				'counts'  => $document['manifest']['counts'],
				'deleted' => $this->prune(folder: $folder, organisation: $organisation, keep: $settings->keep),
			];
		}

		return $report;
	}//end backUp()

	/**
	 * Every declared schema slug, without the chats unless they are included.
	 *
	 * @param array<string, mixed> $descriptor      The register descriptor.
	 * @param bool                 $includeSessions Whether chats are included.
	 *
	 * @return list<string> The slugs.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function schemaSlugs(array $descriptor, bool $includeSessions): array {
		$slugs = [];
		foreach ((array)($descriptor['components']['schemas'] ?? []) as $key => $schema) {
			$slug = (string)($schema['slug'] ?? strtolower((string)$key));
			if ($slug === '' || ($includeSessions === false && in_array($slug, self::SESSION_SLUGS, true) === true)) {
				continue;
			}

			$slugs[] = $slug;
		}

		return array_values(array_unique($slugs));
	}//end schemaSlugs()

	/**
	 * The shipped register descriptor.
	 *
	 * @return array<string, mixed> The descriptor.
	 *
	 * @throws RuntimeException When it cannot be read.
	 *
	* @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function descriptor(): array {
		$raw = @file_get_contents($this->registerFile);
		$descriptor = json_decode((string)$raw, true);
		if (is_array($descriptor) === false) {
			throw new RuntimeException('The hermiq register descriptor cannot be read.');
		}

		return $descriptor;
	}//end descriptor()

	/**
	 * The file name of one organisation's backup.
	 *
	 * @param string            $organisation The organisation.
	 * @param DateTimeInterface $stamp        When it is written.
	 *
	 * @return string The name.
	 *
	* @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public static function fileName(string $organisation, DateTimeInterface $stamp): string {
		return self::FILE_PREFIX . self::token(organisation: $organisation) . '-' . $stamp->format('Y-m-d-Hi') . self::FILE_SUFFIX;
	}//end fileName()

	/**
	 * The organisation as it appears in a file name: lower case letters, digits and dashes.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return string The token.
	 *
	* @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public static function token(string $organisation): string {
		$token = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($organisation)), '-');
		if ($token === '') {
			return 'organisation';
		}

		return $token;
	}//end token()

	/**
	 * Read every object of the given schemas and group it by its organisation.
	 *
	 * @param list<string> $slugs The schema slugs.
	 *
	 * @return array<string, array<string, list<array<string, mixed>>>> Organisation => slug => objects.
	 */
	private function collect(array $slugs): array {
		$byOrganisation = [];
		foreach ($slugs as $slug) {
			foreach ($this->readSchema(slug: $slug) as $object) {
				if (($object instanceof ObjectEntity) === false) {
					continue;
				}

				$organisation = (string)($object->getOrganisation() ?? '');
				if ($organisation === '') {
					continue;
				}

				$byOrganisation[$organisation][$slug][] = $object->jsonSerialize();
			}
		}

		return $byOrganisation;
	}//end collect()

	/**
	 * Every object of one schema, system-wide, page by page. A schema that
	 * cannot be read is logged and contributes nothing.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return list<mixed> The objects.
	 */
	private function readSchema(string $slug): array {
		$objects = [];
		try {
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$rows = $this->objectService
					->setRegister(self::REGISTER_SLUG)
					->setSchema($slug)
					->findAll(config: ['limit' => self::PAGE_SIZE, 'offset' => ($page * self::PAGE_SIZE)], _rbac: false, _multitenancy: false);
				array_push($objects, ...array_values($rows));
				if (count($rows) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[BackupWriter] A schema could not be read for the backup',
				['schema' => $slug, 'exception' => $e->getMessage(), 'app' => AppIdentity::APP_ID]
			);
		}

		return $objects;
	}//end readSchema()

	/**
	 * The manifest of one organisation's backup.
	 *
	 * @param string                                     $organisation The organisation.
	 * @param array<string, list<array<string, mixed>>> $objects      Slug => objects.
	 * @param array<string, mixed>                       $descriptor   The register descriptor.
	 * @param DateTimeInterface                          $stamp        When it is written.
	 *
	 * @return array<string, mixed> The manifest.
	 */
	private function manifest(string $organisation, array $objects, array $descriptor, DateTimeInterface $stamp): array {
		$counts = [];
		foreach ($objects as $slug => $rows) {
			$counts[(string)$slug] = count($rows);
		}

		return [
			'createdAt'       => $stamp->format(DateTimeInterface::ATOM),
			'appVersion'      => $this->appManager->getAppVersion(AppIdentity::APP_ID),
			'registerVersion' => (string)($descriptor['info']['version'] ?? ''),
			'organisation'    => $organisation,
			'schemas'         => array_keys($counts),
			'counts'          => $counts,
		];
	}//end manifest()

	/**
	 * The backup folder in the owner's Files, created when it is not there.
	 *
	 * @param BackupSettings $settings The settings.
	 *
	 * @return Folder The folder.
	 *
	 * @throws RuntimeException When the path is not a folder.
	 */
	private function folder(BackupSettings $settings): Folder {
		$home = $this->rootFolder->getUserFolder($settings->ownerUid);
		if ($home->nodeExists($settings->folder) === false) {
			return $home->newFolder($settings->folder);
		}

		$node = $home->get($settings->folder);
		if (($node instanceof Folder) === false) {
			throw new RuntimeException('The backup folder ' . $settings->folder . ' is a file, not a folder.');
		}

		return $node;
	}//end folder()

	/**
	 * Write one file, replacing one of the same name (the same organisation in the same minute).
	 *
	 * @param Folder $folder  The folder.
	 * @param string $name    The file name.
	 * @param string $content The gzipped content.
	 *
	 * @return void
	 */
	private function writeFile(Folder $folder, string $name, string $content): void {
		if ($folder->nodeExists($name) === true) {
			$existing = $folder->get($name);
			if ($existing instanceof \OCP\Files\File) {
				$existing->putContent($content);
				return;
			}
		}

		$folder->newFile($name, $content);
	}//end writeFile()

	/**
	 * Delete this organisation's backups beyond the number to keep, oldest first.
	 * The time in the name sorts in creation order; other files are never touched.
	 *
	 * @param Folder $folder       The folder.
	 * @param string $organisation The organisation.
	 * @param int    $keep         How many to keep.
	 *
	 * @return list<string> The names deleted.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function prune(Folder $folder, string $organisation, int $keep): array {
		$prefix = self::FILE_PREFIX . self::token(organisation: $organisation) . '-';
		$pattern = '/^' . preg_quote($prefix, '/') . '\d{4}-\d{2}-\d{2}-\d{4}' . preg_quote(self::FILE_SUFFIX, '/') . '$/';
		$backups = array_values(
			array_filter(
				$folder->getDirectoryListing(),
				static fn (Node $node): bool => preg_match($pattern, $node->getName()) === 1
			)
		);
		usort($backups, static fn (Node $a, Node $b): int => strcmp($b->getName(), $a->getName()));

		$deleted = [];
		foreach (array_slice($backups, max(1, $keep)) as $old) {
			$deleted[] = $old->getName();
			$old->delete();
		}

		return $deleted;
	}//end prune()
}//end class
