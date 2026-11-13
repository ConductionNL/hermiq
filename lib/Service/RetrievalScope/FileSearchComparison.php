<?php

/**
 * Hermiq File Search Comparison.
 *
 * One comparison of a Nextcloud file search (field, operator, value), built
 * against OCP's public ISearchComparison so hermiq needs no server-internal class.
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

use OCP\Files\Search\ISearchComparison;

/**
 * A file search comparison.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */
final class FileSearchComparison implements ISearchComparison {

	/**
	 * Query hints the server sets while planning.
	 *
	 * @var array<string, mixed>
	 */
	private array $hints = [];

	/**
	 * Constructor.
	 *
	 * @param string                           $type  One of the COMPARE_ constants.
	 * @param string                           $field The file cache field.
	 * @param string|int|bool|\DateTime|array $value The value compared with.
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function __construct(
		private readonly string $type,
		private readonly string $field,
		private readonly string|int|bool|\DateTime|array $value,
	) {
	}//end __construct()

	/**
	 * The operator.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getType(): string {
		return $this->type;
	}//end getType()

	/**
	 * The field.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getField(): string {
		return $this->field;
	}//end getField()

	/**
	 * The extra qualifier; none here.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getExtra(): string {
		return '';
	}//end getExtra()

	/**
	 * The value.
	 *
	 * @return string|int|bool|\DateTime|array
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
	 */
	public function getValue(): string|int|bool|\DateTime|array {
		return $this->value;
	}//end getValue()

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
