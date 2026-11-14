/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The route contract of the thin API helpers: for each call, the verb, URL and
 * body it sends, and what it hands back from the reply. The helpers with their
 * own logic (list unwrapping, optional fields, grant flattening, speech) have
 * their own cases below the table.
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as agentAvailability from '../../src/api/agentAvailability.js'
import * as agents from '../../src/api/agents.js'
import * as agentTemplates from '../../src/api/agentTemplates.js'
import * as aiFeatures from '../../src/api/aiFeatures.js'
import * as analytics from '../../src/api/analytics.js'
import * as approvals from '../../src/api/approvals.js'
import * as assistantPrompts from '../../src/api/assistantPrompts.js'
import * as budgets from '../../src/api/budgets.js'
import * as compliance from '../../src/api/compliance.js'
import * as evals from '../../src/api/evals.js'
import * as flowRuns from '../../src/api/flowRuns.js'
import * as guardrailPolicy from '../../src/api/guardrailPolicy.js'
import * as intake from '../../src/api/intake.js'
import * as literacy from '../../src/api/literacy.js'
import * as llm from '../../src/api/llm.js'
import * as memory from '../../src/api/memory.js'
import * as modelPolicy from '../../src/api/modelPolicy.js'
import * as reportSimilarity from '../../src/api/reportSimilarity.js'
import * as runRetention from '../../src/api/runRetention.js'
import * as scheduleWebhookSecret from '../../src/api/scheduleWebhookSecret.js'
import * as skills from '../../src/api/skills.js'
import * as speech from '../../src/api/speech.js'
import * as talk from '../../src/api/talk.js'
import * as talkBridge from '../../src/api/talkBridge.js'
import * as tenantOps from '../../src/api/tenantOps.js'
import * as toolOversight from '../../src/api/toolOversight.js'
import * as toolTaxonomy from '../../src/api/toolTaxonomy.js'
import * as webhooks from '../../src/api/webhooks.js'
import * as webResearch from '../../src/api/webResearch.js'
import { createConnectionHandlers, INTEGRIQ_CONNECTIONS_PATH } from '../../src/services/connectionRegistry.js'

const H = '/index.php/apps/hermiq/api'
const BODY = { echo: true }

/**
 * Point every axios verb at a recording double that answers `data`.
 *
 * @param {*} data The response body.
 * @return {Array} The recorded calls as [verb, url, second argument].
 */
function record(data) {
	const calls = []
	for (const verb of ['get', 'post', 'put', 'patch', 'delete']) {
		vi.spyOn(axios, verb).mockImplementation(async (url, second) => {
			calls.push([verb, url, second])
			return { data }
		})
	}
	return calls
}

afterEach(() => {
	vi.restoreAllMocks()
})

