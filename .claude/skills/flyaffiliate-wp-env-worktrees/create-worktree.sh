#!/usr/bin/env bash
#
# Create a FlyAffiliate worktree with its own wp-env: its own ports, its own
# database, and optionally a database cloned from a local site. For a change
# that also touches FlyAffiliate Pro, use Pro's flyaffiliate-pro-wp-env-worktrees
# skill instead: Pro drives that environment and mounts FlyAffiliate.
#
# Usage:
#   create-worktree.sh <branch> [base-branch] [port]
#
#   <branch>       made from <base-branch> (or origin/<branch>) when it does not exist
#   [base-branch]  default: develop
#   [port]         dev port; tests use port+1. Default: 8910
#
# Env overrides:
#   FLYAFFILIATE_WT_HOME   parent dir for worktrees (default: ~/flyaffiliate-wt)
#   FLYAFFILIATE_CLONE_DB_FROM / _HOST / _PORT / _NAME / _USER / _PASS / _PREFIX
#                          clone the dev DB on first start; written to the override as
#                          env.development.config (see clone-db.sh for the semantics)
#
# Requires jq. Run from anywhere inside the FlyAffiliate main checkout.
set -euo pipefail

SKILL_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

BRANCH="${1:?usage: create-worktree.sh <branch> [base-branch] [port]}"
BASE="${2:-develop}"
PORT="${3:-8910}"
TESTS_PORT=$(( PORT + 1 ))

command -v jq >/dev/null || { echo "jq required"; exit 1; }

MAIN="$( git rev-parse --show-toplevel )"
SLUG="${BRANCH//\//-}"
# The folder is named flyaffiliate: wp-env mounts "." under its folder name, so
# the plugin keeps its slug (and its active state in a cloned database).
DEST="${FLYAFFILIATE_WT_HOME:-$HOME/flyaffiliate-wt}/$SLUG/flyaffiliate"
mkdir -p "$( dirname "$DEST" )"

# Where a branch is checked out already, if anywhere (a branch can be in one worktree only).
checked_out_at() {
  git -C "$MAIN" worktree list --porcelain | awk -v ref="branch refs/heads/$1" '
    /^worktree / { path = substr( $0, 10 ) }
    $0 == ref    { print path }'
}

git -C "$MAIN" fetch --quiet origin "$BRANCH" 2>/dev/null || true
if git -C "$MAIN" show-ref --verify --quiet "refs/heads/$BRANCH"; then
  busy="$( checked_out_at "$BRANCH" )"
  if [[ -n "$busy" ]]; then
    echo "note: $BRANCH is checked out at $busy; this worktree gets its commit, detached."
    echo "      Commit there, or switch that checkout to another branch and run: git -C \"$DEST\" switch $BRANCH"
    git -C "$MAIN" worktree add --detach "$DEST" "$BRANCH"
  else
    git -C "$MAIN" worktree add "$DEST" "$BRANCH"
  fi
elif git -C "$MAIN" show-ref --verify --quiet "refs/remotes/origin/$BRANCH"; then
  git -C "$MAIN" worktree add -b "$BRANCH" "$DEST" "origin/$BRANCH"
else
  git -C "$MAIN" worktree add -b "$BRANCH" "$DEST" "$BASE"
fi

abs() { # an existing file or directory, as an absolute path
  if [[ -f "$1" ]]; then echo "$( cd "$( dirname "$1" )" && pwd )/$( basename "$1" )"; else ( cd "$1" && pwd ); fi
}

# Clone source, written as wp-env config constants (wp-env rejects unknown
# top-level keys). clone-db.sh reads them back; with none set it does nothing.
clone='{}'
for k in FROM HOST PORT NAME USER PASS PREFIX; do
  name="FLYAFFILIATE_CLONE_DB_$k"; v="${!name:-}"
  [[ -n "$v" ]] || continue
  if [[ "$k" == FROM ]]; then v="$( abs "$v" )"; fi
  if [[ "$k" == PORT ]]; then
    clone="$( jq --arg n "$name" --argjson v "$v" '. + { ($n): $v }' <<<"$clone" )"
  else
    clone="$( jq --arg n "$name" --arg v "$v" '. + { ($n): $v }' <<<"$clone" )"
  fi
done

# The committed .wp-env.json stays untouched (CI reads it) and already mounts
# WooCommerce and "."; the gitignored override adds only what differs per worktree.
jq -n \
  --argjson port "$PORT" \
  --argjson tests "$TESTS_PORT" \
  --argjson clone "$clone" \
  --arg hook "$SKILL_DIR/clone-db.sh" '
  {
    port: $port,
    env: (
      { tests: { port: $tests } }
      + ( if $clone == {} then {} else { development: { config: $clone } } end )
    ),
    lifecycleScripts: { afterStart: $hook }
  }' > "$DEST/.wp-env.override.json"

cat <<EOF

Worktree ready: $DEST  ($BRANCH)
  wp-env on port $PORT (tests $TESTS_PORT); override written to .wp-env.override.json

Next:
  cd "$DEST" && composer install && npm ci && npm run build
  cd "$DEST" && npx wp-env start       # http://localhost:$PORT  (admin / password)
  # If npm ci fails on a git dependency (@wedevs/plugin-ui), see "npm ci in a worktree" in SKILL.md.

Tear down when merged:
  (cd "$DEST" && npx wp-env destroy)   # drops this worktree's DB volumes
  git -C "$MAIN" worktree remove "$DEST"
EOF
