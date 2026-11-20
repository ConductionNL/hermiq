<?php

/**
 * Hermiq SubscriptionLoginException.
 *
 * A subscription sign-in that cannot go on. Its message is shown to the user and
 * never carries a token; its code is the HTTP status the controller answers with.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Credential
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Credential;

use RuntimeException;

/**
 * A subscription sign-in refusal, with the HTTP status as its code.
 */
class SubscriptionLoginException extends RuntimeException {
}//end class
