<?php

/**
 * Value-object stub for OpenRegister's TaskInboxCriteria.
 *
 * Mirrors the REAL constructor of openregister development so hermiq's
 * ApprovalTaskInbox builds the same criteria it builds against the real
 * class; it only stores what it is given and decides nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * One inbox query: the audience and the selection, flattened.
 */
final class TaskInboxCriteria {

	public const SCOPE_ASSIGNED = 'assigned';
	public const SCOPE_POOLED = 'pooled';
	public const SCOPE_WATCHED = 'watched';
	public const SCOPE_ALL = 'all';

	/**
	 * The reader.
	 *
	 * @var string
	 */
	public readonly string $uid;

	/**
	 * The reader's groups.
	 *
	 * @var array<int, string>
	 */
	public readonly array $groupIds;

	/**
	 * The relationship asked about.
	 *
	 * @var string
	 */
	public readonly string $scope;

	/**
	 * Terminal or open; null for both.
	 *
	 * @var boolean|null
	 */
	public readonly ?bool $isTerminal;

	/**
	 * Constructor.
	 *
	 * @param TaskInboxAudience       $audience  Who reads.
	 * @param TaskInboxSelection|null $selection What is selected.
	 */
	public function __construct(
		TaskInboxAudience $audience,
		?TaskInboxSelection $selection = null,
	) {
		$selection = ($selection ?? new TaskInboxSelection());
		$this->uid = $audience->uid;
		$this->groupIds = $audience->groupIds;
		$this->scope = $audience->scope;
		$this->isTerminal = $selection->filter->isTerminal;
	}//end __construct()
}//end class
