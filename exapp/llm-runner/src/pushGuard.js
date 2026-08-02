/**
 * pushGuard — the mechanical fences around a stage that may WRITE to the forge.
 *
 * THE PROBLEM THIS EXISTS FOR
 * --------------------------
 * A builder stage clones a repository, lets a model change it, and pushes the
 * result. The model reads code and issue text it did not author, so repository
 * content is HOSTILE INPUT: anything a prompt can talk the model out of is not a
 * control. "Only push to `feature/<issue>/*`" written in a system prompt is a
 * request, and a request is not a fence.
 *
 * So every rule here is a function that runs on the RUNNER's side of the
 * boundary, after the model has finished and before anything reaches the forge.
 * The model cannot reach these checks, cannot see their state, and cannot
 * decline them. That is the whole design: the security property must hold
 * irrespective of what the model was persuaded to attempt.
 *
 * WHY THE MODEL NEVER HOLDS THE CREDENTIAL
 * ----------------------------------------
 * The strongest fence available is not to hand over the key. `runStage()`
 * withholds `GIT_FORGE_TOKEN`/`GIT_ASKPASS` from the command child whenever a
 * `push` is declared, and performs the push itself once these assertions pass.
 * Without that, a shell-capable agent could simply run `git push` with the
 * credential and no runner-side rule could observe it — the checks would be
 * decoration around a hole. Every function below assumes that arrangement and
 * is worthless without it.
 *
 * WHAT IS DELIBERATELY *NOT* HERE
 * -------------------------------
 * Repository scope is the FORGE's job. The credential is a fine-grained token
 * carrying `contents:write` on ONE repository, so a push elsewhere fails at
 * github.com even if every line below were deleted. `assertSameRepository()` is
 * the second of two independent controls, not the only one — it turns a remote
 * 403 into a local refusal that names the reason, and it still refuses when the
 * credential is wider than it should be.
 *
 * Likewise protected branches: the forge rulesets (`pull_request` on
 * `main`/`development`/`beta`, bypass OrganizationAdmin only) refuse a direct
 * push regardless. `assertBranchAllowed()` is the near-side half of the same
 * property.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict';

/**
 * An error carrying a stable machine-readable code.
 *
 * The code matters more than the message here: a caller (and a test) needs to
 * distinguish "refused by the branch rule" from "refused by the diff rule"
 * from "the clone failed", and a substring match on prose is exactly the kind
 * of assertion that silently stops testing anything when the prose is reworded.
 */
class PushRefused extends Error {
    /**
     * @param {string} code    Stable refusal code.
     * @param {string} message Human-readable reason.
     */
    constructor(code, message) {
        super(message);
        this.name = 'PushRefused';
        this.code = code;
    }
}

/**
 * Paths that may NEVER be written by a builder stage, whatever the issue says.
 *
 * `.github/workflows` and `.forgejo/workflows` are code execution on the
 * forge's runners: a job definition is a shell script the forge runs with the
 * forge's own secrets. A credential that can edit one escapes every other
 * control in this change — which is why the credential must not carry
 * `workflows:write` AND why the diff is refused here even if it somehow does.
 * Two independent refusals, because one of them being wrong is the scenario
 * worth surviving.
 *
 * `.git/` is listed because a hook written into the clone would run on the
 * runner, not on the forge — a different escape with the same shape.
 *
 * @type {Array<{prefix: string, code: string, why: string}>}
 */
const FORBIDDEN_PREFIXES = [
    {
        prefix: '.github/workflows/',
        code: 'workflow_definition',
        why: 'a workflow definition is code the forge executes with the forge’s own secrets',
    },
    {
        prefix: '.forgejo/workflows/',
        code: 'workflow_definition',
        why: 'a workflow definition is code the forge executes with the forge’s own secrets',
    },
    {
        prefix: '.github/actions/',
        code: 'workflow_definition',
        why: 'a composite action is executed by every workflow that references it',
    },
    {
        prefix: '.git/',
        code: 'git_internals',
        why: 'a file under .git/ can install a hook that runs on the runner itself',
    },
];

