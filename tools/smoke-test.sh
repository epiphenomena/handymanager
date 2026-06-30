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

# --- Standalone log-call page ---
OUT=$(post log-call.php '{"token":"wrong","customer_name":"Patel","location":"7 Birch Ct"}')
check "log-call rejects bad token" 'Invalid admin token' "$OUT"

OUT=$(post log-call.php "{\"token\":\"$ADMIN\",\"customer_name\":\"Patel\",\"location\":\"7 Birch Ct\",\"phone\":\"555-0103\",\"call_notes\":\"Drywall repair\"}")
check "log-call opens job" 'Patel - 7 Birch Ct' "$OUT"
JOB2_ID=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Joe\"}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["jobs"] as $j) if(strpos($j["name"],"Patel")!==false) { echo $j["id"]; break; }')

# --- A running task blocks ready-for-billing; admin adding an end time unblocks ---
OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Joe\",\"job_id\":$JOB2_ID,\"start_time\":\"2026-06-06 08:00\"}")
TASK2_ID=$(echo "$OUT" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["task_id"];')

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$JOB2_ID" --data-urlencode 'status=ready_for_billing')
check "running task blocks ready-for-billing" 'Tasks still in progress: Joe' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=save-task' --data-urlencode "task_id=$TASK2_ID" \
    --data-urlencode 'tech_name=Joe' --data-urlencode 'start_date=2026-06-06' --data-urlencode 'start_time=08:00' \
    --data-urlencode 'end_date=2026-06-06' --data-urlencode 'end_time=10:30' --data-urlencode 'notes=Patched drywall')
check "admin can add end time to task" 'Task updated' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$JOB2_ID" --data-urlencode 'status=ready_for_billing')
check "ready-for-billing works after end time set" 'Ready for Billing' "$OUT"

# --- Call-log autocomplete suggestions (with prefill data) ---
OUT=$(post log-call.php "{\"token\":\"$ADMIN\",\"action\":\"suggestions\"}")
check "suggestions include customer" 'Patel' "$OUT"
check "suggestions include location" '7 Birch Ct' "$OUT"
check "suggestions carry phone for prefill" '555-0103' "$OUT"

# Admin Log Call form wires the custom autocomplete + embeds suggestion data
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=log-call-form')
check "admin log-call form attaches autocomplete" 'HMAutocomplete.attach' "$OUT"
check "admin log-call form embeds customers" '"name":"Patel"' "$OUT"
check "admin log-call form embeds locations" '7 Birch Ct' "$OUT"

# --- Admin can add a task directly (work reported outside the tech app) ---
OUT=$(post log-call.php "{\"token\":\"$ADMIN\",\"customer_name\":\"Lee\",\"location\":\"9 Pine Rd\"}")
JOB3_ID=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["jobs"] as $j) if(strpos($j["name"],"Lee")!==false) { echo $j["id"]; break; }')

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=add-task' --data-urlencode "id=$JOB3_ID" \
    --data-urlencode 'tech_name=Tim' --data-urlencode 'start_date=2026-06-06' --data-urlencode 'start_time=09:00' \
    --data-urlencode 'end_date=2026-06-06' --data-urlencode 'end_time=12:00' --data-urlencode 'notes=Phoned in: replaced railing')
check "admin can add a task" 'Task added' "$OUT"
check "added task shows in timeline" 'Phoned in: replaced railing' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=add-task' --data-urlencode "id=$JOB3_ID" \
    --data-urlencode 'tech_name=' --data-urlencode 'start_date=2026-06-06' --data-urlencode 'start_time=09:00')
check "add-task requires tech name" 'Tech name and a valid start' "$OUT"

# Completed (ready-for-billing) jobs reject admin add-task too
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$JOB3_ID" --data-urlencode 'status=ready_for_billing')
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=add-task' --data-urlencode "id=$JOB3_ID" \
    --data-urlencode 'tech_name=Tim' --data-urlencode 'start_date=2026-06-06' --data-urlencode 'start_time=14:00')
check "closed job rejects admin add-task" 'not open for new tasks' "$OUT"

# --- On hold hides the job from techs without closing it ---
OUT=$(post log-call.php "{\"token\":\"$ADMIN\",\"customer_name\":\"Nguyen\",\"location\":\"5 Cedar Ln\"}")
JOB4_ID=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["jobs"] as $j) if(strpos($j["name"],"Nguyen")!==false) { echo $j["id"]; break; }')

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$JOB4_ID" --data-urlencode 'status=on_hold')
check "job can be put on hold" 'On Hold' "$OUT"

