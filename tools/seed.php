<?php
// tools/seed.php - Create a fresh local database with sample data for development
//
// Usage: HANDYMANAGER_DB=handymanager-test.db php tools/seed.php
//        (or `rake seed`)

if (!getenv('HANDYMANAGER_DB')) {
    fwrite(STDERR, "Refusing to run without HANDYMANAGER_DB set - this would seed the real database.\n");
    fwrite(STDERR, "Usage: HANDYMANAGER_DB=handymanager-test.db php tools/seed.php\n");
    exit(1);
}

$dbFile = getenv('HANDYMANAGER_DB');
if (file_exists($dbFile)) {
    unlink($dbFile);
    echo "Removed existing $dbFile\n";
}

require_once __DIR__ . '/../database.php';
initDatabase();

// --- Open job, no tasks yet (fresh call) ---
$jobA = createJob('Smith - 412 Oak Ave', 'Smith', '555-0101', 'smith@example.com', 'Leaky kitchen faucet, also asked about regrouting the bathroom tile.');

// --- In-progress job with tasks ---
$jobB = createJob('Hendersons - 88 Lakeview Dr', 'Hendersons', '555-0102', 'hendersons@example.com', 'Deck boards rotting near the stairs. Wants an estimate for full replacement.');
list($t1,) = createTask($jobB, 'Tim', date('Y-m-d H:i:s', strtotime('-3 days 9:00')));
completeTask($t1, date('Y-m-d H:i:s', strtotime('-3 days 12:30')), "Notes:\n- Removed rotten boards, measured for replacements\n\nMaterials:\n- 6x 2x6x8 PT lumber");
list($t2,) = createTask($jobB, 'Tim', date('Y-m-d H:i:s', strtotime('-1 day 8:30')));
completeTask($t2, date('Y-m-d H:i:s', strtotime('-1 day 15:00')), "Notes:\n- Installed new boards, sealed\n\nMaterials:\n- Deck screws, sealant");
updateJobFields($jobB, ['admin_notes' => 'Estimate approved 5/28. Tim handling. Needs final walkthrough before billing.']);

// --- Job with a task still running ---
$jobC = createJob('Patel - 7 Birch Ct', 'Patel', '555-0103', '', 'Drywall repair in garage.');
createTask($jobC, 'Joe', date('Y-m-d H:i:s', strtotime('-2 hours')));

// --- Completed jobs across two months (for reports) ---
$jobD = createJob('Riverside Cafe - 301 Main St', 'Riverside Cafe', '555-0104', 'manager@riversidecafe.example', 'Replace back door and frame.');
list($t4,) = createTask($jobD, 'Tim', date('Y-m-d H:i:s', strtotime('first day of last month 10:00')));
completeTask($t4, date('Y-m-d H:i:s', strtotime('first day of last month 16:00')), "Notes:\n- Door and frame replaced");
setJobStatus($jobD, 'ready_for_billing');
setJobStatus($jobD, 'billed');
setJobStatus($jobD, 'paid');

$jobE = createJob('Garcia - 19 Elm St', 'Garcia', '555-0105', 'garcia@example.com', 'Fence gate sagging.');
list($t5,) = createTask($jobE, 'Joe', date('Y-m-d H:i:s', strtotime('-10 days 13:00')));
completeTask($t5, date('Y-m-d H:i:s', strtotime('-10 days 15:30')), "Notes:\n- Rehung gate, replaced hinges");
setJobStatus($jobE, 'ready_for_billing');
updateJobFields($jobE, ['admin_notes' => 'Invoice #1042 ready to send.']);

// --- A repeat customer: earlier job closed, new one open at same location ---
$jobF = createJob('Smith - 412 Oak Ave', 'Smith', '555-0101', 'smith@example.com', 'PREVIOUS visit: garbage disposal replacement.');
list($t6,) = createTask($jobF, 'Tim', date('Y-m-d H:i:s', strtotime('-40 days 9:00')));
completeTask($t6, date('Y-m-d H:i:s', strtotime('-40 days 11:00')), "Notes:\n- Disposal replaced");
setJobStatus($jobF, 'ready_for_billing');
setJobStatus($jobF, 'billed');

// --- Clock in/out entries ---
$pdo = getDbConnection();
$clockJobId = $pdo->query("SELECT id FROM jobs WHERE is_system = 1")->fetchColumn();
list($t7,) = createTask($clockJobId, 'Joe', date('Y-m-d H:i:s', strtotime('-1 day 7:30')));
completeTask($t7, date('Y-m-d H:i:s', strtotime('-1 day 8:15')), 'Shop time - loaded truck');

echo "Seeded $dbFile:\n";
echo "  jobs:  " . $pdo->query("SELECT COUNT(*) FROM jobs WHERE is_system = 0")->fetchColumn() . "\n";
echo "  tasks: " . $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn() . "\n";
echo "\nRun the dev server with:  rake dev   (uses $dbFile)\n";
echo "Tokens are read from config.json as usual.\n";