/**
 * Dependency manifests and their lockfiles.
 *
 * Adding a dependency is adding somebody else's code to the build, and the
 * review that would catch a malicious package is a human one. The spec asks for
 * "dependency-manifest additions" to be refused; this refuses ANY change to
 * them, because distinguishing an addition from a bump from a removal by
 * parsing every ecosystem's format is a large surface for a small gain — and
 * the failure mode of getting the parse wrong is admitting the addition.
 *
 * A basename match, not a prefix: these files appear at every depth of a
 * monorepo, and a rule anchored at the root would miss a nested
 * `exapp/llm-runner/package.json` entirely.
 *
 * @type {Array<string>}
 */
const DEPENDENCY_MANIFESTS = [
    'package.json',
    'package-lock.json',
    'npm-shrinkwrap.json',
    'yarn.lock',
    'pnpm-lock.yaml',
    'composer.json',
    'composer.lock',
    'requirements.txt',
    'pyproject.toml',
    'poetry.lock',
    'Pipfile',
    'Pipfile.lock',
    'go.mod',
    'go.sum',
    'Gemfile',
    'Gemfile.lock',
    'Cargo.toml',
    'Cargo.lock',
];

/**
 * Branch names that are never a legitimate builder target.
 *
 * Redundant with {@link assertBranchAllowed}'s positive pattern — nothing in
 * this list can match `feature/<issue>/<slug>` anyway. It is here because a
 * future relaxation of the pattern (a `hydra/*` convention, say) must not
 * quietly re-admit `main`, and because a refusal that names "this is a
 * protected branch" is a better diagnostic than "this did not match a regex".
 *
 * @type {Array<string>}
 */
const PROTECTED_BRANCHES = ['main', 'master', 'development', 'develop', 'beta', 'release', 'HEAD'];

/**
 * Reduce a clone URL to a comparable repository identity.
 *
 * `https://x-access-token@github.com/Owner/Repo.git` and
 * `https://github.com/owner/repo` are the SAME repository, and a naive string
 * comparison of the two refuses a legitimate push — the class of control that
 * gets switched off. Userinfo is stripped (it is a credential position), the
 * host and path are lower-cased (git forges are case-insensitive on both, and
 * `Owner/Repo` vs `owner/repo` is a pure formatting difference), a trailing
 * `.git` and trailing slashes are dropped.
 *
 * Returns null for anything unparseable rather than guessing. A null propagates
 * to a REFUSAL at the call site, never to a permit.
 *
 * @param {string} url A clone URL.
 * @returns {string|null} `host/owner/repo`, or null when it cannot be read.
 */
function repoIdentity(url) {
    if (typeof url !== 'string' || url.trim() === '') {
        return null;
    }

    let parsed;
    try {
        parsed = new URL(url.trim());
    } catch (err) {
        // `git@github.com:owner/repo.git` is not a URL. It is also not a shape
        // this runner ever produces or accepts — the clone URL is built from an
        // https base — so it is refused rather than special-cased.
        return null;
    }

    const host = String(parsed.hostname || '').toLowerCase();
    let path = String(parsed.pathname || '').toLowerCase();

    path = path.replace(/\.git$/, '').replace(/^\/+/, '').replace(/\/+$/, '');

    if (host === '' || path === '') {
        return null;
    }

    return `${host}/${path}`;
}

/**
 * Refuse a push whose destination is not the repository the stage was scoped to.
 *
 * @param {string} pushUrl    Where the push would go.
 * @param {string} allowedUrl The single repository this stage may write to.
 * @returns {void}
 * @throws {PushRefused} When the two are not the same repository.
 */
