/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @nextcloud/initial-state stand-in: a test sets `state[key]` before mounting.
 */

export const state = {}

/**
 * The value a test set for the key, else the fallback.
 *
 * @param {string} app The app id.
 * @param {string} key The state key.
 * @param {*} fallback The fallback.
 * @return {*} The value.
 */
export function loadState(app, key, fallback) {
	return key in state ? state[key] : fallback
}
