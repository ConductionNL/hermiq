<?php

/**
 * Minimal OpenRegister BrokerIdentity stub for standalone unit runs and static analysis.
 *
 * Mirrors openregister lib/Service/Config/BrokerIdentity.php. Registered at TEST
 * TIME only; the real class wins whenever OpenRegister is enabled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

/**
 * Who a brokered call is made for: the app and the acting user.
 */
final class BrokerIdentity {

	/**
	 * Constructor.
	 *
	 * @param string      $appId        The calling app id.
	 * @param string|null $actingUserId The acting user, or null.
	 */
	private function __construct(
		public readonly string $appId,
		public readonly ?string $actingUserId,
	) {
	}//end __construct()

	/**
	 * The identity for an app and an acting user; empty values fall back to OpenRegister and no user.
	 *
	 * @param string|null $appId        The calling app id.
	 * @param string|null $actingUserId The acting user.
	 *
	 * @return self
	 */
	public static function of(?string $appId = null, ?string $actingUserId = null): self {
		$app = trim((string)$appId);
		$user = trim((string)$actingUserId);

		return new self(appId: ($app === '' ? 'openregister' : $app), actingUserId: ($user === '' ? null : $user));
	}//end of()
}//end class
