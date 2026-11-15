#!/usr/bin/env bash
# Universal Safe Command Runner for Linux / macOS / WSL
# Truncates output to prevent token window overflow in AI agent transcripts.

if [ -z "$1" ]; then
  echo "Usage: ./safe-run.sh \"<command>\" [tail_lines] [log_file]"
  exit 1
fi

CMD="$1"
TAIL_LINES="${2:-35}"
LOG="${3:-.workflow_cmd.log}"

START_TIME=$(date +%s%N 2>/dev/null || date +%s)

eval "$CMD" > "$LOG" 2>&1
EXIT_CODE=$?

END_TIME=$(date +%s%N 2>/dev/null || date +%s)

if [ $EXIT_CODE -eq 0 ]; then
  echo -e "\033[0;32m[SUCCESS] Command completed with exit code 0.\033[0m"
  tail -n 3 "$LOG"
  exit 0
else
  echo -e "\033[0;31m[FAILED] Command failed with exit code $EXIT_CODE.\033[0m"
  echo -e "\033[0;33m--- Tail of Log ($LOG, last $TAIL_LINES lines) ---\033[0m"
  tail -n "$TAIL_LINES" "$LOG"
  echo -e "\033[0;33m--------------------------------------------------\033[0m"
  exit $EXIT_CODE
fi
