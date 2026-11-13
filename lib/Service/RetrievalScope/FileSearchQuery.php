<?php

/**
 * Hermiq File Search Query.
 *
 * A Nextcloud file search for one person, against OCP's public ISearchQuery.
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
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\RetrievalScope;

use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/**
 * A file search as one person.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */
final class FileSearchQuery implements ISearchQuery {

	/**
	 * Constructor.
	 *
	 * @param ISearchOperator $operation The condition.
	 * @param int             $limit     The row cap.
	 * @param IUser           $user      The person searching.
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function __construct(
		private readonly ISearchOperator $operation,
		private readonly int $limit,
		private readonly IUser $user,
	) {
	}//end __construct()

	/**
	 * The condition.
	 *
	 * @return ISearchOperator
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getSearchOperation() {
		return $this->operation;
	}//end getSearchOperation()

	/**
	 * The row cap.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getLimit() {
		return $this->limit;
	}//end getLimit()

	/**
	 * The offset; always the first page.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getOffset() {
		return 0;
	}//end getOffset()

	/**
	 * The order; none.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getOrder() {
		return [];
	}//end getOrder()

	/**
	 * The person searching.
	 *
	 * @return IUser
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getUser() {
		return $this->user;
	}//end getUser()

	/**
	 * Whether to search the home storage only; no, shared files count.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function limitToHome(): bool {
		return false;
	}//end limitToHome()

	/**
	 * The fields to select; the server's default.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getSelectFields(): array {
		return [];
	}//end getSelectFields()
}//end class
