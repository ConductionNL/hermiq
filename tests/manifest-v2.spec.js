#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// manifest-v2.spec.js — validate src/manifest.json against the v2 manifest
// schema using Ajv (draft 2020-12).
//
// Usage:
//   node tests/manifest-v2.spec.js
//
// Exit codes:
//   0 — manifest validates with zero errors
//   1 — manifest fails validation (or schema/manifest cannot be loaded)
//
// Schema lookup order (first hit wins):
//   1. Env var APP_MANIFEST_V2_SCHEMA — explicit absolute path to the v2 schema
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json
//   3. ../nextcloud-vue/src/schemas/app-manifest-v2.schema.json (sibling worktree)
//   4. /tmp/worktrees/nc-vue-manifest-v2-renderer/src/schemas/app-manifest-v2.schema.json
//
// Options 3 and 4 exist because the v2 library is not yet published to npm.
// Once nc-vue PRs #254/#255/#256 merge and the package is released, options
// 1 and 2 take over.
//
// When no v2 schema is found, the script falls back to a structural check
// that validates the mandatory v2 shape invariants (no layout[] arrays,
// no sidebarTabs, _note on custom pages, unified widget entries).

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')
const V2_SCHEMA_URL_SUFFIX = 'app-manifest-v2.schema.json'

const V2_SCHEMA_CANDIDATES = [
	process.env.APP_MANIFEST_V2_SCHEMA,
	path.join(REPO_ROOT, 'node_modules', '@conduction', 'nextcloud-vue', 'src', 'schemas', 'app-manifest-v2.schema.json'),
	path.join(REPO_ROOT, '..', 'nextcloud-vue', 'src', 'schemas', 'app-manifest-v2.schema.json'),
	'/tmp/worktrees/nc-vue-manifest-v2-renderer/src/schemas/app-manifest-v2.schema.json',
].filter(Boolean)

function findSchemaPath() {
	for (const candidate of V2_SCHEMA_CANDIDATES) {
		try {
			if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
				return candidate
			}
		} catch (_) {
			// continue to next candidate
		}
	}
	return null
}

function loadJson(file) {
	const raw = fs.readFileSync(file, 'utf8')
	return JSON.parse(raw)
}

function loadAjv() {
	let Ajv2020 = null
	let addFormats = null
	try {
		Ajv2020 = require('ajv/dist/2020').default || require('ajv/dist/2020')
	} catch (_) {
		try {
			Ajv2020 = require('ajv').default || require('ajv')
		} catch (__) {
			return { Ajv: null, addFormats: null }
		}
	}
	try {
		addFormats = require('ajv-formats').default || require('ajv-formats')
	} catch (_) {
		addFormats = null
	}
	return { Ajv: Ajv2020, addFormats }
}

/**
 * Structural checks for v2 invariants that must hold regardless of Ajv.
 * These mirror the requirements in openspec/changes/scaffold-v2/specs/scaffold-v2/spec.md.
 *
 * @param {object} manifest Parsed manifest
 * @return {string[]} Array of error messages (empty = pass)
 */
