/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * One component action run behind a busy flag: the flag is up while the
 * action runs, the error message is cleared before it starts, and a failure
 * lands in the error message instead of escaping.
 */

import { errorMessage } from './errorMessage.js'

/**
 * The two data fields through which a component reports a running action.
 */
export class BusyFields {

	/**
	 * @param {string} busy The data field that is true while the action runs.
	 * @param {string} error The data field that holds the failure message.
	 */
	constructor(busy, error) {
		this.busy = busy
		this.error = error
		Object.freeze(this)
	}

}

/** The `busy` flag and `error` message most components use. */
export const BUSY_FIELDS = new BusyFields('busy', 'error')

/**
 * The runner for one component's actions.
 *
 * The runner takes the action and the translated fallback message (a function,
 * so it is only translated when a failure has neither a server nor a thrown
 * message) and resolves once the flag is down again.
 *
 * @param {object} vm The component.
 * @param {BusyFields} fields The fields the actions report through.
 * @return {function(function(): Promise<void>, function(): string): Promise<void>} The runner.
 * @spec exclude busy-flag plumbing shared by the UI's action handlers, no domain behaviour
 */
export function busyAction(vm, fields) {
	return async (attempt, fallback) => {
		vm[fields.busy] = true
		vm[fields.error] = ''
		try {
			await attempt()
		} catch (e) {
			vm[fields.error] = errorMessage(e, fallback())
		} finally {
			vm[fields.busy] = false
		}
	}
}
