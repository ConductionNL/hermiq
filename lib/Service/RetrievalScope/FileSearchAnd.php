<?php

/**
 * Hermiq File Search And.
 *
 * All of several file search conditions, against OCP's public ISearchBinaryOperator.
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

use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchOperator;

/**
 * An AND of file search conditions.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */
final class FileSearchAnd implements ISearchBinaryOperator {

	/**
	 * Query hints the server sets while planning.
	 *
	 * @var array<string, mixed>
	 */
	private array $hints = [];

	/**
	 * Constructor.
	 *
	 * @param list<ISearchOperator> $arguments The conditions.
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function __construct(
		private array $arguments,
	) {
	}//end __construct()

	/**
	 * The operator: always AND.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getType() {
		return ISearchBinaryOperator::OPERATOR_AND;
	}//end getType()

	/**
	 * The conditions.
	 *
	 * @return list<ISearchOperator>
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getArguments() {
		return $this->arguments;
	}//end getArguments()

	/**
	 * A query hint.
	 *
	 * @param string $name    The hint.
	 * @param mixed  $default Its default.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getQueryHint(string $name, $default) {
		return ($this->hints[$name] ?? $default);
	}//end getQueryHint()

	/**
	 * Set a query hint.
	 *
	 * @param string $name  The hint.
	 * @param mixed  $value Its value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function setQueryHint(string $name, $value): void {
		$this->hints[$name] = $value;
	}//end setQueryHint()
}//end class