// [label, call, verb, url, second argument (body or config)]; each returns the body as answered.
const PASS_THROUGH = [
	['availability read', () => agentAvailability.getAvailability('a b'), 'get', `${H}/agents/a%20b/availability`, undefined],
	['availability set', () => agentAvailability.setAvailability('a', false, 'holiday'), 'post', `${H}/agents/a/availability`, { active: false, reason: 'holiday' }],
	['template create', () => agentTemplates.createAgentTemplate(BODY), 'post', `${H}/agent-templates`, BODY],
	['template update', () => agentTemplates.updateAgentTemplate('t1', BODY), 'put', `${H}/agent-templates/t1`, BODY],
	['template delete', () => agentTemplates.deleteAgentTemplate('t1'), 'delete', `${H}/agent-templates/t1`, undefined],
	['template from agent', () => agentTemplates.saveAgentAsTemplate('a1'), 'post', `${H}/agent-templates/from-agent/a1`, undefined],
	['template import', () => agentTemplates.importAgentTemplate('PKG'), 'post', `${H}/agent-templates/import`, { package: 'PKG', source: 'org' }],
	['template import from hub', () => agentTemplates.importAgentTemplate('PKG', 'hub'), 'post', `${H}/agent-templates/import`, { package: 'PKG', source: 'hub' }],
	['template collect', () => agentTemplates.collectAppTemplates(), 'post', `${H}/agent-templates/collect-from-apps`, undefined],
	['template approve', () => agentTemplates.approveAgentTemplate('t1'), 'post', `${H}/agent-templates/t1/approve`, { force: false }],
	['template approve forced', () => agentTemplates.approveAgentTemplate('t1', true), 'post', `${H}/agent-templates/t1/approve`, { force: true }],
	['template instantiate', () => agentTemplates.instantiateAgentTemplate('t1'), 'post', `${H}/agent-templates/t1/instantiate`, { overrides: {} }],
	['template github search', () => agentTemplates.searchGithubTemplates('bot', 'c1'), 'get', `${H}/agent-templates/github/search`, { params: { q: 'bot', credentialId: 'c1' } }],
	['template github search, no filters', () => agentTemplates.searchGithubTemplates(), 'get', `${H}/agent-templates/github/search`, { params: {} }],
	['template github install', () => agentTemplates.installGithubTemplate({ owner: 'o', repo: 'r' }), 'post', `${H}/agent-templates/github/install`, { owner: 'o', repo: 'r', ref: null, credentialId: null }],
	['template github publish', () => agentTemplates.publishAgentTemplateToGithub('t1', { owner: 'o', repo: 'r', credentialId: 'c' }), 'post', `${H}/agent-templates/t1/publish-github`, { owner: 'o', repo: 'r', visibility: 'private', credentialId: 'c' }],
	['prompt preview', () => agents.previewPrompt('a/1', BODY), 'post', `${H}/agents/a%2F1/prompt-preview`, BODY],
	['draft check', () => agents.checkAgentDraft(BODY), 'post', `${H}/agents/draft-check`, { draft: BODY }],
	['schedule run now', () => agents.runScheduleNow('s1'), 'post', `${H}/schedules/s1/run`, undefined],
	['run trace', () => agents.getRunTrace('s1', 'r1'), 'get', `${H}/schedules/s1/runs/r1/trace`, undefined],
	['dry run', () => agents.dryRunSchedule('s1'), 'post', `${H}/schedules/s1/dry-run`, undefined],
	['replay', () => agents.replayRun('s1', 'r1'), 'post', `${H}/schedules/s1/runs/r1/replay`, undefined],
	['version rollback', () => agents.rollbackAgentVersion('a1', 'v1'), 'post', `${H}/agents/a1/versions/v1/rollback`, undefined],
	['app assistant', () => agents.setAppAssistant('a1', true), 'post', `${H}/agents/a1/app-assistant`, { appAssistant: true }],
	['git publish', () => agents.publishAgentToGit('a1', BODY), 'post', `${H}/agents/a1/git/publish`, BODY],
	['git push', () => agents.pushAgentToGit('a1', 'c1'), 'post', `${H}/agents/a1/git/push`, { credentialId: 'c1' }],
	['git pull preview', () => agents.previewAgentPull('a1', 'c1'), 'get', `${H}/agents/a1/git/pull`, { params: { credentialId: 'c1' } }],
	['git pull apply', () => agents.applyAgentPull('a1', 'c1'), 'post', `${H}/agents/a1/git/pull`, { credentialId: 'c1' }],
	['feature acknowledge', () => aiFeatures.acknowledgeAiFeature('f'), 'post', `${H}/ai-features/f/acknowledge`, undefined],
	['feature enable', () => aiFeatures.enableAiFeature('f'), 'post', `${H}/ai-features/f/enable`, undefined],
	['feature disable', () => aiFeatures.disableAiFeature('f'), 'post', `${H}/ai-features/f/disable`, undefined],
	['feature publish', () => aiFeatures.publishAiFeature('f'), 'post', `${H}/ai-features/f/publish`, undefined],
	['feature withdraw', () => aiFeatures.withdrawAiFeature('f'), 'post', `${H}/ai-features/f/withdraw`, undefined],
	['feature bind', () => aiFeatures.bindAiFeature('f', { provider: 'p', model: 'm' }), 'put', `${H}/ai-features/f/binding`, { provider: 'p', model: 'm', requiredResidency: '' }],
	['analytics for all', () => analytics.getAnalytics(), 'get', `${H}/analytics`, { params: {} }],
	['analytics for one agent', () => analytics.getAnalytics('a1'), 'get', `${H}/analytics`, { params: { agentId: 'a1' } }],
	['runs, unfiltered', () => analytics.listRuns(), 'get', `${H}/runs`, { params: { limit: 50, offset: 0 } }],
	['runs, filtered', () => analytics.listRuns({ agentId: 'a', status: 'failed', limit: 5, offset: 5 }), 'get', `${H}/runs`, { params: { limit: 5, offset: 5, agentId: 'a', status: 'failed' } }],
	['runs compare', () => analytics.compareRuns('l', 'r'), 'get', `${H}/runs/compare`, { params: { left: 'l', right: 'r' } }],
	['approval approve', () => approvals.approveApproval('p1'), 'post', `${H}/approvals/p1/approve`, undefined],
	['approval deny', () => approvals.denyApproval('p1', 'no'), 'post', `${H}/approvals/p1/deny`, { reason: 'no' }],
	['approval deny without reason', () => approvals.denyApproval('p1'), 'post', `${H}/approvals/p1/deny`, { reason: '' }],
	['kill switch read', () => approvals.getKillSwitch('org'), 'get', `${H}/tenant-control/org`, undefined],
	['kill switch engage', () => approvals.toggleKillSwitch('org', true, 'incident'), 'post', `${H}/tenant-control/org/toggle`, { engaged: 'true', reason: 'incident' }],
	['kill switch release', () => approvals.toggleKillSwitch('org', false), 'post', `${H}/tenant-control/org/toggle`, { engaged: 'false', reason: '' }],
	['prompt create', () => assistantPrompts.saveAssistantPrompt('', BODY), 'post', `${H}/assistant-prompts`, BODY],
	['prompt update', () => assistantPrompts.saveAssistantPrompt('p1', BODY), 'put', `${H}/assistant-prompts/p1`, BODY],
	['prompts disable all', () => assistantPrompts.disableAllAssistantPrompts(), 'post', `${H}/assistant-prompts/disable-all`, {}],
	['prompts disable in scope', () => assistantPrompts.disableAllAssistantPrompts('chat'), 'post', `${H}/assistant-prompts/disable-all`, { scope: 'chat' }],
	['budget status', () => budgets.getBudgetStatus('org'), 'get', `${H}/budgets/status`, { params: { organisation: 'org', agentId: '' } }],
	['budget create', () => budgets.createBudget(BODY), 'post', `${H}/budgets`, BODY],
	['budget update', () => budgets.updateBudget('b1', BODY), 'put', `${H}/budgets/b1`, BODY],
	['budget delete', () => budgets.deleteBudget('b1'), 'delete', `${H}/budgets/b1`, undefined],
	['budget estimate', () => budgets.getBudgetEstimate('a1'), 'get', `${H}/agents/a1/budget-estimate`, undefined],
	['compliance dashboard', () => compliance.getComplianceDashboard(), 'get', `${H}/compliance/dashboard`, undefined],
	['compliance export', () => compliance.getComplianceExport(), 'get', `${H}/compliance/export`, undefined],
	['factsheet', () => compliance.getAgentFactsheet('a1'), 'get', `${H}/compliance/factsheet/a1`, undefined],
	['flow run', () => flowRuns.getFlowRun('u 1'), 'get', '/index.php/apps/openregister/api/flow-runs/u%201', undefined],
	['guardrail effective', () => guardrailPolicy.getEffectiveGuardrailPolicy(), 'get', `${H}/guardrail-policies/effective`, undefined],
	['guardrail create', () => guardrailPolicy.createGuardrailPolicy(BODY), 'post', `${H}/guardrail-policies`, BODY],
	['guardrail update', () => guardrailPolicy.updateGuardrailPolicy('g1', BODY), 'put', `${H}/guardrail-policies/g1`, BODY],
	['intake read', () => intake.getIntakeSettings(), 'get', `${H}/settings/intake`, undefined],
	['intake set', () => intake.setIntakeSettings(0.4), 'put', `${H}/settings/intake`, { abstentionThreshold: 0.4 }],
	['lessons', () => literacy.getLessons(), 'get', `${H}/literacy/lessons`, undefined],
	['lesson answer', () => literacy.answerLesson('a b', 2), 'post', `${H}/literacy/lessons/a%20b/answer`, { choice: 2 }],
	['literacy overview', () => literacy.getOverview(), 'get', `${H}/literacy/overview`, undefined],
	['literacy requirement', () => literacy.setRequirement(true), 'put', `${H}/literacy/requirement`, { required: true }],
	['llm read', () => llm.getLlmSettings(), 'get', `${H}/settings/llm`, undefined],
	['llm patch', () => llm.patchLlmSettings(BODY), 'patch', `${H}/settings/llm`, { llm: BODY }],
	['residency read', () => llm.getProviderResidency(), 'get', `${H}/settings/provider-residency`, undefined],
	['residency declare', () => llm.declareProviderResidency('open ai', BODY), 'put', `${H}/settings/provider-residency/open%20ai`, BODY],
	['data use read', () => llm.getProviderDataUse(), 'get', `${H}/settings/provider-data-use`, undefined],
	['data use declare', () => llm.declareProviderDataUse('p', BODY), 'put', `${H}/settings/provider-data-use/p`, BODY],
	['memory read', () => memory.getMemory('a1'), 'get', `${H}/agents/a1/memory`, undefined],
	['memory add', () => memory.addMemory('a1', 'fact'), 'post', `${H}/agents/a1/memory`, { text: 'fact' }],
	['memory consolidate all', () => memory.consolidateMemory('a1'), 'post', `${H}/agents/a1/memory/consolidate`, {}],
	['memory consolidate some', () => memory.consolidateMemory('a1', ['e1']), 'post', `${H}/agents/a1/memory/consolidate`, { entries: ['e1'] }],
	['memory correct', () => memory.correctMemoryEntry('a1', 'e1', 'new'), 'put', `${H}/agents/a1/memory/entries/e1`, { text: 'new' }],
	['memory forget', () => memory.forgetMemoryEntry('a1', 'e1'), 'delete', `${H}/agents/a1/memory/entries/e1`, undefined],
	['model policy effective', () => modelPolicy.getEffectiveModelPolicy(), 'get', `${H}/model-policy/effective`, undefined],
	['model policy create', () => modelPolicy.createModelPolicy(BODY), 'post', `${H}/model-policy`, BODY],
	['model policy update', () => modelPolicy.updateModelPolicy('m1', BODY), 'put', `${H}/model-policy/m1`, BODY],
	['similarity read', () => reportSimilarity.getReportSimilaritySettings(), 'get', `${H}/settings/report-similarity`, undefined],
	['similarity set', () => reportSimilarity.setReportSimilaritySettings(BODY), 'put', `${H}/settings/report-similarity`, BODY],
	['retention read', () => runRetention.getRunRetention(), 'get', `${H}/settings/run-retention`, undefined],
	['retention set', () => runRetention.setRunRetention(30), 'put', `${H}/settings/run-retention`, { defaultDays: 30 }],
	['schedule secret status', () => scheduleWebhookSecret.getScheduleWebhookSecretStatus('s1'), 'get', `${H}/schedules/s1/webhook-secret`, undefined],
	['schedule secret mint', () => scheduleWebhookSecret.mintScheduleWebhookSecret('s1'), 'post', `${H}/schedules/s1/webhook-secret`, undefined],
	['schedule secret rotate', () => scheduleWebhookSecret.rotateScheduleWebhookSecret('s1'), 'post', `${H}/schedules/s1/webhook-secret/rotate`, undefined],
	['schedule secret revoke', () => scheduleWebhookSecret.revokeScheduleWebhookSecret('s1'), 'post', `${H}/schedules/s1/webhook-secret/revoke`, undefined],
	['skill import', () => skills.importSkill('PKG'), 'post', `${H}/skills`, { package: 'PKG' }],
	['skill install', () => skills.installSkill('k1', 'a1'), 'post', `${H}/skills/k1/install`, { agentId: 'a1' }],
	['skill uninstall', () => skills.uninstallSkill('k1', 'a1'), 'delete', `${H}/skills/k1/install/a1`, undefined],
	['skill update', () => skills.updateSkill('k1', BODY), 'put', `${H}/skills/k1`, BODY],
	['skill qualify', () => skills.qualifySkill('k1'), 'post', `${H}/skills/k1/qualify`, undefined],
	['skill attest', () => skills.attestSkillL4('k1'), 'post', `${H}/skills/k1/attest-l4`, { note: '' }],
	['skill install from source', () => skills.installFromSource('PKG'), 'post', `${H}/skills/install-from-source`, { package: 'PKG', source: 'hub' }],
	['skill approve', () => skills.approveSkill('k1'), 'post', `${H}/skills/k1/approve`, undefined],
	['skill publish', () => skills.publishSkill('k1'), 'post', `${H}/skills/k1/publish`, { hubId: 'default' }],
	['skill github search', () => skills.searchGithubSkills('q'), 'get', `${H}/skills/github/search`, { params: { q: 'q' } }],
	['skill github install', () => skills.installGithubSkill({ owner: 'o', repo: 'r', ref: 'v1' }), 'post', `${H}/skills/github/install`, { owner: 'o', repo: 'r', ref: 'v1', credentialId: null }],
	['skill github publish', () => skills.publishSkillToGithub('k1', { owner: 'o', repo: 'r', visibility: 'public' }), 'post', `${H}/skills/k1/github/publish`, { owner: 'o', repo: 'r', visibility: 'public', credentialId: undefined }],
	['skill propose', () => skills.proposeSkillImprovement('k1'), 'post', `${H}/skills/k1/propose-improvement`, undefined],
	['draft content', () => skills.updateSkillDraftContent('d1', BODY), 'post', `${H}/skill-drafts/d1/content`, BODY],
	['draft accept', () => skills.acceptSkillDraft('d1'), 'post', `${H}/skill-drafts/d1/accept`, undefined],
	['draft reject', () => skills.rejectSkillDraft('d1'), 'post', `${H}/skill-drafts/d1/reject`, { note: '', rejectedLearningRefs: [] }],
	['skill rollback', () => skills.rollbackSkill('k1', 'v1'), 'post', `${H}/skills/k1/rollback`, { versionId: 'v1' }],
	['skill republish', () => skills.republishSkill('k1', 'c1'), 'post', `${H}/skills/k1/republish`, { credentialId: 'c1' }],
	['talk bridge status', () => talkBridge.getTalkBridgeStatus(), 'get', `${H}/settings/talk-bridge`, undefined],
	['talk bridge bind', () => talkBridge.bindTalkRoom('tok', 'a1'), 'put', `${H}/settings/talk-bridge/room`, { token: 'tok', agentId: 'a1' }],
	['quota', () => tenantOps.getQuota(), 'get', `${H}/tenant-ops/quota`, undefined],
	['audit export', () => tenantOps.getAuditExport(), 'get', `${H}/tenant-ops/audit-export`, undefined],
	['access review', () => tenantOps.getAccessReview(), 'get', `${H}/tenant-ops/access-review`, undefined],
	['attest', () => tenantOps.attestReviewed('u1'), 'post', `${H}/tenant-ops/access-review/u1/attest`, undefined],
	['reassign', () => tenantOps.reassignAgent('u1', 'bob'), 'post', `${H}/tenant-ops/access-review/u1/reassign`, { actingUser: 'bob' }],
	['incidents', () => tenantOps.getIncidents(), 'get', `${H}/tenant-ops/incidents`, undefined],
	['incident create', () => tenantOps.createIncident(BODY), 'post', `${H}/tenant-ops/incidents`, BODY],
	['tenant retention read', () => tenantOps.getRetention(), 'get', `${H}/tenant-ops/retention`, undefined],
	['tenant retention set', () => tenantOps.setRetention(12), 'put', `${H}/tenant-ops/retention`, { retentionMonths: 12 }],
	['tool catalog', () => toolOversight.getToolCatalog('a1'), 'get', `${H}/agents/a1/tool-catalog`, undefined],
	['tool grants', () => toolOversight.updateToolGrants('a1', ['x']), 'put', `${H}/agents/a1/tool-grants`, { grants: ['x'] }],
	['tool invocations', () => toolOversight.getToolInvocations('a1', { limit: 3 }), 'get', `${H}/agents/a1/tool-invocations`, { params: { limit: 3 } }],
	['web research read', () => webResearch.getWebResearchSettings(), 'get', `${H}/settings/web-research`, undefined],
	['web research patch', () => webResearch.patchWebResearchSettings(BODY), 'patch', `${H}/settings/web-research`, { webResearch: BODY }],
	['agent secret status', () => webhooks.getWebhookStatus('a1'), 'get', `${H}/agents/a1/webhook-secret`, undefined],
	['agent secret create', () => webhooks.createWebhookSecret('a1'), 'post', `${H}/agents/a1/webhook-secret`, undefined],
	['agent secret rotate', () => webhooks.rotateWebhookSecret('a1'), 'post', `${H}/agents/a1/webhook-secret/rotate`, undefined],
	['agent secret revoke', () => webhooks.revokeWebhookSecret('a1'), 'post', `${H}/agents/a1/webhook-secret/revoke`, undefined],
	['agent secret patch', () => webhooks.patchWebhookSecret('a1', BODY), 'patch', `${H}/agents/a1/webhook-secret`, BODY],
	['speech synthesis', () => speech.synthesise('Hi'), 'post', `${H}/speech/speech`, { text: 'Hi' }],
]

