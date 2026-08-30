# hermiq-prefer-tool-hints

Makes `ToolGrantResolver`'s write/destructive classification prefer OpenRegister's ADR-063 descriptor
hints (`scope`/`destructiveHint`/`readOnlyHint`) now that `McpProviderBridge` forwards them, and closes
a fail-OPEN hole: a hint-less, non-3-segment tool id (a curated/hand-written 2-segment id) is now
classified write/destructive by default instead of silently passing as read.
