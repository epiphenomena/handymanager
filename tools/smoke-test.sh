#!/usr/bin/env bash
# tools/smoke-test.sh - End-to-end API smoke test against a throwaway database.
# Starts a dev server on a random port with HANDYMANAGER_DB pointed at a temp
# file, exercises every endpoint, and reports pass/fail.

set -u
cd "$(dirname "$0")/.."

PORT=$((20000 + RANDOM % 20000))
DB="$(mktemp -u /tmp/handymanager-test-XXXX.db)"
BASE="http://localhost:$PORT"
TOKEN=$(php -r '$c=json_decode(file_get_contents("config.json"),true); echo $c["VALID_TOKEN"];')
ADMIN=$(php -r '$c=json_decode(file_get_contents("config.json"),true); echo $c["ADMIN_TOKEN"];')

HANDYMANAGER_DB="$DB" php -S "localhost:$PORT" -t . >/dev/null 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null; rm -f "$DB"' EXIT
sleep 0.5

PASS=0
FAIL=0

# check <name> <expected-substring> <actual>
check() {
    if [[ "$3" == *"$2"* ]]; then
        PASS=$((PASS+1)); echo "ok   - $1"
    else
        FAIL=$((FAIL+1)); echo "FAIL - $1"; echo "       expected to contain: $2"; echo "       got: $3"
    fi
}

post() { curl -s -X POST "$BASE/$1" -H 'Content-Type: application/json' -d "$2"; }
form() { curl -s -X POST "$BASE/admin.php" "${@:1}"; }

# --- Auth: every endpoint rejects a bad token ---
check "bad token rejected (tech)"  '"success":false' "$(post get-tasks.php '{"token":"wrong","tech_name":"Tim"}')"
check "bad token rejected (admin)" 'Invalid admin token' "$(form --data-urlencode 'token=wrong' --data-urlencode 'action=view-jobs')"
check "missing token rejected"     '"success":false' "$(post get-open-jobs.php '{"tech_name":"Tim"}')"

# --- Admin: log a call to open a job ---
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=log-call' \
    --data-urlencode 'customer_name=Smith' --data-urlencode 'location=412 Oak Ave' \
    --data-urlencode 'phone=555-0101' --data-urlencode 'call_notes=Leaky faucet')
check "admin can log a call" 'Job opened: Smith - 412 Oak Ave' "$OUT"

# --- Tech: open jobs list includes the new job and Clock in/out ---
OUT=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
check "open jobs include new job"      'Smith - 412 Oak Ave' "$OUT"
check "open jobs include Clock in/out" 'Clock in' "$OUT"  # JSON escapes the slash
JOB_ID=$(echo "$OUT" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["jobs"] as $j) if(!$j["is_system"]) { echo $j["id"]; break; }')

# --- Tech: cannot create a task against an unknown job (location lockdown) ---
OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":9999,\"start_time\":\"2026-06-05 09:00\"}")
check "task rejected for unknown job" 'not open for new tasks' "$OUT"

# --- Tech: start and complete a task ---
OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":$JOB_ID,\"start_time\":\"2026-06-05 09:00\"}")
check "task created" '"success":true' "$OUT"
TASK_ID=$(echo "$OUT" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["task_id"];')

OUT=$(post get-tasks.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
check "in-progress task listed" 'Smith - 412 Oak Ave' "$OUT"

OUT=$(post complete-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Joe\",\"task_id\":$TASK_ID,\"end_time\":\"2026-06-05 11:00\",\"notes\":\"done\"}")
check "other tech cannot complete task" 'your own tasks' "$OUT"

OUT=$(post complete-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"task_id\":$TASK_ID,\"end_time\":\"2026-06-05 11:00\",\"notes\":\"Fixed faucet\"}")
check "task completed" '"success":true' "$OUT"

OUT=$(post update-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"task_id\":$TASK_ID,\"notes\":\"Fixed faucet + extra\"}")
check "task updated" '"success":true' "$OUT"

OUT=$(post get-latest-tasks.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
check "history shows task" 'Fixed faucet + extra' "$OUT"

# --- Admin: job lifecycle ---
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=active')
check "active list shows in-progress job" 'In Progress' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-job' --data-urlencode "id=$JOB_ID")
check "job detail shows timeline" 'Call logged' "$OUT"
check "job detail shows task" 'Fixed faucet' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=save-notes' --data-urlencode "id=$JOB_ID" --data-urlencode 'admin_notes=Waiting on parts')
check "admin notes saved" 'Waiting on parts' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$JOB_ID" --data-urlencode 'status=ready_for_billing')
check "job marked ready for billing" 'Ready for Billing' "$OUT"

# Closed job no longer appears in the tech autocomplete
OUT=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
if [[ "$OUT" == *'Smith - 412 Oak Ave'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - closed job hidden from tech autocomplete"
else
    PASS=$((PASS+1)); echo "ok   - closed job hidden from tech autocomplete"
fi

# Closed job rejects new tasks
OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":$JOB_ID,\"start_time\":\"2026-06-05 13:00\"}")
check "closed job rejects new tasks" 'not open for new tasks' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$JOB_ID" --data-urlencode 'status=billed')
check "job marked billed" '>Billed<' "$OUT"
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$JOB_ID" --data-urlencode 'status=paid')
check "job marked paid" '>Paid<' "$OUT"

# --- Reports ---
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-reports')
check "monthly report has data" '<td>1</td>' "$OUT"

MONTH=$(date +%Y-%m)
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=report-tech' --data-urlencode 'tech=Tim' --data-urlencode "month=$MONTH")
check "tech report shows task" 'Smith - 412 Oak Ave' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=export-tech-csv' --data-urlencode 'tech=Tim' --data-urlencode "month=$MONTH")
check "tech CSV export" 'Tech,Job,Start,End,Hours,Notes' "$OUT"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
