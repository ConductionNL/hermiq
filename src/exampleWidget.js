// SPDX-FileCopyrightText: 2024 Conduction B.V.
// SPDX-License-Identifier: EUPL-1.2

/**
 * Dashboard widget renderer.
 *
 * The first argument to `OCA.Dashboard.register(...)` MUST equal the string
 * returned by `ExampleWidget::getId()` in `lib/Dashboard/ExampleWidget.php`.
 * If they don't match, Nextcloud's registry silently ignores the callback
 * and the widget renders blank — check the browser console for
 * `No callback registered for widget '<id>'`.
 *
 * @see lib/Dashboard/ExampleWidget.php
 */

import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'

import pinia from './pinia.js'
import ExampleWidget from './views/widgets/ExampleWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('hermiq_example_widget', (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(ExampleWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
