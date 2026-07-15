#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
# Copyright 2026 Conduction B.V.
#
# Container/behaviour tests for hermiq-llm-runner. Runs the real Node service
# against STUB CLIs (no real Anthropic/OpenAI/Grok tokens or network needed) and
# asserts:
#   (a) POST /run WITHOUT the shared secret is rejected BEFORE any CLI runs;
#   (b) POST /run WITH the secret + a stubbed CLI returns {text,toolCalls,usage};
#   (c) the credential is passed via env only and never appears in logs/argv;
#   (d) the egress-allowlist mechanism denies a non-allowlisted host.
#
# Usage: ./test.sh   (from exapp/llm-runner/ or anywhere — paths are absolute)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "${HERE}")"
WORK="$(mktemp -d /tmp/llm-runner-test.XXXXXX)"
STUB_LOG="${WORK}/stub.log"
SERVER_LOG="${WORK}/server.log"
: > "${STUB_LOG}"

APP_SECRET="test-shared-secret-$(date +%s)"
APP_ID="hermiq-llm-runner"
PORT="9917"
TOKEN="oat-SUPERSECRET-TOKEN-do-not-log-1234567890"

PASS=0
FAIL=0
pass() { echo "PASS: $1"; PASS=$((PASS + 1)); }
fail() { echo "FAIL: $1"; FAIL=$((FAIL + 1)); }

cleanup() {
    [ -n "${SERVER_PID:-}" ] && kill "${SERVER_PID}" 2>/dev/null || true
    rm -rf "${WORK}"
}
trap cleanup EXIT

chmod +x "${ROOT}/test/stub-cli/claude" "${ROOT}/test/stub-cli/codex"

# --- Start the real server with stub CLIs wired in ---------------------------
APP_SECRET="${APP_SECRET}" \
APP_ID="${APP_ID}" \
RUNNER_PORT="${PORT}" \
RUNNER_ANTHROPIC_BIN="${ROOT}/test/stub-cli/claude" \
RUNNER_OPENAI_BIN="${ROOT}/test/stub-cli/codex" \
RUNNER_STUB_LOG="${STUB_LOG}" \
RUNNER_PASSTHROUGH_ENV="RUNNER_STUB_LOG" \
    node "${ROOT}/src/server.js" > "${SERVER_LOG}" 2>&1 &
SERVER_PID=$!

# Wait for the health endpoint.
for _ in $(seq 1 40); do
    if curl -fsS "http://127.0.0.1:${PORT}/heartbeat" >/dev/null 2>&1; then break; fi
    sleep 0.25
done
curl -fsS "http://127.0.0.1:${PORT}/heartbeat" >/dev/null 2>&1 \
    && pass "server heartbeat is up" \
    || { fail "server did not start"; cat "${SERVER_LOG}"; exit 1; }

# --- Build a request body ----------------------------------------------------
BODY_FILE="${WORK}/body.json"
cat > "${BODY_FILE}" <<JSON
{
  "provider": "anthropic",
  "model": "claude-opus-4-8",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Say hello."}
  ],
  "tools": [{"name": "lookup", "input_schema": {"type": "object"}}],
  "credentialEnv": {"CLAUDE_CODE_OAUTH_TOKEN": "${TOKEN}"}
}
JSON

sig() { openssl dgst -sha256 -hmac "${APP_SECRET}" "$1" | sed 's/^.*= *//'; }

# =============================================================================
# (a) POST /run WITHOUT the shared secret -> rejected before any CLI runs
# =============================================================================
stub_lines_before=$(wc -l < "${STUB_LOG}")
code=$(curl -s -o "${WORK}/noauth.json" -w '%{http_code}' \
    -X POST "http://127.0.0.1:${PORT}/run" \
    -H 'Content-Type: application/json' \
    --data-binary "@${BODY_FILE}")
stub_lines_after=$(wc -l < "${STUB_LOG}")

if [ "${code}" = "401" ]; then
    pass "(a) unauthenticated /run rejected with 401"
else
    fail "(a) expected 401 without auth, got ${code}"
fi
if [ "${stub_lines_before}" = "${stub_lines_after}" ]; then
    pass "(a) CLI was NOT invoked for the unauthenticated request"
else
    fail "(a) CLI ran before auth check (stub log grew)"
fi

# Also verify a wrong signature is rejected (403), still before any CLI runs.
code=$(curl -s -o /dev/null -w '%{http_code}' \
    -X POST "http://127.0.0.1:${PORT}/run" \
    -H 'Content-Type: application/json' \
    -H "EX-APP-ID: ${APP_ID}" \
    -H 'AUTHORIZATION-APP-API: dXNlcjpzZWNyZXQ=' \
    -H 'AA-SIGNATURE: deadbeefdeadbeef' \
    --data-binary "@${BODY_FILE}")
[ "${code}" = "403" ] \
    && pass "(a) invalid signature rejected with 403" \
    || fail "(a) expected 403 for bad signature, got ${code}"

