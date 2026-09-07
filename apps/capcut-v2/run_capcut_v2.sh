#!/usr/bin/env bash
# Independent runner for Product V2: 2toolne AutoEdit for CapCut
set -e

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
ROOT_DIR="$( cd "$DIR/../.." >/dev/null 2>&1 && pwd )"

cd "$ROOT_DIR"

# Prioritize virtual environment if present
if [ -f ".venv/bin/python3" ]; then
    PYTHON_CMD=".venv/bin/python3"
elif command -v python3 &>/dev/null; then
    PYTHON_CMD="python3"
else
    echo "❌ Python 3 not found!"
    exit 1
fi

export PYTHONPATH="apps/capcut-v2:.:$PYTHONPATH"
export CAPCUT_V2_PORT="8088"

echo "=========================================================="
echo "   2TOOLNE AUTOEDIT FOR CAPCUT (V2 POC)"
echo "   Port: http://127.0.0.1:8088"
echo "=========================================================="

(sleep 2 && open "http://127.0.0.1:8088") &
"$PYTHON_CMD" apps/capcut-v2/capcut_app.py


