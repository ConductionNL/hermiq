<?php

/**
 * Initialize Actions Repair Step
 *
 * Seeds the ADR-023 action-authorization matrix on fresh install if empty.
 * Preserves any admin-customized matrix on upgrade — existing non-empty
 * matrix values are left untouched.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Kernel\Contracts\ActionMatrixInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Seed the action-authorization matrix from lib/actions.seed.json on install.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class InitializeActions implements IRepairStep {
	private const SEED_PATH = __DIR__ . '/../actions.seed.json';

	/**
	 * Constructor.
	 *
	 * @param ActionMatrixInterface $actionMatrix The stored action matrix.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ActionMatrixInterface $actionMatrix,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function getName(): string {
		return 'Initialize action-authorization matrix (ADR-023)';
	}//end getName()

	/**
	 * Seed the matrix if empty; preserve any existing admin-customized matrix.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function run(IOutput $output): void {
		$existing = $this->actionMatrix->getMatrix();
		if (count($existing) > 0) {
			$output->info($this->preservedMessage(count: count($existing)));
			return;
		}

		$actions = $this->readSeedActions(output: $output);
		if ($actions === null) {
			return;
		}

		if ($this->writeMatrix(actions: $actions, output: $output) === false) {
			return;
		}

		$output->info($this->seededMessage(count: count($actions)));
	}//end run()

	/**
	 * Write the seeded matrix, or warn and answer false when the write fails.
	 *
	 * @param array $actions The actions to seed.
	 * @param IOutput $output Repair output channel.
	 *
	 * @return bool Whether the matrix was written.
	 */
	private function writeMatrix(array $actions, IOutput $output): bool {
		try {
			$this->actionMatrix->setMatrix($actions);
		} catch (\JsonException $e) {
			$output->warning('Failed to write matrix: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end writeMatrix()

	/**
	 * The message for a matrix that already has entries and is preserved.
	 *
	 * @param int $count The number of entries.
	 *
	 * @return string
	 */
	private function preservedMessage(int $count): string {
		$plural = 'ies';
		if ($count === 1) {
			$plural = 'y';
		}

		return sprintf(
			'Action matrix already has %d entr%s — preserving.',
			$count,
			$plural
		);
	}//end preservedMessage()

	/**
	 * The message for a matrix seeded with the shipped actions.
	 *
	 * @param int $count The number of actions seeded.
	 *
	 * @return string
	 */
	private function seededMessage(int $count): string {
		$plural = 's';
		if ($count === 1) {
			$plural = '';
		}

		return sprintf(
			'Seeded action matrix with %d action%s (default: admin-only).',
			$count,
			$plural
		);
	}//end seededMessage()

	/**
	 * The `actions` object of the seed file, or null (with a warning) when the file is
	 * missing, unreadable, malformed or has no `actions` object.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return array|null
	 */
	private function readSeedActions(IOutput $output): ?array {
		$raw = $this->readSeedFile(output: $output);
		if ($raw === null) {
			return null;
		}

		return $this->actionsIn(raw: $raw, output: $output);
	}//end readSeedActions()

	/**
	 * The `actions` object of the seed file's contents, or null (with a warning) when
	 * they are not valid JSON or have no `actions` object.
	 *
	 * @param string $raw The seed file's contents.
	 * @param IOutput $output Repair output channel.
	 *
	 * @return array|null
	 */
	private function actionsIn(string $raw, IOutput $output): ?array {
		try {
			$parsed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$output->warning('actions.seed.json invalid JSON: ' . $e->getMessage());
			$this->logger->error('[hermiq] ADR-023 seed malformed: ' . $e->getMessage());
			return null;
		}

		$actions = ($parsed['actions'] ?? null);
		if (is_array($actions) === false) {
			$output->warning('actions.seed.json missing `actions` object — matrix left empty.');
			return null;
		}

		return $actions;
	}//end actionsIn()

	/**
	 * The seed file's contents, or null (with a warning) when it is missing or
	 * unreadable.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return string|null
	 */
	private function readSeedFile(IOutput $output): ?string {
		if (file_exists(self::SEED_PATH) === false) {
			$output->warning('actions.seed.json not found — matrix left empty (default-deny).');
			$this->logger->warning('[hermiq] ADR-023 seed file missing at ' . self::SEED_PATH);
			return null;
		}

		$raw = file_get_contents(self::SEED_PATH);
		if ($raw === false) {
			$output->warning('Could not read actions.seed.json — matrix left empty (default-deny).');
			return null;
		}

		return $raw;
	}//end readSeedFile()
}//end class
