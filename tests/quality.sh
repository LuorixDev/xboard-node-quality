#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
curl(){ printf '{}'; }
export -f curl
out=$(bash plugin/NodeQuality/resources/assets/collect-quality.sh 1.1.1.1 4 2>/dev/null)
printf '%s' "$out" | jq -e '.Score | to_entries | all(.value == "null" or .value == null)' >/dev/null
printf '%s' "$out" | jq -e 'has("Mail") | not' >/dev/null
echo 'PASS empty providers produce unknown risk, no mail module'