OUT=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
if [[ "$OUT" == *'Nguyen'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - on-hold job hidden from tech autocomplete"
else
    PASS=$((PASS+1)); echo "ok   - on-hold job hidden from tech autocomplete"
fi

OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":$JOB4_ID,\"start_time\":\"2026-06-06 09:00\"}")
check "on-hold job rejects new tasks" 'not open for new tasks' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=active')
check "on-hold job shows in active group" 'Nguyen' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$JOB4_ID" --data-urlencode 'status=open')
OUT=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
check "resumed job visible to techs again" 'Nguyen' "$OUT"

# --- Closed status, tab filters, and list-level status changes ---
OUT=$(post log-call.php "{\"token\":\"$ADMIN\",\"customer_name\":\"Abbott\",\"location\":\"1 First St\"}")
ABBOTT_ID=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["jobs"] as $j) if(strpos($j["name"],"Abbott")!==false){echo $j["id"];break;}')

# Close it straight from the active list
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$ABBOTT_ID" --data-urlencode 'status=closed' --data-urlencode 'return=list' --data-urlencode 'group=active')
check "close from list re-renders list" 'Active Jobs' "$OUT"

OUT=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
if [[ "$OUT" == *'Abbott'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - closed job hidden from tech picker"
else
    PASS=$((PASS+1)); echo "ok   - closed job hidden from tech picker"
fi

OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":$ABBOTT_ID,\"start_time\":\"2026-06-07 09:00\"}")
check "closed job rejects new tasks" 'not open for new tasks' "$OUT"

# Paid tab, filtered to Closed, shows it
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=paid' --data-urlencode 'status_filter=closed')
check "closed filter shows closed job" 'Abbott' "$OUT"
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=paid' --data-urlencode 'status_filter=paid')
if [[ "$OUT" == *'Abbott'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - paid filter excludes closed job"
else
    PASS=$((PASS+1)); echo "ok   - paid filter excludes closed job"
fi

# Reopen from the list
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$ABBOTT_ID" --data-urlencode 'status=open' --data-urlencode 'return=list' --data-urlencode 'group=paid' --data-urlencode 'status_filter=closed')
OUT=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
check "reopened job visible to techs again" 'Abbott' "$OUT"

# Forward then backward through billing, all from the list
form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$ABBOTT_ID" --data-urlencode 'status=ready_for_billing' --data-urlencode 'return=list' --data-urlencode 'group=active' >/dev/null
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$ABBOTT_ID" --data-urlencode 'status=billed' --data-urlencode 'return=list' --data-urlencode 'group=billing')
check "advance to billed from list" 'Billing' "$OUT"
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$ABBOTT_ID" --data-urlencode 'status=ready_for_billing' --data-urlencode 'return=list' --data-urlencode 'group=billing')
check "move back to ready for billing from list" '>Ready for Billing<' "$OUT"

# --- Job name search on the tabs (partial, token-based) ---
# Abbott job ("Abbott - 1 First St") is in the billing group by now.
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=billing' --data-urlencode 'q=abb' --data-urlencode 'cards_only=1')
check "partial job search matches" 'Abbott - 1 First St' "$OUT"
check "search updates count out-of-band" 'id="job-count"' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=billing' --data-urlencode 'q=first abbott' --data-urlencode 'cards_only=1')
check "multi-token search matches any order" 'Abbott - 1 First St' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=billing' --data-urlencode 'q=zzzznomatch' --data-urlencode 'cards_only=1')
check "search with no matches" 'No jobs match your search' "$OUT"

# Search composes with the status filter (wrong status filter -> no match)
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=billing' --data-urlencode 'status_filter=billed' --data-urlencode 'q=abbott' --data-urlencode 'cards_only=1')
if [[ "$OUT" == *'Abbott - 1 First St'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - search composes with status filter"
else
    PASS=$((PASS+1)); echo "ok   - search composes with status filter"
fi

# --- Customer report: fuzzy search and per-customer jobs ---
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=customer-search' --data-urlencode 'q=abb')
check "customer search is fuzzy" 'Abbott' "$OUT"
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=customer-jobs' --data-urlencode 'customer=Abbott')
check "customer jobs lists the job" 'Abbott - 1 First St' "$OUT"

# --- Jobs with zero tasks must not show a "running" count ---
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=active')
if [[ "$OUT" == *'0 tasks ('* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - zero-task job shows no running count"
else
    PASS=$((PASS+1)); echo "ok   - zero-task job shows no running count"
fi

# --- Offline replay: queued tasks are accepted into closed jobs, idempotently ---
UUID="test-uuid-$RANDOM"
# JOB3 (Lee) was marked ready_for_billing earlier; a live submit must fail...
OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":$JOB3_ID,\"start_time\":\"2026-06-06 15:00\",\"client_uuid\":\"$UUID\"}")
check "live submit to closed job still rejected" 'not open for new tasks' "$OUT"
# ...but a queued (offline) replay is accepted
OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":$JOB3_ID,\"start_time\":\"2026-06-06 15:00\",\"client_uuid\":\"$UUID\",\"queued\":true}")
check "queued task accepted into closed job" '"success":true' "$OUT"
QTASK_ID=$(echo "$OUT" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["task_id"];')
# Replaying the same uuid must not duplicate
OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":$JOB3_ID,\"start_time\":\"2026-06-06 15:00\",\"client_uuid\":\"$UUID\",\"queued\":true}")
check "replayed uuid is idempotent" "\"task_id\":$QTASK_ID" "$OUT"
# Completing by uuid (offline-created tasks have no server id on the client)
OUT=$(post complete-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"task_uuid\":\"$UUID\",\"end_time\":\"2026-06-06 16:00\",\"notes\":\"synced from offline queue\",\"queued\":true}")
check "complete by client uuid" '"success":true' "$OUT"

# --- Tech autocomplete is alphabetical (system job first) ---
OUT=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}")
SORTED=$(echo "$OUT" | php -r '
$d = json_decode(stream_get_contents(STDIN), true);
$names = array_values(array_map(function($j){ return $j["name"]; },
    array_filter($d["jobs"], function($j){ return !$j["is_system"]; })));
$sorted = $names; usort($sorted, "strcasecmp");
echo ($names === $sorted && $d["jobs"][0]["is_system"]) ? "yes" : "no";')
check "open jobs sorted alphabetically after Clock" 'yes' "$SORTED"

# --- Clock In/Out: own admin view, tech filter, never billable ---
CLOCK_ID=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["jobs"] as $j) if($j["is_system"]) { echo $j["id"]; break; }')

OUT=$(post create-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"job_id\":$CLOCK_ID,\"start_time\":\"2026-06-06 07:00\"}")
CLOCK_TASK_ID=$(echo "$OUT" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["task_id"];')
post complete-task.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\",\"task_id\":$CLOCK_TASK_ID,\"end_time\":\"2026-06-06 07:45\",\"notes\":\"loaded truck\"}" >/dev/null

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-clock')
check "clock view shows entry" 'loaded truck' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-clock' --data-urlencode 'tech=Joe')
if [[ "$OUT" == *'loaded truck'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - clock tech filter excludes other techs"
else
    PASS=$((PASS+1)); echo "ok   - clock tech filter excludes other techs"
fi

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=set-status' --data-urlencode "id=$CLOCK_ID" --data-urlencode 'status=ready_for_billing')
check "clock job cannot be marked for billing" 'cannot change status' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-job' --data-urlencode "id=$CLOCK_ID")
check "clock job detail redirects to clock view" 'Clock In/Out' "$OUT"

# --- Tags: curated vocabulary, assignment, list filtering, cascade ---
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=manage-tags')
check "manage-tags lists seeded tags" 'Plumbing' "$OUT"
check "manage-tags lists seeded HVAC"  'HVAC' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=create-tag' --data-urlencode 'name=Roofing')
check "create tag adds to vocabulary" 'Roofing' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=create-tag' --data-urlencode 'name=plumbing')
check "duplicate tag rejected (case-insensitive)" 'already exists' "$OUT"

# Tag ids from the throwaway db (read-only; the server isn't writing here)
HVAC_ID=$(HANDYMANAGER_DB="$DB" php -r 'require "database.php"; foreach(getAllTags() as $t) if($t["name"]==="HVAC"){echo $t["id"];}')
ROOF_ID=$(HANDYMANAGER_DB="$DB" php -r 'require "database.php"; foreach(getAllTags() as $t) if($t["name"]==="Roofing"){echo $t["id"];}')

# Assign a tag to the Smith job (has 1 task, 2h) via Edit Details save
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=save-job' --data-urlencode "id=$JOB_ID" \
    --data-urlencode 'name=Smith - 412 Oak Ave' --data-urlencode "tags[]=$HVAC_ID")
check "job detail shows assigned tag chip" 'tag-chip' "$OUT"
check "job detail shows assigned tag name" 'HVAC' "$OUT"

# Filter the Paid tab by the tag: the job shows AND its task aggregates are intact
# (the EXISTS filter must not multiply rows and break COUNT/SUM).
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=paid' --data-urlencode "tag=$HVAC_ID")
check "tag filter shows the tagged job"        'Smith - 412 Oak Ave' "$OUT"
check "tag filter preserves task aggregates"   '1 task' "$OUT"

# A different tag (Smith isn't Roofing) excludes it
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-jobs' --data-urlencode 'group=paid' --data-urlencode "tag=$ROOF_ID")
if [[ "$OUT" == *'Smith - 412 Oak Ave'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - tag filter excludes jobs without the tag"
else
    PASS=$((PASS+1)); echo "ok   - tag filter excludes jobs without the tag"
fi

# Forms expose the curated vocabulary as checkboxes (no free-text)
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=log-call-form')
check "log-call form renders tag checkboxes" 'name="tags[]"' "$OUT"
OUT=$(post log-call.php "{\"token\":\"$ADMIN\",\"action\":\"suggestions\"}")
check "suggestions include the tag vocabulary" '"name":"HVAC"' "$OUT"

# Log a call with a tag selected; the new job carries it
OUT=$(post log-call.php "{\"token\":\"$ADMIN\",\"customer_name\":\"Tagged\",\"location\":\"2 Tag St\",\"tags\":[\"$ROOF_ID\"]}")
check "log-call with tag opens job" 'Tagged - 2 Tag St' "$OUT"
TAGGED_ID=$(post get-open-jobs.php "{\"token\":\"$TOKEN\",\"tech_name\":\"Tim\"}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["jobs"] as $j) if(strpos($j["name"],"Tagged")!==false){echo $j["id"];break;}')
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-job' --data-urlencode "id=$TAGGED_ID")
check "tag set at intake is on the job" 'Roofing' "$OUT"

# Rename a tag (vocabulary-level)
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=rename-tag' --data-urlencode "tag_id=$ROOF_ID" --data-urlencode 'name=Roof Work')
check "rename tag updates vocabulary" 'Roof Work' "$OUT"
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-job' --data-urlencode "id=$TAGGED_ID")
check "renamed tag reflected on job" 'Roof Work' "$OUT"

# Delete a tag: it drops off every job (FK cascade)
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=delete-tag' --data-urlencode "tag_id=$ROOF_ID")
check "delete tag from vocabulary" 'Tag deleted' "$OUT"
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=view-job' --data-urlencode "id=$TAGGED_ID")
if [[ "$OUT" == *'Roof Work'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - deleting a tag removes it from its jobs"
else
    PASS=$((PASS+1)); echo "ok   - deleting a tag removes it from its jobs"
fi

# --- Job and report exports ---
OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=export-job-json' --data-urlencode "id=$JOB_ID")
check "job JSON export has tasks" '"tasks"' "$OUT"
check "job JSON export has summary" '"total_hours"' "$OUT"
check "job JSON export includes tags" '"HVAC"' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=job-text' --data-urlencode "id=$JOB_ID")
check "job plain-text view renders textarea" '<textarea class="job-text"' "$OUT"
check "job plain-text has no markdown heading" 'Status: ' "$OUT"
check "job plain-text has task log" 'Task Log:' "$OUT"
if [[ "$OUT" == *'## '* || "$OUT" == *'**'* ]]; then
    FAIL=$((FAIL+1)); echo "FAIL - plain text contains no markdown syntax"
else
    PASS=$((PASS+1)); echo "ok   - plain text contains no markdown syntax"
fi

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=export-months-csv')
check "months CSV export" 'Month,"Jobs Completed"' "$OUT"

OUT=$(form --data-urlencode "token=$ADMIN" --data-urlencode 'action=export-month-csv' --data-urlencode "month=$(date +%Y-%m)")
check "month detail CSV export" 'Job,Customer,Phone,Status' "$OUT"

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
