// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom tools (tools-custom-tools-without-code): an organisation's own tools
// that call a flow or an integriq endpoint. Writes go through hermiq's
// /api/custom-tools endpoints, which only an organisation admin or instance
// admin passes; the generic objects path cannot express that rule.
//
// Stateless functions, no Pinia store. axios from @nextcloud/axios adds the CSRF
// request token.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq custom tools base path. */
const BASE = '/apps/hermiq/api/custom-tools'

/**
 * Create a custom tool in the caller's active organisation.
 *
 * @param {object} tool The tool: slug, name, description, inputSchema, changesData, target, enabled.
 * @return {Promise<object>} The stored tool with its id and toolId.
 *
 * @spec openspec/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
export async function createCustomTool(tool) {
	const response = await axios.post(generateUrl(BASE), tool)
	return response.data
}

/**
 * Update a custom tool.
 *
 * @param {string} id The tool's uuid.
 * @param {object} tool The tool's new fields.
 * @return {Promise<object>} The stored tool.
 *
 * @spec openspec/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
export async function updateCustomTool(id, tool) {
	const response = await axios.put(
		generateUrl(`${BASE}/${encodeURIComponent(id)}`),
		tool,
	)
	return response.data
}

/**
 * Run a stored custom tool once with sample inputs. This really runs the flow
 * or calls the system.
 *
 * @param {string} id The tool's uuid.
 * @param {object} args The sample inputs.
 * @return {Promise<object>} The tool's answer, or its error envelope.
 *
 * @spec openspec/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
export async function tryCustomTool(id, args) {
	try {
		const response = await axios.post(
			generateUrl(`${BASE}/${encodeURIComponent(id)}/try`),
			{ arguments: args },
		)
		return response.data
	} catch (error) {
		if (error?.response?.data?.error) {
			return error.response.data
		}
		throw error
	}
}

/**
 * Turn the editor's input rows into the tool's input schema.
 *
 * @param {Array<{name: string, type: string, required: boolean, description: string, enumText: string}>} inputs The rows.
 * @return {{type: string, properties: object, required: Array<string>}} The input schema.
 *
 * @spec openspec/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
export function inputSchemaFrom(inputs) {
	const properties = {}
	const required = []
	for (const input of inputs) {
		const name = (input.name || '').trim()
		if (name === '') {
			continue
		}
		properties[name] = propertyFrom(input)
		if (input.required) {
			required.push(name)
		}
	}
	return { type: 'object', properties, required }
}

/**
 * Turn one editor input row into its schema property.
 *
 * @param {{type: string, description: string, enumText: string}} input The row.
 * @return {object} The property: its type, a description when given, and an enum for a string with choices.
 *
 * @spec openspec/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
function propertyFrom(input) {
	const property = { type: input.type || 'string' }
	if ((input.description || '').trim() !== '') {
		property.description = input.description.trim()
	}
	const choices = (input.enumText || '')
		.split(',')
		.map((choice) => choice.trim())
		.filter((choice) => choice !== '')
	if (property.type === 'string' && choices.length > 0) {
		property.enum = choices
	}
	return property
}

/**
 * Turn a stored input schema back into editor rows.
 *
 * @param {object|null} schema The stored input schema.
 * @return {Array<{name: string, type: string, required: boolean, description: string, enumText: string}>} The rows.
 *
 * @spec openspec/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
export function inputsFrom(schema) {
	const properties = schema?.properties || {}
	const required = Array.isArray(schema?.required) ? schema.required : []
	return Object.keys(properties).map((name) => ({
		name,
		type: properties[name]?.type || 'string',
		required: required.includes(name),
		description: properties[name]?.description || '',
		enumText: Array.isArray(properties[name]?.enum)
			? properties[name].enum.join(', ')
			: '',
	}))
}

/**
 * How a sample text becomes a typed argument, per input type; any other type stays text.
 */
const SAMPLE_PARSERS = {
	integer: (text) => Number.parseInt(text, 10),
	number: (text) => Number(text),
	boolean: (text) => text === 'true',
}

/**
 * Turn the editor's sample values into typed arguments for "Try it".
 *
 * @param {Array<{name: string, type: string}>} inputs The input rows.
 * @param {object} samples The sample text per input name.
 * @return {object} The arguments; an empty sample is left out.
 *
 * @spec openspec/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
export function sampleArguments(inputs, samples) {
	const args = {}
	for (const input of inputs) {
		const name = (input.name || '').trim()
		const text = String(samples?.[name] ?? '').trim()
		if (name === '' || text === '') {
			continue
		}
		args[name] = Object.hasOwn(SAMPLE_PARSERS, input.type) ? SAMPLE_PARSERS[input.type](text) : text
	}
	return args
}

/**
 * The integriq target a form stores: the wait and the deadline only when the tool waits,
 * and the deadline only as a whole number of hours.
 *
 * @param {object} form The editable form.
 * @return {object}
 * @spec openspec/changes/archive/2026-11-25-flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
 */
export function integriqTargetFrom(form) {
	const target = {
		type: 'integriq',
		source: form.source.trim(),
		method: form.method,
		path: form.path.trim(),
		bodyTemplate: form.bodyTemplate.trim(),
	}
	if (form.waitForCallback !== true) {
		return target
	}

	target.waitForCallback = true
	const hours = Number.parseInt(String(form.deadlineHours).trim(), 10)
	if (Number.isInteger(hours) && hours >= 1 && hours <= 168) {
		target.deadlineHours = hours
	}

	return target
}
