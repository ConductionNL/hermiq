/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Talk-delivery helpers for the per-user delivery-room picker (Personal settings).
 *
 * Rooms are read/created straight from the Nextcloud Talk (spreed) OCS API — the
 * user's own rooms, scoped by their session — so Hermiq never needs a server-side
 * proxy for listing or creating. The chosen room token is stored as a per-user
 * Hermiq preference (`delivertarget`) that DeliveryService falls back to when a
 * schedule has no explicit target.
 */

import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'

/** Talk group-conversation type (spreed Room::TYPE_GROUP). */
const ROOM_TYPE_GROUP = 2

/**
 * List the Talk rooms the current user is a member of.
 *
 * @return {Promise<Array<{token: string, name: string}>>} The user's rooms.
 */
export async function listRooms() {
	const { data } = await axios.get(generateOcsUrl('apps/spreed/api/v4/room'))
	const rooms = (data && data.ocs && data.ocs.data) || []
	return rooms.map((r) => ({
		token: r.token,
		name: r.displayName || r.name || r.token,
	}))
}

/**
 * Create a group Talk room the current user owns.
 *
 * @param {string} name The room display name.
 * @return {Promise<{token: string, name: string}>} The created room.
 */
export async function createRoom(name) {
	const { data } = await axios.post(generateOcsUrl('apps/spreed/api/v4/room'), {
		roomType: ROOM_TYPE_GROUP,
		roomName: name,
	})
	const room = (data && data.ocs && data.ocs.data) || {}
	return { token: room.token, name: room.displayName || name }
}

/**
 * Read the user's default Hermiq delivery-room token (`''` = Note to self).
 *
 * @return {Promise<string>} The stored room token, or `''`.
 */
export async function getDeliveryTarget() {
	const { data } = await axios.get(
		generateUrl('/apps/hermiq/api/preferences/delivertarget'),
	)
	return (data && data.value) || ''
}

/**
 * Persist the user's default delivery-room token (`''` clears it → Note to self).
 *
 * @param {string} token The room token, or `''` to clear.
 * @return {Promise<void>}
 */
export async function setDeliveryTarget(token) {
	await axios.put(generateUrl('/apps/hermiq/api/preferences/delivertarget'), {
		value: token || '',
	})
}
