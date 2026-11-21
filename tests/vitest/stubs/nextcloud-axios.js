/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @nextcloud/axios stand-in: tests replace the methods they need with vi.fn().
 */

const axios = {
	get: async () => ({ data: {} }),
	post: async () => ({ data: {} }),
	put: async () => ({ data: {} }),
	patch: async () => ({ data: {} }),
	delete: async () => ({ data: {} }),
}

export default axios
