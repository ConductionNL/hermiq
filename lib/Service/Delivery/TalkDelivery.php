<?php

/**
 * Hermiq TalkDelivery
 *
 * What a Talk delivery needs: room bindings, approval notices, links and users.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Delivery
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
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Delivery;

use OCA\Hermiq\Service\Talk\TalkApprovalNotifier;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * What a Talk delivery needs: room bindings, approval notices, links and users.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class TalkDelivery {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Builds the schedule/run deep link for notifications.
	 * @param IUserManager $userManager Resolves the owner IUser for room creation / email fallback.
	 * @param TalkRoomBinding $talkRoomBinding Binds the run's conversation to the Talk room it
	 *                                         was delivered into, so the report can be replied to
	 *                                         (talk-chat-bridge).
	 * @param TalkApprovalNotifier $talkApprovalNotifier Posts an approval request into the agent's
	 *                                                   bound room as the bot, so it can be decided
	 *                                                   by a reaction (talk-approval-reactions).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly IURLGenerator $urlGenerator,
		public readonly IUserManager $userManager,
		public readonly TalkRoomBinding $talkRoomBinding,
		public readonly TalkApprovalNotifier $talkApprovalNotifier,
	) {
	}//end __construct()
}//end class
