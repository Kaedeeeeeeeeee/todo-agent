#!/bin/sh
command="$1"
shift
if [ "$TASK_AGENT_ISOLATED" = "1" ]; then
    exec "${TASK_AGENT_CODEX_BIN:-codex}" "$command" --ignore-user-config --ephemeral "$@"
fi
exec "${TASK_AGENT_CODEX_BIN:-codex}" "$command" --ephemeral "$@"
