#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest.js — schema-validates src/manifest.json against the
// @conduction/nextcloud-vue v1.1.0 app-manifest schema using Ajv.
//
// Usage:
//   node tests/validate-manifest.js
//
// Exit codes:
//   0 — manifest validates against the schema with zero errors
//   1 — manifest fails validation (or schema/manifest cannot be loaded)
//
// Schema lookup order (first hit wins):
//   1. Env var APP_MANIFEST_SCHEMA — explicit absolute path to a schema JSON
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json
//   3. ../nextcloud-vue/src/schemas/app-manifest.schema.json (sibling worktree)
//   4. /tmp/worktrees/nextcloud-vue-manifest-v1/src/schemas/app-manifest.schema.json (v1.2.0 consolidation worktree)
//   5. /tmp/worktrees/nextcloud-vue-page-type-extensions/src/schemas/app-manifest.schema.json (v1.1.0 fallback)
//
// The fourth / fifth options exist because the v1.x schema is not yet
// released to npm; the consolidated `manifest-v1` worktree carries the
// canonical v1.2.0 source. Once published, options 1 and 2 take over.

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')

// Default schema FILENAME. The manifest's own `$schema` declaration overrides
// it (see schemaFileName) — this is only the fallback for a manifest that
// declares nothing.
const DEFAULT_SCHEMA_FILE = 'app-manifest.schema.json'

/**
 * The schema file name this manifest asks to be validated against, taken from
 * its own `$schema` URL.
 *
 * This validator used to hardcode `app-manifest.schema.json` (v1) while the
 * manifest has declared `app-manifest-v2.schema.json` for a long time. Every
 * v2-only key — `setup`, `walkthrough`, per-widget `content`, `icon`, `_note`
 * comments — is `additionalProperties: false` under v1, so the check reported
 * 69 errors against a manifest that is in fact CLEAN (0 errors) under the
 * schema it declares. A permanently-red check is a check nobody reads, so the
 * geometry regression this run introduced would have landed unnoticed
 * underneath the noise. Only the basename is taken from the URL: the schema is
 * always read from the local library copy, never fetched.
 *
 * @param {object} manifest The parsed manifest.
 * @return {string} The schema file's basename.
 */
function schemaFileName(manifest) {
	const declared = typeof manifest.$schema === 'string' ? manifest.$schema : ''
	const base = declared.split('/').pop() || ''
	return /^app-manifest(-v\d+)?\.schema\.json$/.test(base)
		? base
		: DEFAULT_SCHEMA_FILE
}

/**
 * Candidate paths for a schema file name, nearest checkout first.
 *
 * @param {string} file The schema basename.
 * @return {Array<string>} Absolute candidate paths.
 */
function schemaCandidates(file) {
	return [
		process.env.APP_MANIFEST_SCHEMA,
		path.join(
			REPO_ROOT,
			'node_modules',
			'@conduction',
			'nextcloud-vue',
			'src',
			'schemas',
			file,
		),
		path.join(REPO_ROOT, '..', 'nextcloud-vue', 'src', 'schemas', file),
	].filter(Boolean)
}

function findSchemaPath(manifest) {
	const file = schemaFileName(manifest)
	for (const candidate of schemaCandidates(file)) {
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
	// The canonical schema uses JSON Schema draft 2020-12 (`$schema`:
	// "https://json-schema.org/draft/2020-12/schema"). Standard Ajv (v7+)
	// does not auto-load the 2020 meta-schema; we need the `ajv/dist/2020`
	// entry point.
	let Ajv2020
	let addFormats
	try {
		// Ajv 8+ ships the 2020 draft entry point.
		Ajv2020 = require('ajv/dist/2020').default || require('ajv/dist/2020')
	} catch (_) {
		try {
			// Fall back to standard Ajv (will fail to compile the 2020-draft
			// schema; we surface that error clearly).
			Ajv2020 = require('ajv').default || require('ajv')
		} catch (__) {
			console.error('[validate-manifest] Ajv not installed in node_modules.')
			console.error(
				'[validate-manifest] Install with: npm i -D ajv ajv-formats',
			)
			console.error(
				'[validate-manifest] Falling back to a structural lint pass.',
			)
			return { Ajv: null, addFormats: null }
		}
	}
	try {
		addFormats = require('ajv-formats').default || require('ajv-formats')
	} catch (_) {
		// ajv-formats is optional; the schema uses "uri" format on $schema
		// which without ajv-formats is silently accepted.
		addFormats = null
	}
	return { Ajv: Ajv2020, addFormats }
}

function structuralLint(manifest) {
	// Minimal structural fallback when Ajv isn't available.
	const errors = []
	if (!manifest.version || typeof manifest.version !== 'string') {
		errors.push('top-level: version (string) is required')
	}
	if (!Array.isArray(manifest.menu))
		errors.push('top-level: menu (array) is required')
	if (!Array.isArray(manifest.pages))
		errors.push('top-level: pages (array) is required')
	const allowedTypes = new Set([
		'index',
		'detail',
		'dashboard',
		'logs',
		'settings',
		'chat',
		'files',
		'custom',
	])
	const seenIds = new Set()
	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const page = manifest.pages[i]
		if (!page || typeof page !== 'object') {
			errors.push(`pages[${i}]: must be an object`)
			continue
		}
		for (const required of ['id', 'route', 'type', 'title']) {
			if (!page[required] || typeof page[required] !== 'string') {
				errors.push(
					`pages[${i}]: missing required string field "${required}"`,
				)
			}
		}
		if (page.type && !allowedTypes.has(page.type)) {
			errors.push(`pages[${i}].type: "${page.type}" not in v1.1 enum`)
		}
		if (page.id) {
			if (seenIds.has(page.id))
				errors.push(`pages[${i}].id: duplicate "${page.id}"`)
			seenIds.add(page.id)
		}
		if (page.type === 'custom' && !page.component) {
			errors.push(`pages[${i}]: type=custom requires component field`)
		}
	}
	return errors
}

