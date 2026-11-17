<?php

/**
 * Hermiq Validated File Set.
 *
 * The files that carry the validated tag (memory-retrieval-scope, D2), which a
 * turn in validated mode may search. No tag set, or a failing lookup, is no
 * file: validated mode then abstains rather than answer from anything else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\RetrievalScope
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\RetrievalScope;

use OCA\Hermiq\Kernel\Contracts\ValidatedTagSettingsInterface;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The files carrying the validated tag.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */
class ValidatedFileSet {

	/**
	 * Constructor.
	 *
	 * @param ValidatedTagSettingsInterface $settings The validated tag.
	 * @param ISystemTagObjectMapper        $tagged   Which files carry a tag.
	 * @param LoggerInterface               $logger   The log.
	 */
	public function __construct(
		private readonly ValidatedTagSettingsInterface $settings,
		private readonly ISystemTagObjectMapper $tagged,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ids of the files carrying the validated tag; none when no tag is set.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
	 */
	public function fileIds(): array {
		$tagId = $this->settings->tagId();
		if ($tagId === null || $tagId === '') {
			return [];
		}

		try {
			$ids = $this->tagged->getObjectIdsForTags(tagIds: $tagId, objectType: 'files');
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ValidatedFileSet] The validated files could not be listed; no file is searched',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}

		return array_values(array_unique(array_map(static fn (mixed $id): int => (int)$id, $ids)));
	}//end fileIds()
}//end class