function assertSameRepository(pushUrl, allowedUrl) {
    const target = repoIdentity(pushUrl);
    const allowed = repoIdentity(allowedUrl);

    if (allowed === null) {
        // Fail CLOSED. An unreadable allowlist entry must never widen to
        // "anything goes" — that is how an allowlist becomes a no-op.
        throw new PushRefused(
            'allowed_repo_unreadable',
            `the stage declares no readable target repository (got ${JSON.stringify(allowedUrl)})`
        );
    }

    if (target === null) {
        throw new PushRefused(
            'push_repo_unreadable',
            `the push target is not a readable repository URL (got ${JSON.stringify(pushUrl)})`
        );
    }

    if (target !== allowed) {
        throw new PushRefused(
            'repo_not_allowed',
            `push refused: this stage may write only to ${allowed}, not to ${target}`
        );
    }
}

/**
 * Refuse a branch that is not `feature/<issue>/<slug>` for THIS issue.
 *
 * The issue number is part of the pattern rather than checked separately: a
 * builder answering issue 42 that pushes to `feature/91/...` has escaped its
 * scope just as surely as one pushing to `main`, and the convention is already
 * parsed that way everywhere else in the pipeline
 * (`sed -nE 's|^feature/([0-9]+)/.*|\1|p'`).
 *
 * @param {string} branch The branch to push to (no `refs/heads/` prefix).
 * @param {string|number} issue The issue number the stage is answering.
 * @returns {void}
 * @throws {PushRefused} When the branch is outside the allowlist.
 */
function assertBranchAllowed(branch, issue) {
    const name = String(branch === undefined || branch === null ? '' : branch).trim();
    const issueNumber = String(issue === undefined || issue === null ? '' : issue).trim();

    if (name === '') {
        throw new PushRefused('branch_missing', 'push refused: no branch was named');
    }

    if (/^[0-9]+$/.test(issueNumber) === false) {
        // Without an issue there is no scope, and without a scope the pattern
        // below would admit `feature/<anything>/<anything>`. Fail closed.
        throw new PushRefused(
            'issue_missing',
            `push refused: the stage declares no issue number, so no branch can be in scope (got ${JSON.stringify(issue)})`
        );
    }

    // Named explicitly so the refusal says WHY, even though the pattern below
    // would reject these anyway. See PROTECTED_BRANCHES.
    if (PROTECTED_BRANCHES.includes(name) === true) {
        throw new PushRefused(
            'protected_branch',
            `push refused: "${name}" is a protected branch — nothing reaches it without human approval`
        );
    }

    // `refs/heads/...`, `refs/tags/...` and any other fully-qualified ref are
    // refused here rather than normalised. A caller that hands a ref where a
    // branch was asked for has a bug, and normalising it would let
    // `refs/heads/main` slip past the protected-branch list above by not being
    // spelled `main`.
    if (name.startsWith('refs/') === true) {
        throw new PushRefused(
            'qualified_ref',
            `push refused: expected a branch name, got the fully-qualified ref "${name}"`
        );
    }

    // Anchored at both ends. An unanchored test would match
    // `evil/feature/42/x` and `feature/42/x:refs/heads/main`.
    const pattern = new RegExp(`^feature/${issueNumber}/[A-Za-z0-9][A-Za-z0-9._-]*$`);
    if (pattern.test(name) === false) {
        throw new PushRefused(
            'branch_not_allowed',
            `push refused: "${name}" is outside the allowlist — `
            + `this stage may push only to feature/${issueNumber}/<slug>`
        );
    }
}

/**
 * Whether a path is inside one of the declared scope prefixes.
 *
 * Prefix matching on directory boundaries, not `startsWith` on the raw string:
 * a scope of `lib/Service` must not admit `lib/ServiceAccountKeys.php`. An
 * exact file path in the scope list matches that file and nothing else.
 *
 * @param {string} file  A repository-relative path.
 * @param {Array<string>} scope The declared scope prefixes.
 * @returns {boolean} True when the file is in scope.
 */