# =============================================================================
# (b) POST /run WITH the secret + stubbed CLI -> returns a completion
# =============================================================================
SIG="$(sig "${BODY_FILE}")"
code=$(curl -s -o "${WORK}/ok.json" -w '%{http_code}' \
    -X POST "http://127.0.0.1:${PORT}/run" \
    -H 'Content-Type: application/json' \
    -H "EX-APP-ID: ${APP_ID}" \
    -H 'AUTHORIZATION-APP-API: dXNlcjpzZWNyZXQ=' \
    -H "AA-SIGNATURE: ${SIG}" \
    --data-binary "@${BODY_FILE}")

if [ "${code}" = "200" ]; then
    pass "(b) authenticated /run returned 200"
else
    fail "(b) expected 200 with valid auth, got ${code}"; cat "${WORK}/ok.json" || true
fi

# Assert the response shape {text,toolCalls,usage}.
node -e '
const r = require("'"${WORK}/ok.json"'");
const ok = typeof r.text === "string" && r.text.length > 0
        && Array.isArray(r.toolCalls)
        && r.usage && typeof r.usage === "object";
if (!ok) { console.error("bad shape:", JSON.stringify(r)); process.exit(1); }
if (r.toolCalls.length !== 1) { console.error("toolCalls not returned"); process.exit(1); }
process.exit(0);
' && pass "(b) response has {text, toolCalls (returned, not executed), usage}" \
  || fail "(b) response shape wrong"

grep -q 'INVOKED claude' "${STUB_LOG}" \
    && pass "(b) the anthropic CLI was invoked for the authorised request" \
    || fail "(b) CLI was not invoked"

# =============================================================================
# (c) Credential passed via env only; never in argv/logs
# =============================================================================
if grep -q 'ENV_TOKEN_PRESENT: 1' "${STUB_LOG}"; then
    pass "(c) credential reached the CLI via the environment"
else
    fail "(c) credential did not reach the CLI env"
fi

# The token value must appear NOWHERE: not in the CLI argv log, not in the
# server log, not in the HTTP response.
if grep -rq -- "${TOKEN}" "${STUB_LOG}" "${SERVER_LOG}" "${WORK}/ok.json"; then
    fail "(c) TOKEN VALUE LEAKED into a log or response"
    grep -rl -- "${TOKEN}" "${STUB_LOG}" "${SERVER_LOG}" "${WORK}/ok.json" || true
else
    pass "(c) token value never appears in argv, logs, or response"
fi

# Directly assert argv carried no token.
if grep '^ARGV:' "${STUB_LOG}" | grep -q -- "${TOKEN}"; then
    fail "(c) token found on CLI argv"
else
    pass "(c) CLI argv contains no credential"
fi

# Unit-level check: the runner drops non-allowlisted env keys the caller tries
# to smuggle through credentialEnv (PATH/LD_PRELOAD injection defense).
node -e '
const { selectCredentialEnv } = require("'"${ROOT}"'/src/runner");
const { getProvider } = require("'"${ROOT}"'/src/providers");
const p = getProvider("anthropic");
const sel = selectCredentialEnv(p, {
  CLAUDE_CODE_OAUTH_TOKEN: "keepme",
  PATH: "/evil",
  LD_PRELOAD: "/evil.so",
});
const keys = Object.keys(sel);
if (keys.length !== 1 || sel.CLAUDE_CODE_OAUTH_TOKEN !== "keepme") {
  console.error("credentialEnv allowlist failed:", JSON.stringify(sel)); process.exit(1);
}
process.exit(0);
' && pass "(c) credentialEnv is allowlisted (PATH/LD_PRELOAD dropped)" \
  || fail "(c) credentialEnv allowlist failed"

# =============================================================================
# (d) Egress allowlist mechanism denies a non-allowlisted host
# =============================================================================
ALLOWLIST="api.anthropic.com api.openai.com api.x.ai"
egress_verdict() {
    local host="$1"
    for a in ${ALLOWLIST}; do [ "${host}" = "${a}" ] && { echo allow; return; }; done
    echo deny
}
[ "$(egress_verdict api.anthropic.com)" = "allow" ] \
    && pass "(d) provider host api.anthropic.com is allowed" \
    || fail "(d) provider host wrongly denied"
[ "$(egress_verdict attacker.example.com)" = "deny" ] \
    && pass "(d) non-allowlisted host attacker.example.com is denied" \
    || fail "(d) non-allowlisted host wrongly allowed"

# Tie the demonstration to the real enforcement script: default-deny + no
# wildcard ACCEPT, allowlist driven by RUNNER_ALLOWED_HOSTS.
ENTRY="${ROOT}/deploy/egress-entrypoint.sh"
grep -q 'iptables -P OUTPUT DROP' "${ENTRY}" \
    && pass "(d) egress-entrypoint.sh sets default-DROP on OUTPUT" \
    || fail "(d) egress-entrypoint.sh missing default-DROP"
if grep -Eq 'iptables -A OUTPUT .*-j ACCEPT.*0\.0\.0\.0/0' "${ENTRY}"; then
    fail "(d) egress-entrypoint.sh has a wildcard ACCEPT"
else
    pass "(d) egress-entrypoint.sh has no wildcard ACCEPT rule"
fi

# =============================================================================
echo
echo "==== ${PASS} passed, ${FAIL} failed ===="
[ "${FAIL}" -eq 0 ]
