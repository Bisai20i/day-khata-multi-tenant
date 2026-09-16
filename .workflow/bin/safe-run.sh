#!/usr/bin/env bash
# Universal Safe Command Runner for Linux / macOS / WSL

if [ -z "$1" ]; then
  echo "Usage: ./safe-run.sh \"<command>\""
  exit 1
fi

CMD="$1"
LOG=".workflow_cmd.log"

eval "$CMD" > "$LOG" 2>&1
EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
  echo -e "\033[0;32m[SUCCESS] Command completed with exit code 0.\033[0m"
  tail -n 3 "$LOG"
  exit 0
else
  echo -e "\033[0;31m[FAILED] Command failed with exit code $EXIT_CODE.\033[0m"
  echo -e "\033[0;33m--- Tail of Log ($LOG) ---\033[0m"
  tail -n 35 "$LOG"
  echo -e "\033[0;33m---------------------------\033[0m"
  exit $EXIT_CODE
fi
