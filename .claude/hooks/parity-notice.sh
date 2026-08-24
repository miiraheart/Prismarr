#!/usr/bin/env bash
# PostToolUse(Edit|Write): state which sibling files carry the same role as the
# file just edited, and whether that file is a cross-cutting registration site.
# Silent for anything outside symfony/. Read only: greps and lists, nothing else.
set -uo pipefail

ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
command -v jq >/dev/null 2>&1 || exit 0

input=$(cat)
file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')
[ -n "$file" ] || exit 0

rel="${file#"$ROOT"/}"
case "$rel" in symfony/*) ;; *) exit 0 ;; esac

notes=""

# 1. Same-leaf siblings under the other module template directories.
case "$rel" in
  symfony/templates/*/*)
    module=$(printf '%s' "$rel" | cut -d/ -f3)
    leaf=${rel#symfony/templates/"$module"/}
    peers=$(cd "$ROOT/symfony/templates" 2>/dev/null &&
      find . -mindepth 2 -path "./*/$leaf" -not -path "./$module/*" 2>/dev/null |
      sed 's#^\./##' | sort | tr '\n' ' ')
    [ -n "$peers" ] && notes="Same-role sibling templates: ${peers}"
    ;;
  symfony/src/Controller/*Controller.php|symfony/src/Service/Media/*Client.php)
    kind=$(basename "$rel" | sed -E 's/^.*(Controller|Client)\.php$/\1/')
    peers=$(cd "$ROOT/symfony/src" 2>/dev/null &&
      ls -1 Controller/*"$kind".php Service/Media/*"$kind".php 2>/dev/null |
      grep -v "$(basename "$rel")" | sort | tr '\n' ' ')
    [ -n "$peers" ] && notes="Same-role sibling classes: ${peers}"
    ;;
esac

# 2. Cross-cutting registration sites: editing one implies the other twelve.
if grep -qxF "${rel#symfony/}" "$ROOT/symfony/tools/parity-sites.txt" 2>/dev/null; then
  sites=$(tr '\n' ' ' < "$ROOT/symfony/tools/parity-sites.txt")
  notes="${notes}${notes:+ }This file is one of the cross-cutting registration sites. A module registered here must also be registered in: ${sites}"
fi

[ -n "$notes" ] || exit 0

msg="Parity note for ${rel}. Integration modules in this repo are duplicated on purpose, so a change here does not reach the siblings by itself. ${notes} Run 'make parity' before finishing."
jq -nc --arg c "$msg" '{hookSpecificOutput:{hookEventName:"PostToolUse",additionalContext:$c}}'