/**
 * Lint widget-grid geometry: two layout items on the same page must not claim
 * the same cell. The JSON schema types every field correctly and says nothing
 * about how the rectangles relate, so raising one widget's gridHeight without
 * shifting the rows beneath it silently overlaps them — gridstack then reflows
 * at runtime and the rendered page no longer matches the manifest. Cheap to
 * check arithmetically, invisible in review.
 *
 * @param {object} manifest The parsed manifest.
 * @return {Array<string>} One error per overlapping pair.
 */
function gridGeometryLint(manifest) {
	const errors = []
	for (const page of manifest.pages || []) {
		const layout = (page.config || {}).layout
		if (!Array.isArray(layout)) continue
		const rects = layout
			.map((item) => ({
				id: item.widgetId || item.id,
				x: Number(item.gridX),
				y: Number(item.gridY),
				w: Number(item.gridWidth),
				h: Number(item.gridHeight),
			}))
			.filter((r) => [r.x, r.y, r.w, r.h].every(Number.isFinite))
		for (let a = 0; a < rects.length; a++) {
			for (let b = a + 1; b < rects.length; b++) {
				const p = rects[a]
				const q = rects[b]
				const overlaps =
					p.x < q.x + q.w
					&& q.x < p.x + p.w
					&& p.y < q.y + q.h
					&& q.y < p.y + p.h
				if (overlaps) {
					errors.push(
						`pages[id="${page.id}"].config.layout: "${p.id}" `
							+ `(x${p.x} y${p.y} w${p.w} h${p.h}) overlaps "${q.id}" `
							+ `(x${q.x} y${q.y} w${q.w} h${q.h})`,
					)
				}
			}
		}
	}
	return errors
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		console.error(`[validate-manifest] manifest not found: ${MANIFEST_PATH}`)
		process.exit(1)
	}

	const manifest = loadJson(MANIFEST_PATH)
	console.log(`[validate-manifest] manifest: ${MANIFEST_PATH}`)
	console.log(`[validate-manifest] manifest.version: ${manifest.version}`)
	console.log(`[validate-manifest] pages: ${(manifest.pages || []).length}`)

	// Runs on every path — the schema cannot express this, so it must not be
	// skipped just because Ajv is present and the types all check out.
	const geometryErrors = gridGeometryLint(manifest)
	if (geometryErrors.length > 0) {
		console.error('[validate-manifest] grid geometry: FAIL')
		for (const err of geometryErrors) console.error(`  - ${err}`)
		process.exit(1)
	}
	console.log(
		'[validate-manifest] grid geometry: PASS (no overlapping widget cells)',
	)

	const schemaPath = findSchemaPath(manifest)
	if (!schemaPath) {
		console.warn(
			'[validate-manifest] no schema candidate resolved; falling back to structural lint.',
		)
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log('[validate-manifest] structural lint: PASS (0 issues)')
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint: FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}
	console.log(`[validate-manifest] schema: ${schemaPath}`)
	const schema = loadJson(schemaPath)
	console.log(`[validate-manifest] schema.version: ${schema.version || '(unset)'}`)

	const { Ajv, addFormats } = loadAjv()
	if (!Ajv) {
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log(
				'[validate-manifest] structural lint (no Ajv): PASS (0 issues)',
			)
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint (no Ajv): FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}

	const ajv = new Ajv({ allErrors: true, strict: false })
	if (addFormats) addFormats(ajv)
	const validate = ajv.compile(schema)
	const ok = validate(manifest)
	if (ok) {
		console.log('[validate-manifest] Ajv validation: PASS (0 errors)')
		process.exit(0)
	}
	console.error('[validate-manifest] Ajv validation: FAIL')
	for (const err of validate.errors || []) {
		console.error(
			`  - ${err.instancePath || '(root)'} ${err.message} (keyword=${err.keyword})`,
		)
	}
	process.exit(1)
}

main()
