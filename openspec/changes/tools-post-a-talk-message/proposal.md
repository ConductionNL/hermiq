# A granted tool that posts a Talk message

## Why

Ruben decided on 13 Nov (decision 406) that the drawn board is canon. HmMcpTools and
HmAgentBewerken draw a tool "Talk-bericht plaatsen" (`talk.message.send`, medium risk, also
reachable over MCP) that an agent is granted like any other tool. Today an agent reaches Talk
only through delivery (`TalkBridge::postToRoom` for `deliver=talk` schedules and Talk-bound agent
replies). No tool in `lib/Mcp` lets an agent decide during a turn to post a message.

### Matrix rows (hermiq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `tl-talk` | an agent posts a Talk message through a granted tool, in a conversation it is a member of |

## What changes

- A Talk message tool joins the Nextcloud-native tools: medium risk, default-denied until granted,
  offered over MCP to outside agents.
- The tool posts only in conversations where the agent's Talk identity is a member.

## Impact

- A new descriptor beside `lib/Mcp/NcTaskToolDescriptors.php`, reusing `TalkBridge::postToRoom`.
  The tool catalogue (HmMcpTools) and the agent's tool grants (HmAgentBewerken). Not built yet:
  the matrix row moves to `specified`.
