<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ExampleWidget — scaffold demo for kind: "widget" registry entries.

  This is a placeholder demonstrating the registry pattern. Replace or
  delete it when scaffolding a new app. To add your own widget:

  1. Copy this file to src/widgets/<YourWidget>.vue.
  2. Register it in src/registry.js with kind: "widget".
  3. Reference it in src/manifest.json via widgetKey: "<your-key>".

  @spec openspec/changes/scaffold-v2/specs/scaffold-v2/spec.md
-->
<template>
	<div class="example-widget">
		<p class="example-widget__message">
			{{ message }}
		</p>
	</div>
</template>

<script>
export default {
	name: 'ExampleWidget',

	props: {
		/**
		 * Demo message rendered inside the widget.
		 * Replace with your widget's real props.
		 */
		message: {
			type: String,
			default: 'Hello from a custom widget (replace me)',
		},
	},
}
</script>

<style scoped>
.example-widget {
	padding: var(--default-grid-baseline, 4px);
	border: 1px dashed var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
}

.example-widget__message {
	color: var(--color-text-maxcontrast, #666);
	font-size: 0.875rem;
	margin: 0;
}
</style>
