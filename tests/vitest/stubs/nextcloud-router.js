/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @nextcloud/router stand-in: URLs are returned as given, under /index.php.
 */

export const generateUrl = (url) => '/index.php' + url
export const generateOcsUrl = (url) => '/ocs/v2.php' + url
export const imagePath = (app, file) => `/apps/${app}/img/${file}`
