<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  StatusBadge — scaffold demo for kind: "cell-renderer" registry entries.

  Registers with appliesTo: { schema: "item", property: "status" } so
  the object table automatically substitutes this component for the
  "status" column on "item" schema rows.

  Replace or delete when scaffolding a new app. To add your own
  cell-renderer:

  1. Copy this file to src/cellRenderers/<YourRenderer>.vue.
  2. Register it in src/registry.js with kind: "cell-renderer" and the
     appropriate appliesTo.schema + appliesTo.property.
  3. The object table will auto-bind it to matching columns.

  @spec openspec/changes/scaffold-v2/specs/scaffold-v2/spec.md
-->
<template>
	<span
		class="status-badge"
		:class="`status-badge--${normalised}`">
		{{ value }}
	</span>
</template>

<script>
export default {
	name: 'StatusBadge',

	props: {
		/** Raw cell value (the status string from the object). */
		value: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * Normalise the status to a CSS-safe class segment.
		 *
		 * @spec openspec/specs/scaffold-components/spec.md#REQ-COMP-001
		 * @return {string} Lowercased, hyphen-collapsed value (or "unknown").
		 */
		normalised() {
			return (this.value || 'unknown')
				.toLowerCase()
				.replace(/[^a-z0-9]+/g, '-')
		},
	},
}
</script>

<style scoped>
.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	background-color: var(--color-background-darker, #eee);
	color: var(--color-text-maxcontrast, #555);
}

.status-badge--open {
	background-color: var(--color-success-light, #d8f3dc);
	color: var(--color-success, #1a7431);
}

.status-badge--closed,
.status-badge--done {
	background-color: var(--color-background-darker, #e0e0e0);
	color: var(--color-text-maxcontrast, #555);
}

.status-badge--error,
.status-badge--failed {
	background-color: var(--color-error-light, #fde8e8);
	color: var(--color-error, #c0392b);
}

.status-badge--pending,
.status-badge--in-progress {
	background-color: var(--color-warning-light, #fff3cd);
	color: var(--color-warning-text, #856404);
}
</style>
