# knowledge-bases Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- memory-knowledge-bases

## Purpose

An agent owner groups documents into a knowledge base and attaches it to agents, and the agents answer from it. Rows `hermiq:me-kb-upload` and `hermiq:me-external-kb`.

## ADDED Requirements

### Requirement: An owner can create a knowledge base and fill it from Files (REQ-KB-001)

Hermiq MUST let a person create a knowledge base with a name and a description, and add documents to it by uploading them into its folder in Files, by picking files from Files, or by adding a Files folder. Hermiq MUST NOT copy a document out of Files, and removing a source MUST NOT delete the file.

#### Scenario: A procurement officer builds a knowledge base
- GIVEN a procurement officer at Gemeente Tilburg on `/knowledge-bases`
- WHEN they create "Inkoopbeleid", upload `inkoopvoorwaarden-2026.pdf` and add the folder `Team Inkoop/Modelcontracten`
- THEN the detail page lists both sources, the PDF sits in `Hermiq/Knowledge bases/Inkoopbeleid` in their Files, and each document shows its indexing state
- e2e: `tests/e2e/spec-coverage/knowledge-bases.spec.ts`

### Requirement: An agent answers from its attached knowledge bases (REQ-KB-002)

Hermiq MUST search every knowledge base attached to an agent on each turn through the OpenRegister vector facade, scoped to that knowledge base's files and objects, and MUST list the documents it used as sources on the answer. When vector search is not available, hermiq MUST NOT search knowledge bases and MUST say so on the knowledge base page.

#### Scenario: The procurement agent cites the conditions
- GIVEN the agent "Inkoopassistent" with "Inkoopbeleid" attached and its documents indexed
- WHEN a colleague asks "Wat is de betalingstermijn in onze inkoopvoorwaarden?"
- THEN the answer names the term from `inkoopvoorwaarden-2026.pdf` and lists that file as a source
- @e2e exclude needs an OpenRegister vector backend with indexed documents; covered by PHPUnit with a fake facade and a live check

#### Scenario: No vector search, no pretending
- GIVEN an OpenRegister without the vector facade
- WHEN the owner opens the knowledge base page
- THEN it shows "Search is not available on this server. Ask your administrator to set up vector search in OpenRegister." and the agent's answers carry no knowledge base sources
- e2e: `tests/e2e/spec-coverage/knowledge-bases.spec.ts`

### Requirement: A person only gets answers from documents they may read (REQ-KB-003)

Hermiq MUST run knowledge base retrieval as the person in the session and MUST NOT return a passage from a file that person cannot read. The knowledge base page MUST warn the owner about each source that only the owner can read.

#### Scenario: A private folder answers nobody else
- GIVEN a knowledge base whose only source is a folder in the owner's Files that is not shared
- WHEN a colleague asks the attached agent a question the folder answers
- THEN the answer uses no passage from that folder, and the owner's detail page shows "Only you can read this folder. Other people who use the agent get no answers from it. Share it with them, or use a team folder."
- @e2e exclude needs two identities and an indexed private folder; covered by PHPUnit on the scope resolution

### Requirement: Outside sources come in through integriq (REQ-KB-004)

Hermiq MUST accept as a knowledge base source a Files folder or an OpenRegister register and schema that integriq fills from an outside system. Hermiq MUST NOT call an outside knowledge system itself.

#### Scenario: A SharePoint library through integriq
- GIVEN integriq syncing the SharePoint library "Beleidsdocumenten" into `Integraties/SharePoint/Beleidsdocumenten`
- WHEN the owner adds that folder to "Inkoopbeleid"
- THEN the source shows "Synced by integriq from SharePoint: Beleidsdocumenten", and hermiq makes no request to SharePoint
- @e2e exclude needs integriq connected to SharePoint; covered by a live check on the dev instance

### Requirement: The vector facade offers a file scope and an indexing state (REQ-KB-005)

The OpenRegister vector facade that hermiq depends on MUST accept a list of file ids that limits results to chunks of those files, and MUST report per file whether it is indexed, pending, failed or unsupported. Hermiq MUST resolve the facade lazily and MUST keep working without it.

#### Scenario: An older OpenRegister without the scope
- GIVEN an OpenRegister whose facade has no file scope
- WHEN an agent with a knowledge base answers
- THEN hermiq searches no knowledge base, logs that the scope is missing, and the turn still gets an answer
- @e2e exclude a dependency contract; covered by PHPUnit with the facade class absent and present
