<template>
	<CnFeaturesAndRoadmapView
		:repo="repo"
		:features="features"
		:disabled="disabled" />
</template>

<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Features & Roadmap page — thin wrapper around `CnFeaturesAndRoadmapView`
// from `@conduction/nextcloud-vue`. Mounted as the manifest `custom` page
// `FeaturesRoadmap` (route `/features-roadmap`, surfaced from the Settings
// section of the nav).
//
// When scaffolding a new app from this template: change the
// `'ConductionNL/hermiq'` repo fallback below to YOUR app's
// GitHub `<owner>/<repo>`. `features` / `disabled` (and the repo) can also be
// provided by the backend via `IInitialState` (`<appId>::features_roadmap_*`);
// the fallbacks here keep the page usable without that wiring. The roadmap
// itself is powered by OpenRegister's `github-issue-proxy`.

import { CnFeaturesAndRoadmapView } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'FeaturesRoadmap',

	components: {
		CnFeaturesAndRoadmapView,
	},

	data() {
		return {
			repo: loadState('hermiq', 'features_roadmap_repo', 'ConductionNL/hermiq'),
			features: loadState('hermiq', 'features_roadmap_features', []),
			disabled: loadState('hermiq', 'features_roadmap_disabled', false),
		}
	},
}
</script>
