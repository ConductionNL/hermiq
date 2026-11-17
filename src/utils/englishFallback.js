/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The bundled English strings as a fallback under whatever Nextcloud already
 * registered for the user's language, so a key with no translation (the slugged
 * ones in particular) still reads as English and never as its key.
 */

/**
 * Register the English bundle beneath the translations already loaded.
 *
 * Nextcloud loads `l10n/<language>.js` before the app bundle runs, so a plain
 * `register(app, english)` here would overwrite every translated string.
 *
 * @param {(appId: string, translations: Record<string, string>) => void} register @nextcloud/l10n's register().
 * @param {string} appId The app whose strings these are.
 * @param {Record<string, string>} english The bundled en.json translations.
 * @param {Record<string, Record<string, string>>|undefined} registry The global translation registry.
 * @return {void}
 * @spec openspec/specs/i18n-completeness/spec.md#requirement-every-english-translation-key-must-have-a-non-empty-dutch-translation
 */
export function registerEnglishFallback(
	register,
	appId,
	english,
	registry = globalThis._oc_l10n_registry_translations,
) {
	register(appId, { ...english, ...(registry?.[appId] ?? {}) })
}
