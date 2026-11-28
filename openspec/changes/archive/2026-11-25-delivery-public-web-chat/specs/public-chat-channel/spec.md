# public-chat-channel Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- delivery-public-web-chat

## Purpose

An agent on a public website or behind a sibling app's public widget, without sign-in, answering only from declared public content. Row `hermiq:dl-embed-web`; also the entry point portaliq's `search-assistant-from-public-content` waits on.

## ADDED Requirements

### Requirement: An organisation admin declares a public chat channel (REQ-PUBCHAT-001)

The system MUST let an organisation admin create a public channel with an agent, allowed origins for a website, a source scope, a disclosure text and a daily question limit, disabled until switched on. It MUST refuse a channel whose agent has tool grants. Channels MUST only work while the `public-chat` AI feature is enabled.

#### Scenario: A communications advisor puts the assistant on the municipal website
- GIVEN an organisation admin with the `public-chat` AI feature enabled
- WHEN they create the channel "Website" with the agent "Public information", the origin `https://www.example.nl` and the scope "published publications", and switch it on
- THEN the page shows a script snippet and an iframe snippet for the website

#### Scenario: An agent with tools cannot be public
- GIVEN an agent with the tool grant `hermiq.sendMail`
- WHEN an admin picks it for a channel and saves
- THEN the save is refused with "A public assistant cannot use tools. Pick an agent without tools."

### Requirement: A visitor chats without signing in and gets answers only from public content (REQ-PUBCHAT-002)

The system MUST answer a message to a channel without a signed-in user, from objects OpenRegister serves anonymously within the channel's scope only, with no tools, files or memory, and with the sources as links. It MUST show the disclosure text before the first message. It MUST apply the organisation's guardrail filters and MUST NOT store the visitor's IP address.

#### Scenario: A resident asks about bulky waste on the website
- GIVEN a resident on `https://www.example.nl` who opens the chat window
- WHEN they ask "When is bulky waste collected in my area?"
- THEN the window shows the disclosure text, then an answer drawn from the published waste collection page with a link to it

#### Scenario: A non-public object is never a source
- GIVEN an object in the channel's register that is not readable anonymously
- WHEN a question matches it exactly
- THEN the answer does not use or cite it
- @e2e exclude retrieval scope, covered by PHPUnit and a live check

### Requirement: The public endpoint is limited and origin bound (REQ-PUBCHAT-003)

The system MUST rate limit the public endpoint per client and per channel per day, MUST answer cross-origin requests only for the channel's allowed origins, and MUST answer "This assistant is not available right now." when the channel, the feature, the agent or the organisation's kill switch stops it.

#### Scenario: Another website cannot use the channel
- GIVEN a channel allowed for `https://www.example.nl`
- WHEN a browser on `https://other.example.org` posts a message to it
- THEN the browser receives no CORS permission and the message is not answered
- @e2e exclude CORS contract, covered by PHPUnit and Newman

### Requirement: Sibling apps reach the same channel in-process without an identity (REQ-PUBCHAT-004)

The system MUST offer `PublicChatEntryPoint::ask()` to sibling apps for an app channel. The source scope an app passes MUST only narrow the channel's declared scope. No user identity MUST be taken from or passed to the call.

#### Scenario: A portal asks hermiq on behalf of an anonymous visitor
- GIVEN portaliq with an app channel for the portal "wonen"
- WHEN its "Ask a question" widget calls the entry point with a question and the portal's published pages as scope
- THEN hermiq answers from those pages only and returns a conversation token for the rest of the visit
- @e2e exclude in-process PHP contract, covered by PHPUnit