function isInScope(file, scope) {
    for (const raw of scope) {
        const entry = String(raw).replace(/^\.\//, '').replace(/\/+$/, '');
        if (entry === '') {
            continue;
        }
        if (file === entry || file.startsWith(`${entry}/`) === true) {
            return true;
        }
    }

    return false;
}

/**
 * Refuse a change set that reaches somewhere it must not.
 *
 * Runs over the paths the stage actually changed, so it sees what the model
 * DID rather than what it said it would do. Ordering is deliberate: the
 * absolute prohibitions are checked before the scope rule, so a workflow edit
 * is reported as a workflow edit even when the issue's declared scope happens
 * to include `.github`.
 *
 * An EMPTY scope list means "no scope was declared", which disables only the
 * scope rule — the forbidden prefixes and dependency manifests still apply. A
 * missing scope must not be able to switch off the parts of the gate that do
 * not depend on it.
 *
 * @param {Array<string>} files A list of repository-relative changed paths.
 * @param {Array<string>} [scope] Declared scope prefixes; empty ⇒ scope unchecked.
 * @returns {void}
 * @throws {PushRefused} On the first path that is refused.
 */
function assertDiffAllowed(files, scope = []) {
    if (Array.isArray(files) === false) {
        throw new PushRefused('diff_unreadable', 'push refused: the change set could not be read');
    }

    const scopeList = Array.isArray(scope) ? scope : [];

    for (const raw of files) {
        // Normalise the leading `./` git never emits but a caller might, and
        // reject a path that climbs out of the tree before any rule sees it.
        const file = String(raw).replace(/^\.\//, '');
        if (file === '') {
            continue;
        }

        if (file.startsWith('/') === true || file.split('/').includes('..') === true) {
            throw new PushRefused(
                'path_escape',
                `push refused: "${file}" is not a repository-relative path`
            );
        }

        for (const rule of FORBIDDEN_PREFIXES) {
            if (file.startsWith(rule.prefix) === true) {
                throw new PushRefused(
                    rule.code,
                    `push refused: "${file}" is out of bounds — ${rule.why}`
                );
            }
        }

        const base = file.split('/').pop();
        if (DEPENDENCY_MANIFESTS.includes(base) === true) {
            throw new PushRefused(
                'dependency_manifest',
                `push refused: "${file}" is a dependency manifest — `
                + 'adding or moving a dependency needs human review'
            );
        }

        if (scopeList.length > 0 && isInScope(file, scopeList) === false) {
            throw new PushRefused(
                'out_of_scope',
                `push refused: "${file}" is outside the scope this issue declared `
                + `(${scopeList.join(', ')})`
            );
        }
    }
}

/**
 * Every fence, in the order a push must clear them.
 *
 * A single entry point so a caller cannot accidentally run two of the three.
 * The repository check comes first because it is the cheapest and the most
 * total: if the destination is wrong, nothing about the diff matters.
 *
 * @param {object} args Push description.
 * @param {string} args.pushUrl    Where the push would go.
 * @param {string} args.allowedUrl The one repository this stage may write to.
 * @param {string} args.branch     The branch to push to.
 * @param {string|number} args.issue The issue number being answered.
 * @param {Array<string>} args.files Changed repository-relative paths.
 * @param {Array<string>} [args.scope] Declared scope prefixes.
 * @returns {void}
 * @throws {PushRefused} On the first fence that refuses.
 */
function assertPushAllowed({ pushUrl, allowedUrl, branch, issue, files, scope }) {
    assertSameRepository(pushUrl, allowedUrl);
    assertBranchAllowed(branch, issue);
    assertDiffAllowed(files, scope);
}

module.exports = {
    PushRefused,
    repoIdentity,
    assertSameRepository,
    assertBranchAllowed,
    assertDiffAllowed,
    assertPushAllowed,
    isInScope,
    FORBIDDEN_PREFIXES,
    DEPENDENCY_MANIFESTS,
    PROTECTED_BRANCHES,
};
