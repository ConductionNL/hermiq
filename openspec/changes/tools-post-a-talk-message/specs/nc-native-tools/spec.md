# nc-native-tools

## ADDED Requirements

### Requirement: An agent can post a message in a Talk conversation it is a member of (REQ-NCTALK-001)

The system SHALL offer a Nextcloud-native tool `talk.message.send` that posts a message in a Talk
conversation. The tool SHALL be classified medium risk and SHALL be default-denied until an agent
is granted it. It SHALL post only in a conversation where the agent's Talk identity is a member,
and SHALL refuse any other conversation without posting. The tool SHALL be offered to outside
agents over MCP under the same grants. When Talk is not installed, the tool SHALL answer with an
error the agent can read, and the turn SHALL carry on.

Rows: `tl-talk` (hermiq matrix).

#### Scenario: A granted agent posts in its own conversation
- **GIVEN** an agent granted `talk.message.send` and a member of the conversation "Servicedesk"
- **WHEN** the agent calls the tool with that conversation and a message
- **THEN** the message appears in "Servicedesk", posted by the agent's Talk identity

#### Scenario: A conversation the agent is not in is refused
- **GIVEN** an agent granted `talk.message.send` that is not a member of the conversation "Directie"
- **WHEN** the agent calls the tool for "Directie"
- **THEN** nothing is posted and the agent receives a refusal naming the reason

#### Scenario: An agent without the grant cannot post
- **GIVEN** an agent that was not granted `talk.message.send`
- **WHEN** the agent calls the tool
- **THEN** nothing is posted and the call goes through the approval gate like any other ungranted write

#### Scenario: Talk is not installed
- **WHEN** an agent calls the tool on an instance without Talk
- **THEN** the agent receives an error result and its turn continues