describe('route contract', () => {
	it.each(PASS_THROUGH)('%s', async (_label, call, verb, url, second) => {
		const reply = { reply: 1 }
		const calls = record(reply)
		expect(await call()).toEqual(reply)
		expect(calls).toHaveLength(1)
		expect(calls[0][0]).toBe(verb)
		expect(calls[0][1]).toBe(url)
		expect(calls[0][2]).toEqual(second)
	})
})

// [label, call, url, answer, expected]: list readers and their envelope handling.
const LISTS = [
	['templates', () => agentTemplates.listAgentTemplates(), `${H}/agent-templates`, { results: [1] }, [1]],
	['agent runs', () => agents.listRuns('s1'), `${H}/schedules/s1/runs`, { data: { results: [2] } }, [2]],
	['agent versions', () => agents.listAgentVersions('a1'), `${H}/agents/a1/versions`, [3], [3]],
	['features', () => aiFeatures.listAiFeatures(), `${H}/ai-features`, { results: [4] }, [4]],
	['feature residency', () => aiFeatures.listFeatureResidency(), `${H}/ai-features/residency`, { nope: 1 }, []],
	['low ratings', () => analytics.listLowRatings('a1'), `${H}/analytics/agents/a1/low-ratings`, { results: [5] }, [5]],
	['low ratings, malformed', () => analytics.listLowRatings('a1'), `${H}/analytics/agents/a1/low-ratings`, { results: 'x' }, []],
	['pending approvals', () => approvals.listPendingApprovals(), `${H}/approvals`, { data: { results: [6] } }, [6]],
	['prompts', () => assistantPrompts.listAssistantPrompts(), `${H}/assistant-prompts`, { results: [7] }, [7]],
	['prompts in a scope', () => assistantPrompts.listAssistantPrompts('a b'), `${H}/assistant-prompts?scope=a%20b`, {}, []],
	['flows, bare list', () => flowRuns.listFlows(), '/index.php/apps/openregister/api/flows', [8], [8]],
	['flows, envelope', () => flowRuns.listFlows(), '/index.php/apps/openregister/api/flows', { results: [9] }, [9]],
	['flows, nothing', () => flowRuns.listFlows(), '/index.php/apps/openregister/api/flows', null, []],
	['guardrail policies', () => guardrailPolicy.listGuardrailPolicies(), `${H}/guardrail-policies`, { policies: [10] }, [10]],
	['guardrail policies, malformed', () => guardrailPolicy.listGuardrailPolicies(), `${H}/guardrail-policies`, { policies: {} }, []],
	['user profiles', () => memory.listUserProfiles('a1'), `${H}/agents/a1/user-profiles`, { results: [11] }, [11]],
	['model policies', () => modelPolicy.listModelPolicies(), `${H}/model-policy`, { policies: [12] }, [12]],
	['model policies, malformed', () => modelPolicy.listModelPolicies(), `${H}/model-policy`, {}, []],
	['skills', () => skills.listSkills(), `${H}/skills`, { results: [13] }, [13]],
	['skill drafts', () => skills.listSkillDrafts('k1'), `${H}/skills/k1/drafts`, [14], [14]],
	['skill versions', () => skills.listSkillVersions('k1'), `${H}/skills/k1/versions`, {}, []],
	['tool taxonomy', () => toolTaxonomy.getToolTaxonomy(), `${H}/agents/tools`, { results: [15] }, [15]],
	['tool taxonomy, none', () => toolTaxonomy.getToolTaxonomy(), `${H}/agents/tools`, {}, []],
	['template export', () => agentTemplates.exportAgentTemplate('t1'), `${H}/agent-templates/t1/export`, { package: 'P' }, 'P'],
	['template export, empty', () => agentTemplates.exportAgentTemplate('t1'), `${H}/agent-templates/t1/export`, {}, ''],
	['agent export as template', () => agentTemplates.exportAgentToTemplate('a1'), `${H}/agent-templates/from-agent/a1/export`, { package: 'Q' }, 'Q'],
	['skill export', () => skills.exportSkill('k1'), `${H}/skills/k1/export`, {}, ''],
	['delivery target', () => talk.getDeliveryTarget(), `${H}/preferences/delivertarget`, { value: 'tok' }, 'tok'],
	['delivery target, unset', () => talk.getDeliveryTarget(), `${H}/preferences/delivertarget`, null, ''],
]

