<?php

/**
 * Hermiq AiLabelStatsService.
 *
 * The numbers behind the "AI label" section of the AI oversight page: how many
 * agent-authored files (notes included, a note is a file) the viewer can read,
 * and the share of those a person has marked as reviewed. Both come from the
 * system tags themselves (design D2, D3), limited to what the viewer can open.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * Counts the agent-authored files a viewer can read, and how many are reviewed.
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */
class AiLabelStatsService {

	/**
	 * The most tagged file ids checked against the viewer's Files in one read.
	 *
	 * @var int
	 */
	public const MAX_FILES = 5000;

	/**
	 * Constructor.
	 *
	 * @param AgentArtefactMarker $marker     Reads which files carry the two tags.
	 * @param IRootFolder         $rootFolder Opens the viewer's own Files.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	public function __construct(
		private readonly AgentArtefactMarker $marker,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * The label counts for one viewer.
	 *
	 * @param string $uid The viewer.
	 *
	 * @return array{files: int, reviewed: int, reviewedShare: int, tagId: string|null, truncated: bool}
	 *         `reviewedShare` is a whole percentage of `files`; `tagId` is the agent-authored
	 *         tag, for the link that opens Files filtered on it; `truncated` says more tagged
	 *         files exist than were checked.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	public function stats(string $uid): array {
		$authored = $this->marker->taggedFiles(name: AgentArtefactMarker::TAG_NAME);
		$reviewed = array_flip($this->marker->taggedFiles(name: AgentArtefactMarker::REVIEWED_TAG_NAME)['fileIds']);

		$checked = array_slice($authored['fileIds'], 0, self::MAX_FILES);
		$readable = $this->readable(uid: $uid, fileIds: $checked);
		$reviewedCount = count(array_filter($readable, static fn (string $fileId): bool => isset($reviewed[$fileId])));

		$share = 0;
		if ($readable !== []) {
			$share = (int)round(($reviewedCount * 100) / count($readable));
		}

		return [
			'files' => count($readable),
			'reviewed' => $reviewedCount,
			'reviewedShare' => $share,
			'tagId' => $authored['tagId'],
			'truncated' => count($authored['fileIds']) > self::MAX_FILES,
		];
	}//end stats()

	/**
	 * The file ids the viewer can open and read.
	 *
	 * @param string       $uid     The viewer.
	 * @param list<string> $fileIds The candidate ids.
	 *
	 * @return list<string> The readable ids.
	 */
	private function readable(string $uid, array $fileIds): array {
		if ($uid === '' || $fileIds === []) {
			return [];
		}

		try {
			$folder = $this->rootFolder->getUserFolder($uid);
		} catch (Throwable) {
			return [];
		}

		$readable = [];
		foreach ($fileIds as $fileId) {
			$node = ($folder->getById((int)$fileId)[0] ?? null);
			if ($node !== null && $node->isReadable() === true) {
				$readable[] = $fileId;
			}
		}

		return $readable;
	}//end readable()
}//end class
