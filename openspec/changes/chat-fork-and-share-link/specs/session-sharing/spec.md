# session-sharing Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-fork-and-share-link

## Purpose

A person shares a session read-only with named colleagues or groups, following Nextcloud's sharing rules, and can revoke it. Row `hermiq:ch-share-link`.

## ADDED Requirements

### Requirement: The owner shares a session with named users or groups (REQ-SSHARE-001)

Hermiq MUST let only the owner of a session add or remove read-only shares for Nextcloud users and groups. It MUST refuse a share that the instance's Nextcloud sharing settings do not allow for that owner. Hermiq MUST NOT create a link that grants access without logging in.

#### Scenario: A case handler shares an analysis with the inkoop team
- GIVEN a case handler who owns the session "Offerte dakrenovatie Stadskantoor" and is a member of group `team-inkoop`
- WHEN they choose "Share", find "team-inkoop" in the dialog and add it
- THEN the dialog lists the group with "Everyone in this group, now and later", and each member gets the notification "Anne de Vries shared the session 'Offerte dakrenovatie Stadskantoor' with you"
- e2e: `tests/e2e/spec-coverage/session-fork-and-share.spec.ts`

#### Scenario: Sharing outside the owner's groups is refused when the instance says so
- GIVEN an instance with "share only with group members" switched on
- WHEN the owner tries to share with a user in none of their groups
- THEN the share is refused with "You can only share with members of your groups." and `sharedWith` is unchanged
- @e2e exclude needs an instance sharing setting changed for the test; covered by PHPUnit with a fake share manager

### Requirement: A share recipient can read and do nothing else (REQ-SSHARE-002)

Hermiq MUST let a user named in `sharedWith`, or a member of a group named there, read the session and its turns. Hermiq MUST NOT let a recipient send a turn, rename, archive, restore, delete, fork or reshare the session.

#### Scenario: A colleague reads the shared analysis
- GIVEN a member of `team-inkoop` with the notification about "Offerte dakrenovatie Stadskantoor"
- WHEN they open it
- THEN `/chat/shared/<uuid>` shows every turn with its sources, the line "Shared by Anne de Vries on 12 September", and no message box
- e2e: `tests/e2e/spec-coverage/session-fork-and-share.spec.ts`

#### Scenario: A recipient cannot add a turn with a crafted request
- GIVEN a share recipient
- WHEN they post to `/api/chat/send` with the shared session's uuid
- THEN the request is refused and no turn is stored
- @e2e exclude a crafted request body; covered by PHPUnit and Newman

### Requirement: The owner can revoke a share at once (REQ-SSHARE-003)

Hermiq MUST remove a recipient's access as soon as the owner removes the share. The recipient's next read MUST get the same 404 as a session that does not exist.

#### Scenario: Anne takes the share back
- GIVEN a session shared with user `bram.jansen`
- WHEN the owner removes Bram in the share dialog
- THEN Bram's next visit to `/chat/shared/<uuid>` shows "This session is not available", and the session is gone from his "Shared with you" group
- e2e: `tests/e2e/spec-coverage/session-fork-and-share.spec.ts`