describe('list and value readers', () => {
	it.each(LISTS)('%s', async (_label, call, url, answer, expected) => {
		const calls = record(answer)
		expect(await call()).toEqual(expected)
		expect(calls[0][1]).toBe(url)
	})
})

describe('helpers with their own shaping', () => {
	it('lists budgets from a budgets envelope, a bare list, or nothing', async () => {
		const calls = record({ budgets: [1] })
		expect(await budgets.listBudgets('org')).toEqual([1])
		expect(calls[0][2]).toEqual({ params: { organisation: 'org' } })
		record([2])
		expect(await budgets.listBudgets('org')).toEqual([2])
		record({})
		expect(await budgets.listBudgets('org')).toEqual([])
	})

	it('lists tools from an array, an id-keyed map, or nothing', async () => {
		record({ results: [{ id: 'a' }] })
		expect(await agents.listTools()).toEqual([{ id: 'a' }])
		record({ x: { name: 'X' }, y: { name: 'Y' } })
		expect(await agents.listTools()).toEqual([{ id: 'x', name: 'X' }, { id: 'y', name: 'Y' }])
		record('nope')
		expect(await agents.listTools()).toEqual([])
	})

	it('diffs agent and skill versions with the from/to pair, defaulting to an empty diff', async () => {
		const calls = record({ results: { a: 1 } })
		expect(await agents.diffAgentVersions('a1', 'v1', 'v2')).toEqual({ a: 1 })
		expect(calls[0][2]).toEqual({ params: { from: 'v1', to: 'v2' } })
		record({ diff: { b: 2 } })
		expect(await skills.diffSkillVersions('k1', 'v1', 'v2')).toEqual({ b: 2 })
		record({})
		expect(await agents.diffAgentVersions('a1', 'v1', 'v2')).toEqual({})
		expect(await skills.diffSkillVersions('k1', 'v1', 'v2')).toEqual({})
	})

	it('reads model policies together with their data-use declarations', async () => {
		record({ policies: [1], dataUse: { p: 'no' } })
		expect(await modelPolicy.listModelPoliciesWithDataUse()).toEqual({ policies: [1], dataUse: { p: 'no' } })
		record(null)
		expect(await modelPolicy.listModelPoliciesWithDataUse()).toEqual({ policies: [], dataUse: {} })
	})

	it('sends only the eval options that are set', async () => {
		const calls = record({ ok: 1 })
		await evals.runEval('d1', 'a1')
		await evals.runEval('d1', 'a1', { agentVersionId: 'v1', regressionThresholdPercent: 0, baseline: true })
		await evals.runEval('d1', 'a1', { baseline: 'yes', regressionThresholdPercent: null })
		expect(calls[0].slice(1)).toEqual([`${H}/evals/d1/run`, { agentId: 'a1' }])
		expect(calls[1][2]).toEqual({ agentId: 'a1', agentVersionId: 'v1', regressionThresholdPercent: 0, baseline: true })
		expect(calls[2][2]).toEqual({ agentId: 'a1' })
	})

	it('lists flow runs for one flow, at most fifty', async () => {
		const calls = record({ results: [1] })
		expect(await flowRuns.listFlowRuns('f1')).toEqual([1])
		expect(calls[0][2]).toEqual({ params: { flowId: 'f1', limit: 50 } })
		record({})
		expect(await flowRuns.listFlowRuns('f1')).toEqual([])
	})

	it('builds the download URLs without a request', () => {
		expect(literacy.overviewCsvUrl()).toBe(`${H}/literacy/overview.csv`)
		expect(toolOversight.toolInvocationsExportUrl('a1', 'csv')).toBe(`${H}/agents/a1/tool-invocations?format=csv`)
	})

	it('maps Talk rooms to token and name, falling back to the token', async () => {
		const calls = record({ ocs: { data: [{ token: 't1', displayName: 'Room' }, { token: 't2', name: 'n2' }, { token: 't3' }] } })
		expect(await talk.listRooms()).toEqual([{ token: 't1', name: 'Room' }, { token: 't2', name: 'n2' }, { token: 't3', name: 't3' }])
		expect(calls[0][1]).toMatch(/apps\/spreed\/api\/v4\/room$/)
		record(null)
		expect(await talk.listRooms()).toEqual([])
	})

	it('creates a group room and names it as asked when Talk does not say', async () => {
		const calls = record({ ocs: { data: { token: 'tk' } } })
		expect(await talk.createRoom('Team')).toEqual({ token: 'tk', name: 'Team' })
		expect(calls[0][2]).toEqual({ roomType: 2, roomName: 'Team' })
		record({ ocs: { data: { token: 'tk', displayName: 'Server name' } } })
		expect(await talk.createRoom('Team')).toEqual({ token: 'tk', name: 'Server name' })
	})

	it('stores the delivery target, clearing it with an empty value', async () => {
		const calls = record({})
		await talk.setDeliveryTarget('tok')
		await talk.setDeliveryTarget(null)
		expect(calls.map((c) => c[2])).toEqual([{ value: 'tok' }, { value: '' }])
	})

	it('reads an agent\'s grants through the flattener', async () => {
		const calls = record({ tools: { openregister: { objects: { read: ['openregister.objects.read'] } } } })
		expect(await toolTaxonomy.getAgentGrants('a1')).toEqual(['openregister.objects.read'])
		expect(calls[0][1]).toBe(`${H}/agents/a1`)
	})
})