function structuralLintV2(manifest) {
	const errors = []

	// 1. $schema must point to the v2 URL
	if (typeof manifest.$schema !== 'string') {
		errors.push('top-level: $schema (string) is required for v2 manifests')
	} else if (!manifest.$schema.endsWith(V2_SCHEMA_URL_SUFFIX)) {
		errors.push(`top-level: $schema must end with "${V2_SCHEMA_URL_SUFFIX}", got "${manifest.$schema}"`)
	}

	// 2. version, menu, pages required
	if (!manifest.version || typeof manifest.version !== 'string') {
		errors.push('top-level: version (string) is required')
	}
	if (!Array.isArray(manifest.menu)) errors.push('top-level: menu (array) is required')
	if (!Array.isArray(manifest.pages)) errors.push('top-level: pages (array) is required')

	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const page = manifest.pages[i]
		if (!page || typeof page !== 'object') continue

		// 3. No top-level layout[] (v1 remnant)
		if (Object.prototype.hasOwnProperty.call(page, 'layout')) {
			errors.push(`pages[${i}] (id="${page.id}"): top-level layout[] is not allowed in v2 — use widgets[] with gridX/gridY`)
		}

		// 4. No config.sidebarTabs (v1 remnant)
		if (page.config && page.config.sidebarTabs) {
			errors.push(`pages[${i}] (id="${page.id}"): config.sidebarTabs is not allowed in v2 — lift to top-level widgets[] with slot: "sidebar"`)
		}

		// 5. type: "custom" requires _note
		if (page.type === 'custom' && (!page._note || typeof page._note !== 'string')) {
			errors.push(`pages[${i}] (id="${page.id}"): type: "custom" requires a non-empty _note field (ADR-036)`)
		}

		// 6. widgets[] entries (when present) must have required fields
		if (Array.isArray(page.widgets)) {
			for (let w = 0; w < page.widgets.length; w++) {
				const widget = page.widgets[w]
				if (!widget || typeof widget !== 'object') {
					errors.push(`pages[${i}].widgets[${w}]: must be an object`)
					continue
				}
				for (const field of ['widgetKey', 'slot', 'gridX', 'gridY', 'gridWidth', 'gridHeight']) {
					if (widget[field] === undefined) {
						errors.push(`pages[${i}].widgets[${w}]: missing required field "${field}"`)
					}
				}
				// sidebar slot: gridWidth must be 1
				if (widget.slot === 'sidebar' && widget.gridWidth !== 1) {
					errors.push(`pages[${i}].widgets[${w}] (slot=sidebar): gridWidth must be exactly 1, got ${widget.gridWidth}`)
				}
				// gridX + gridWidth <= 12 (for non-sidebar slots)
				if (widget.slot !== 'sidebar') {
					const gx = typeof widget.gridX === 'number' ? widget.gridX : NaN
					const gw = typeof widget.gridWidth === 'number' ? widget.gridWidth : NaN
					if (!isNaN(gx) && !isNaN(gw) && gx + gw > 12) {
						errors.push(`pages[${i}].widgets[${w}]: gridX (${gx}) + gridWidth (${gw}) exceeds 12`)
					}
				}
			}
		}
	}

	return errors
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		console.error(`[manifest-v2.spec] manifest not found: ${MANIFEST_PATH}`)
		process.exit(1)
	}

	const manifest = loadJson(MANIFEST_PATH)
	console.log(`[manifest-v2.spec] manifest: ${MANIFEST_PATH}`)
	console.log(`[manifest-v2.spec] manifest.version: ${manifest.version}`)
	console.log(`[manifest-v2.spec] manifest.$schema: ${manifest.$schema || '(absent)'}`)
	console.log(`[manifest-v2.spec] pages: ${(manifest.pages || []).length}`)

	// Quick structural check first (fast, no deps)
	const structErrors = structuralLintV2(manifest)
	if (structErrors.length > 0) {
		console.error('[manifest-v2.spec] structural lint: FAIL')
		for (const err of structErrors) console.error(`  - ${err}`)
		process.exit(1)
	}
	console.log('[manifest-v2.spec] structural lint: PASS')

	const schemaPath = findSchemaPath()
	if (!schemaPath) {
		console.warn('[manifest-v2.spec] v2 schema not found in any candidate path.')
		console.warn('[manifest-v2.spec] nc-vue PRs #254/#255/#256 not yet merged; falling back to structural-only check.')
		console.log('[manifest-v2.spec] structural-only: PASS (Ajv schema unavailable)')
		process.exit(0)
	}
	console.log(`[manifest-v2.spec] schema: ${schemaPath}`)

	const schema = loadJson(schemaPath)
	const { Ajv, addFormats } = loadAjv()

	if (!Ajv) {
		console.warn('[manifest-v2.spec] Ajv not available; structural-only PASS.')
		process.exit(0)
	}

	const ajv = new Ajv({ allErrors: true, strict: false, useDefaults: true })
	if (addFormats) addFormats(ajv)

	const validate = ajv.compile(schema)
	const clone = JSON.parse(JSON.stringify(manifest))
	const ok = validate(clone)

	if (ok) {
		// Post-schema: gridX + gridWidth <= 12
		const postErrors = []
		for (let i = 0; i < (clone.pages || []).length; i++) {
			const page = clone.pages[i]
			if (!page || !Array.isArray(page.widgets)) continue
			for (let w = 0; w < page.widgets.length; w++) {
				const widget = page.widgets[w]
				if (!widget) continue
				const gx = widget.gridX
				const gw = widget.gridWidth
				if (typeof gx === 'number' && typeof gw === 'number' && gx + gw > 12) {
					postErrors.push(
						`pages[${i}].widgets[${w}] (widgetKey="${widget.widgetKey}"): gridX (${gx}) + gridWidth (${gw}) exceeds 12`,
					)
				}
			}
		}
		if (postErrors.length > 0) {
			console.error('[manifest-v2.spec] post-schema check: FAIL')
			for (const err of postErrors) console.error(`  - ${err}`)
			process.exit(1)
		}
		console.log('[manifest-v2.spec] Ajv v2 validation: PASS (0 errors)')
		process.exit(0)
	}

	console.error('[manifest-v2.spec] Ajv v2 validation: FAIL')
	for (const err of validate.errors || []) {
		const instancePath = err.instancePath || err.schemaPath || '(root)'
		console.error(`  - ${instancePath} ${err.message} (keyword=${err.keyword})`)
	}
	process.exit(1)
}

main()
