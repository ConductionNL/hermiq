<?php

/**
 * Hermiq: a person's own agent preferences (personal-preferences).
 *
 * The preferences are stored per Nextcloud user by PreferencesController
 * (`PUT /api/preferences/{key}`), which keeps them under `pref_<key>` with the
 * key reduced to lowercase letters, digits and hyphens. This class reads them
 * back for the server code that acts on them.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\AppIdentity;
use OCP\IConfig;

/**
 * Reads one person's preferences.
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
 */
class PersonalPreferences {

	/**
	 * "Vragen voordat een agent namens mij mailt of iets verstuurt".
	 *
	 * @var string
	 */
	public const ASK_BEFORE_SEND = 'ask-before-send';

	/**
	 * The values that switch a preference on.
	 *
	 * @var list<string>
	 */
	private const ON = ['1', 'true', 'on', 'yes'];

	/**
	 * Constructor.
	 *
	 * @param IConfig $config The per-user configuration.
	 *
	 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
	 */
	public function __construct(
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Whether a person switched a preference on; off when unset or unreadable.
	 *
	 * @param string $userId The person.
	 * @param string $key    The preference key, as the settings dialog sends it.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
	 */
	public function isOn(string $userId, string $key): bool {
		if ($userId === '') {
			return false;
		}

		$value = $this->config->getUserValue($userId, AppIdentity::APP_ID, self::storedKey(key: $key), '');

		return in_array(strtolower(trim((string)$value)), self::ON, true);
	}//end isOn()

	/**
	 * The stored key of a preference, as PreferencesController stores it.
	 *
	 * @param string $key The preference key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-a-persons-agent-preferences-are-kept-per-person-and-set-in-hermiqs-settings-dialog-req-pref-001
	 */
	public static function storedKey(string $key): string {
		return 'pref_' . substr((string)preg_replace('/[^a-z0-9-]/', '', strtolower($key)), 0, 64);
	}//end storedKey()
}//end class
