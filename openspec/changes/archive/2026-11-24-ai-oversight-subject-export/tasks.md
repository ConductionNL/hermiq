## 1. Build

- [x] 1.1 `subjectExport` + route, oversight role and subject access before the read, CSV with formula cells neutralised (AiOversightControllerTest, 4 new cases red on development)
- [x] 1.2 dossiq: the AI oversight card's Download button and footnote (nextcloud-vue object-list export over an endpoint)
  - 2026-11-24: built in dossiq, not here: `src/menu-layout.simple.json` (case-ai-oversight card "What the assistant did on this case") carries `export: {label: "Download", url: "/apps/hermiq/api/ai-oversight/subject/export", params: {originApp: "dossiq", subjectId: "@objectId"}, group: "ai-oversight"}` and the footnote "Only those with the AI oversight role may download."; `CnObjectListWidget` downloads from an endpoint (`endpointExportUrl`) and shows the action only to the group since @conduction/nextcloud-vue 2.204.1, the version dossiq's lock pins.
