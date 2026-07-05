<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Support — the Hermiq "Support & sponsor" nav page.

  Hermiq is open source (EUPL-1.2). Rather than a donate button, support is routed
  through Conduction's partner/support model: commercial support, a partner
  programme, and sponsored development. This page presents those routes and links
  out to conduction.nl. No backend — a static informational page.
-->
<template>
	<div class="support">
		<div class="support__header">
			<h2 class="support__heading">
				{{ t('hermiq', 'Support & sponsor Hermiq') }}
			</h2>
			<p class="support__lede">
				{{ t('hermiq', 'Hermiq is free and open source (EUPL-1.2). It is built and maintained by Conduction. If it saves you time, here is how you can keep it healthy.') }}
			</p>
		</div>

		<div class="support__cards">
			<div class="support__card">
				<HeadsetIcon :size="32" class="support__icon" />
				<h3 class="support__card-title">
					{{ t('hermiq', 'Commercial support') }}
				</h3>
				<p class="support__card-body">
					{{ t('hermiq', 'Get an SLA, help with deployment and upgrades, and a direct line to the people who build Hermiq.') }}
				</p>
				<NcButton type="primary"
					href="https://www.conduction.nl/contact"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('hermiq', 'Contact Conduction') }}
				</NcButton>
			</div>

			<div class="support__card">
				<HandshakeIcon :size="32" class="support__icon" />
				<h3 class="support__card-title">
					{{ t('hermiq', 'Become a partner') }}
				</h3>
				<p class="support__card-body">
					{{ t('hermiq', 'Host, resell or co-develop Hermiq for your own customers as a Conduction partner.') }}
				</p>
				<NcButton type="secondary"
					href="https://www.conduction.nl/partners"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('hermiq', 'Partner programme') }}
				</NcButton>
			</div>

			<div class="support__card">
				<HeartIcon :size="32" class="support__icon" />
				<h3 class="support__card-title">
					{{ t('hermiq', 'Sponsor development') }}
				</h3>
				<p class="support__card-body">
					{{ t('hermiq', 'Fund a feature or the roadmap. Sponsored work stays open source for everyone.') }}
				</p>
				<NcButton type="secondary"
					href="https://www.conduction.nl/contact"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('hermiq', 'Sponsor a feature') }}
				</NcButton>
			</div>
		</div>

		<p class="support__foot">
			{{ t('hermiq', 'Prefer to contribute code or report issues?') }}
			<a class="support__link"
				href="https://codeberg.org/Conduction/hermiq"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('hermiq', 'Hermiq on Codeberg') }}
			</a>
		</p>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import HeadsetIcon from 'vue-material-design-icons/Headset.vue'
import HandshakeIcon from 'vue-material-design-icons/HandshakeOutline.vue'
import HeartIcon from 'vue-material-design-icons/HeartOutline.vue'

export default {
	name: 'Support',

	components: {
		NcButton,
		HeadsetIcon,
		HandshakeIcon,
		HeartIcon,
	},
}
</script>

<style scoped>
.support {
	padding: 20px;
	max-width: 960px;
	margin: 0 auto;
}

.support__heading {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.support__lede {
	color: var(--color-text-maxcontrast);
	margin: 0 0 24px;
	max-width: 640px;
}

.support__cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 16px;
}

.support__card {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
}

.support__icon {
	color: var(--color-primary-element, var(--color-primary));
}

.support__card-title {
	margin: 4px 0 0;
	font-size: 17px;
	font-weight: 600;
}

.support__card-body {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
	flex: 1;
}

.support__foot {
	margin-top: 24px;
	color: var(--color-text-maxcontrast);
}

.support__link {
	color: var(--color-primary-element, var(--color-primary));
	text-decoration: underline;
}
</style>
