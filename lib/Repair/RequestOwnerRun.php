<?php

/**
 * Hermiq RequestOwnerRun
 *
 * One pass of handing every tool-access request to the system owner.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;
use Throwable;

/**
 * The requests read, the system owner, and the mapper that stores a hand-over.
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */
final class RequestOwnerRun {

	/**
	 * Start a run.
	 *
	 * @param iterable<mixed> $requests Every tool-access request read.
	 * @param string          $system   The system user id.
	 * @param object          $mapper   OpenRegister's object mapper (stores an entity).
	 * @param IOutput         $output   The repair output.
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	public function __construct(
		private readonly iterable $requests,
		private readonly string $system,
		private readonly object $mapper,
		private readonly IOutput $output,
	) {
	}//end __construct()

	/**
	 * Hand every request not owned by the system to it.
	 *
	 * @return int How many requests were handed over.
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	public function handOverAll(): int {
		$moved = 0;
		foreach ($this->requests as $request) {
			if (($request instanceof ObjectEntity) === false || $request->getOwner() === $this->system) {
				continue;
			}

			if ($this->handOver(request: $request) === true) {
				$moved++;
			}
		}

		return $moved;
	}//end handOverAll()

	/**
	 * Store one request with the system owner, or report why it could not be.
	 *
	 * @param ObjectEntity $request The request.
	 *
	 * @return bool True when it was stored.
	 */
	private function handOver(ObjectEntity $request): bool {
		try {
			$request->setOwner($this->system);
			$this->mapper->update($request);
			return true;
		} catch (Throwable $e) {
			$this->output->warning('Could not hand tool-access request ' . $request->getUuid() . ' to the system: ' . $e->getMessage());
			return false;
		}
	}//end handOver()
}//end class