describe('flattenGrants', () => {
	it('keeps the non-empty strings of a flat list', () => {
		expect(toolTaxonomy.flattenGrants(['a', '', 3, 'b'])).toEqual(['a', 'b'])
	})

	it('reads nothing from a non-object', () => {
		expect(toolTaxonomy.flattenGrants(null)).toEqual([])
		expect(toolTaxonomy.flattenGrants('a')).toEqual([])
	})

	it('flattens the app / subject / action tree into unique grant strings with their arguments', () => {
		const tools = {
			app: {
				objects: {
					read: ['app.objects.read', { id: 'app.objects.read' }],
					write: { id: 'app.objects.write', args: { register: 'r1', schema: ['s1', 's2'] } },
					delete: { id: 'app.objects.delete', args: {} },
					broken: [{ id: '' }, { args: { a: 1 } }, null],
				},
				odd: 'not-an-object',
			},
			other: null,
		}
		expect(toolTaxonomy.flattenGrants(tools)).toEqual([
			'app.objects.read',
			'app.objects.write?register=r1&schema=in:s1,s2',
			'app.objects.delete',
		])
	})
})

describe('speech', () => {
	it('reports speech available only on an explicit true, and unavailable on a failure', async () => {
		expect(await speech.speechCapabilities({ get: async () => ({ data: { available: true } }) })).toEqual({ available: true })
		expect(await speech.speechCapabilities({ get: async () => ({ data: { available: 1 } }) })).toEqual({ available: false })
		expect(await speech.speechCapabilities({ get: async () => { throw new Error('down') } })).toEqual({ available: false })
	})

	it('transcribes a recording with the language only when given', async () => {
		const posts = []
		const http = { post: async (url, form) => { posts.push([url, form]); return { data: { text: 'hello' } } } }
		expect(await speech.transcribe(new Blob(['a']), 'nl', http)).toBe('hello')
		expect(await speech.transcribe(new Blob(['a']), '', { post: async () => ({ data: { text: 3 } }) })).toBe('')
		expect(posts[0][0]).toBe(`${H}/speech/transcriptions`)
		expect(posts[0][1].get('language')).toBe('nl')
		expect(posts[0][1].get('audio')).toBeTruthy()
	})

	it('asks for the synthesised speech as a blob', async () => {
		const calls = record('BLOB')
		await speech.synthesise('Hi')
		expect(axios.post).toHaveBeenCalledWith(`${H}/speech/speech`, { text: 'Hi' }, { responseType: 'blob' })
		expect(calls).toHaveLength(1)
	})
})

describe('connection registry', () => {
	it('opens the integriq connections page for this app', () => {
		const opened = []
		const handlers = createConnectionHandlers({ generateUrl: (p) => '/x' + p, assign: (u) => opened.push(u) })
		handlers.openIntegriqConnections()
		expect(opened).toEqual(['/x' + INTEGRIQ_CONNECTIONS_PATH])
	})
})
