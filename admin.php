<?php
// admin.php - Admin dashboard: jobs by status, job timeline, call log, reports
//
// GET  -> serves the page shell (no data, token gate)
// POST -> serves HTML fragments for htmx; the admin token is verified on
//         every request (it is sent as a form param from localStorage).

require_once __DIR__ . '/config.php';

const STATUS_LABELS = [
    'open' => 'Open',
    'in_progress' => 'In Progress',
    'on_hold' => 'On Hold',
    'ready_for_billing' => 'Ready for Billing',
    'billed' => 'Billed',
    'paid' => 'Paid',
    'closed' => 'Closed',
];

const STATUS_GROUPS = [
    'active' => ['open', 'in_progress', 'on_hold'],
    'billing' => ['ready_for_billing', 'billed'],
    'paid' => ['paid', 'closed'],
];

const GROUP_TITLES = [
    'active' => 'Active Jobs',
    'billing' => 'Billing',
    'paid' => 'Paid & Closed',
];

// ---------------------------------------------------------------------------
// Fragment endpoints (POST)
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The token is the only security - check it on every request
    if (!verifyAdminToken($_POST['token'] ?? null)) {
        http_response_code(401);
        echo '<div class="flash flash-error">Invalid admin token</div>';
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'view-jobs':
            renderJobsView($_POST['group'] ?? 'active', $_POST['status_filter'] ?? '');
            break;

        case 'view-job':
            renderJobDetail((int)$_POST['id']);
            break;

        case 'view-clock':
            renderClockView($_POST['tech'] ?? '', $_POST['month'] ?? '');
            break;

        case 'set-status':
            list($ok, $message) = setJobStatus((int)$_POST['id'], $_POST['status'] ?? '');
            // Status can be changed from a job list or from the detail view;
            // re-render whichever the request came from.
            if (($_POST['return'] ?? '') === 'list') {
                renderJobsView($_POST['group'] ?? 'active', $_POST['status_filter'] ?? '',
                    $ok ? null : $message, !$ok);
            } else {
                renderJobDetail((int)$_POST['id'], $ok ? 'Status updated' : $message, !$ok);
            }
            break;

        case 'save-notes':
            updateJobFields((int)$_POST['id'], ['admin_notes' => $_POST['admin_notes'] ?? '']);
            $job = getJobById((int)$_POST['id']);
            renderAdminNotes($job, true);
            break;

        case 'job-edit-form':
            renderJobEditForm((int)$_POST['id']);
            break;

        case 'save-job':
            $ok = updateJobFields((int)$_POST['id'], [
                'name' => $_POST['name'] ?? '',
                'customer_name' => $_POST['customer_name'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'call_notes' => $_POST['call_notes'] ?? '',
            ]);
            renderJobDetail((int)$_POST['id'], $ok ? 'Job updated' : 'Job name is required', !$ok);
            break;

        case 'delete-job':
            deleteJob((int)$_POST['id']);
            renderJobsView($_POST['group'] ?? 'active', $_POST['status_filter'] ?? '', 'Job deleted');
            break;

        case 'customer-search':
            renderCustomerSearchResults($_POST['q'] ?? '');
            break;

        case 'customer-jobs':
            renderCustomerJobs($_POST['customer'] ?? '');
            break;

        case 'log-call-form':
            renderLogCallForm();
            break;

        case 'log-call':
            $customer = trim($_POST['customer_name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            if ($customer === '' || $location === '') {
                renderLogCallForm('Customer name and location are required', true, $_POST);
                break;
            }
            // The customer name + location becomes the official job name
            $name = "$customer - $location";
            $jobId = createJob($name, $customer, $_POST['phone'] ?? '', $_POST['call_notes'] ?? '');
            renderLogCallForm("Job opened: " . $name, false);
            break;

        case 'task-add-form':
            renderTaskAddForm((int)$_POST['id']);
            break;

        case 'add-task':
            $jobId = (int)$_POST['id'];
            $tech = trim($_POST['tech_name'] ?? '');
            $start = validateDateTime(trim(($_POST['start_date'] ?? '') . ' ' . ($_POST['start_time'] ?? '')));
            if ($tech === '' || $start === false) {
                renderJobDetail($jobId, 'Tech name and a valid start date/time are required', true);
                break;
            }
            list($taskId, $message) = createTask($jobId, $tech, $start);
            if ($taskId === false) {
                renderJobDetail($jobId, $message, true);
                break;
            }
            // Optional end time and notes in the same step
            $end = (trim($_POST['end_date'] ?? '') !== '' && trim($_POST['end_time'] ?? '') !== '')
                ? validateDateTime($_POST['end_date'] . ' ' . $_POST['end_time']) : null;
            $notes = trim($_POST['notes'] ?? '');
            if ($end || $notes !== '') {
                updateTaskPartial($taskId, [
                    'end_time' => $end ?: null,
                    'closed_at' => $end ? now() : null,
                    'notes' => $notes !== '' ? $_POST['notes'] : null,
                ]);
            }
            renderJobDetail($jobId, 'Task added');
            break;

        case 'task-edit-form':
            renderTaskEditForm((int)$_POST['task_id'], $_POST['clock_tech'] ?? '', $_POST['clock_month'] ?? '');
            break;

        case 'save-task':
            $task = getTaskById((int)$_POST['task_id']);
            if ($task) {
                $start = validateDateTime(trim(($_POST['start_date'] ?? '') . ' ' . ($_POST['start_time'] ?? '')));
                $end = (trim($_POST['end_date'] ?? '') !== '' && trim($_POST['end_time'] ?? '') !== '')
                    ? validateDateTime($_POST['end_date'] . ' ' . $_POST['end_time']) : null;
                updateTaskPartial($task['id'], [
                    'start_time' => $start ?: null,
                    'end_time' => $end ?: null,
                    // Setting an end time finishes the task
                    'closed_at' => ($end && !$task['closed_at']) ? now() : null,
                    'notes' => $_POST['notes'] ?? '',
                    'tech_name' => trim($_POST['tech_name'] ?? '') ?: null,
                ]);
                if ($task['job_is_system']) {
                    renderClockView($_POST['clock_tech'] ?? '', $_POST['clock_month'] ?? '', 'Entry updated');
                } else {
                    renderJobDetail((int)$task['job_id'], 'Task updated');
                }
            }
            break;

        case 'delete-task':
            $task = getTaskById((int)$_POST['task_id']);
            if ($task) {
                deleteTask($task['id']);
                if ($task['job_is_system']) {
                    renderClockView($_POST['clock_tech'] ?? '', $_POST['clock_month'] ?? '', 'Entry deleted');
                } else {
                    renderJobDetail((int)$task['job_id'], 'Task deleted');
                }
            }
            break;

        case 'view-reports':
            renderReports();
            break;

        case 'report-month':
            renderMonthDetail($_POST['month'] ?? '');
            break;

        case 'report-tech':
            renderTechReport($_POST['tech'] ?? '', $_POST['month'] ?? date('Y-m'));
            break;

        case 'export-tech-csv':
            exportTechCsv($_POST['tech'] ?? '', $_POST['month'] ?? date('Y-m'));
            break;

        case 'export-months-csv':
            exportMonthsCsv();
            break;

        case 'export-month-csv':
            exportMonthCsv($_POST['month'] ?? '');
            break;

        case 'export-job-json':
            exportJobJson((int)$_POST['id']);
            break;

        case 'export-job-md':
            exportJobMarkdown((int)$_POST['id']);
            break;

        default:
            http_response_code(400);
            echo '<div class="flash flash-error">Unknown action</div>';
    }
    exit;
}

// ---------------------------------------------------------------------------
// Formatting helpers
// ---------------------------------------------------------------------------

function fmtDt($value) {
    if (!$value) return '';
    $ts = strtotime($value);
    return $ts ? date('M j, Y g:i A', $ts) : $value;
}

function fmtDate($value) {
    if (!$value) return '';
    $ts = strtotime($value);
    return $ts ? date('M j, Y', $ts) : $value;
}

function fmtMonth($month) { // 'YYYY-MM' -> 'Month YYYY'
    $ts = strtotime($month . '-01');
    return $ts ? date('F Y', $ts) : $month;
}

function fmtHours($hours) {
    return $hours === null ? '–' : number_format((float)$hours, 1) . ' h';
}

function statusBadge($status) {
    $label = STATUS_LABELS[$status] ?? $status;
    return '<span class="badge badge-' . h($status) . '">' . h($label) . '</span>';
}

function flash($message, $isError = false) {
    if ($message === null) return '';
    $class = $isError ? 'flash flash-error' : 'flash flash-ok';
    return '<div class="' . $class . '">' . h($message) . '</div>';
}

// ---------------------------------------------------------------------------
// Fragments
// ---------------------------------------------------------------------------

function renderJobsView($group, $statusFilter = '', $message = null, $isError = false) {
    $group = isset(STATUS_GROUPS[$group]) ? $group : 'active';
    $groupStatuses = STATUS_GROUPS[$group];
    // A status filter narrows the group to a single status (must belong to it)
    $statuses = ($statusFilter !== '' && in_array($statusFilter, $groupStatuses, true))
        ? [$statusFilter] : $groupStatuses;
    $jobs = getJobsByStatus($statuses);
    ?>
    <?= flash($message, $isError) ?>
    <div class="view-header">
        <h2><?= h(GROUP_TITLES[$group] ?? 'Jobs') ?></h2>
        <span class="muted"><?= count($jobs) ?> job<?= count($jobs) === 1 ? '' : 's' ?></span>
    </div>

    <?php if (count($groupStatuses) > 1): ?>
    <div class="filter-pills">
        <button class="pill <?= $statusFilter === '' ? 'active' : '' ?>" hx-post="admin.php" hx-target="#content"
            hx-vals='<?= h(json_encode(['action' => 'view-jobs', 'group' => $group, 'status_filter' => ''])) ?>'>All</button>
        <?php foreach ($groupStatuses as $st): ?>
        <button class="pill <?= $statusFilter === $st ? 'active' : '' ?>" hx-post="admin.php" hx-target="#content"
            hx-vals='<?= h(json_encode(['action' => 'view-jobs', 'group' => $group, 'status_filter' => $st])) ?>'><?= h(STATUS_LABELS[$st]) ?></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($jobs)): ?>
        <p class="empty-state">No jobs here.</p>
    <?php endif; ?>
    <div class="job-cards">
        <?php foreach ($jobs as $job): ?>
        <div class="job-card" hx-post="admin.php" hx-vals='{"action":"view-job","id":<?= (int)$job['id'] ?>}'
             hx-target="#content" hx-swap="innerHTML show:window:top">
            <div class="job-card-top">
                <span class="job-card-name"><?= h($job['name']) ?></span>
                <?= statusBadge($job['status']) ?>
            </div>
            <div class="job-card-meta">
                <?php if ($job['phone']): ?><span><?= h($job['phone']) ?></span><?php endif; ?>
                <span><?= (int)$job['task_count'] ?> task<?= (int)$job['task_count'] === 1 ? '' : 's' ?><?php
                    if ((int)$job['open_task_count'] > 0) echo ' (' . (int)$job['open_task_count'] . ' running)';
                ?></span>
                <?php if ($job['total_hours'] !== null): ?><span><?= fmtHours($job['total_hours']) ?></span><?php endif; ?>
                <span>Opened <?= h(fmtDate($job['opened_at'])) ?></span>
                <?php if ($job['ready_for_billing_at']): ?><span>Completed <?= h(fmtDate($job['ready_for_billing_at'])) ?></span><?php endif; ?>
                <?php if ($job['paid_at']): ?><span>Paid <?= h(fmtDate($job['paid_at'])) ?></span><?php endif; ?>
                <?php if ($job['closed_at']): ?><span>Closed <?= h(fmtDate($job['closed_at'])) ?></span><?php endif; ?>
            </div>
            <?php if ($job['admin_notes']): ?>
            <div class="job-card-notes"><?= h(mb_strimwidth($job['admin_notes'], 0, 220, '…')) ?></div>
            <?php endif; ?>
            <div class="job-card-actions">
                <?php renderStatusButtons($job, ['return' => 'list', 'group' => $group, 'status_filter' => $statusFilter], 'btn-xs'); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderAdminNotes($job, $justSaved = false) {
    ?>
    <div class="panel" id="admin-notes-panel">
        <form hx-post="admin.php" hx-target="#admin-notes-panel" hx-swap="outerHTML">
            <input type="hidden" name="action" value="save-notes">
            <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
            <div class="panel-title">
                <label for="admin_notes">Job Notes</label>
                <?php if ($justSaved): ?><span class="saved-tag">Saved ✓</span><?php endif; ?>
            </div>
            <textarea name="admin_notes" id="admin_notes" rows="4"
                placeholder="Summary and progress notes for this job…"><?= h($job['admin_notes']) ?></textarea>
            <button type="submit" class="btn btn-primary btn-sm">Save Notes</button>
        </form>
    </div>
    <?php
}

// Available status transitions for a job: forward, one step backward, plus
// hold/close where relevant. A job on hold (or closed) resumes to "open" if
// it has no tasks yet, otherwise to "in progress".
function jobTransitions($job) {
    $hasTasks = ((int)($job['task_count'] ?? 0)) > 0;
    $resume = $hasTasks ? 'in_progress' : 'open';
    switch ($job['status']) {
        case 'open':
        case 'in_progress':
            return [
                ['ready_for_billing', 'Ready for Billing', 'btn-primary'],
                ['on_hold', 'Hold', 'btn-ghost'],
                ['closed', 'Close', 'btn-ghost'],
            ];
        case 'on_hold':
            return [
                [$resume, 'Resume Job', 'btn-primary'],
                ['ready_for_billing', 'Ready for Billing', 'btn-ghost'],
                ['closed', 'Close', 'btn-ghost'],
            ];
        case 'ready_for_billing':
            return [
                ['billed', 'Mark Billed', 'btn-primary'],
                ['in_progress', 'Reopen', 'btn-ghost'],
                ['closed', 'Close', 'btn-ghost'],
            ];
        case 'billed':
            return [
                ['paid', 'Mark Paid', 'btn-primary'],
                ['ready_for_billing', 'Back to Ready for Billing', 'btn-ghost'],
            ];
        case 'paid':
            return [['billed', 'Back to Billed', 'btn-ghost']];
        case 'closed':
            return [[$resume, 'Reopen', 'btn-primary']];
    }
    return [];
}

// Render the status transition buttons. $returnVals carries where the
// re-render should land (a job list or the detail view). onclick stops the
// click from also triggering the enclosing card's view-job request.
function renderStatusButtons($job, array $returnVals, $size = 'btn-sm') {
    foreach (jobTransitions($job) as $t) {
        list($status, $label, $class) = $t;
        $vals = array_merge(
            ['action' => 'set-status', 'id' => (int)$job['id'], 'status' => $status],
            $returnVals
        );
        ?>
        <button class="btn <?= $class ?> <?= $size ?>" onclick="event.stopPropagation()"
            hx-post="admin.php" hx-target="#content"
            hx-vals='<?= h(json_encode($vals)) ?>'><?= h($label) ?></button>
        <?php
    }
}

function renderJobDetail($jobId, $message = null, $isError = false) {
    $job = getJobById($jobId);
    if (!$job) {
        echo '<div class="flash flash-error">Job not found</div>';
        return;
    }
    // The Clock in/out job has its own dedicated view - no statuses, no billing
    if ($job['is_system']) {
        renderClockView('', '', $message, $isError);
        return;
    }
    $tasks = getTasksForJob($jobId);
    $totalHours = 0;
    foreach ($tasks as $task) {
        $totalHours += (float)($task['hours'] ?? 0);
    }
    $job['task_count'] = count($tasks); // for jobTransitions resume target
    $backGroup = 'active';
    foreach (STATUS_GROUPS as $g => $statuses) {
        if (in_array($job['status'], $statuses, true)) { $backGroup = $g; break; }
    }
    ?>
    <?= flash($message, $isError) ?>
    <div class="view-header">
        <button class="btn btn-ghost btn-sm" hx-post="admin.php" hx-target="#content"
            hx-vals='{"action":"view-jobs","group":"<?= $backGroup ?>"}'>&larr; Back to jobs</button>
    </div>
    <div class="job-detail-header">
        <div>
            <h2><?= h($job['name']) ?></h2>
            <div class="job-card-meta">
                <?= statusBadge($job['status']) ?>
                <?php if ($job['customer_name']): ?><span><?= h($job['customer_name']) ?></span><?php endif; ?>
                <?php if ($job['phone']): ?><span><a href="tel:<?= h($job['phone']) ?>"><?= h($job['phone']) ?></a></span><?php endif; ?>
            </div>
        </div>
        <div class="job-detail-actions">
            <?php renderStatusButtons($job, ['return' => 'detail']); ?>
            <button class="btn btn-ghost btn-sm" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"job-edit-form","id":<?= (int)$job['id'] ?>}'>Edit Details</button>
            <form method="post" action="admin.php" class="inline-form">
                <input type="hidden" name="action" value="export-job-json">
                <input type="hidden" name="token" value="">
                <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">JSON ↓</button>
            </form>
            <form method="post" action="admin.php" class="inline-form">
                <input type="hidden" name="action" value="export-job-md">
                <input type="hidden" name="token" value="">
                <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">Markdown ↓</button>
            </form>
        </div>
    </div>

    <div class="status-dates">
        <span>Opened <?= h(fmtDt($job['opened_at'])) ?></span>
        <?php if ($job['ready_for_billing_at']): ?><span>Ready for billing <?= h(fmtDate($job['ready_for_billing_at'])) ?></span><?php endif; ?>
        <?php if ($job['billed_at']): ?><span>Billed <?= h(fmtDate($job['billed_at'])) ?></span><?php endif; ?>
        <?php if ($job['paid_at']): ?><span>Paid <?= h(fmtDate($job['paid_at'])) ?></span><?php endif; ?>
        <?php if ($job['closed_at']): ?><span>Closed <?= h(fmtDate($job['closed_at'])) ?></span><?php endif; ?>
        <span><?= count($tasks) ?> task<?= count($tasks) === 1 ? '' : 's' ?>, <?= fmtHours($totalHours) ?></span>
    </div>

    <?php renderAdminNotes($job); ?>

    <div class="timeline-header">
        <h3>Timeline</h3>
        <?php if (jobAcceptsTasks($job)): ?>
        <button class="btn btn-ghost btn-sm" hx-post="admin.php" hx-target="#add-task-slot" hx-swap="innerHTML"
            hx-vals='{"action":"task-add-form","id":<?= (int)$job['id'] ?>}'>+ Add Task</button>
        <?php else: ?>
        <span class="muted">Closed to new tasks — reopen the job to add one.</span>
        <?php endif; ?>
    </div>
    <div id="add-task-slot"></div>
    <div class="timeline">
        <?php foreach ($tasks as $task): ?>
        <div class="timeline-item">
            <div class="timeline-item-head">
                <strong><?= h($task['tech_name']) ?></strong>
                <span class="muted">
                    <?= h(fmtDt($task['start_time'])) ?>
                    <?= $task['end_time'] ? '→ ' . h(fmtDt($task['end_time'])) . ' (' . fmtHours($task['hours']) . ')' : '— in progress' ?>
                </span>
                <span class="timeline-item-actions">
                    <button class="btn btn-ghost btn-xs" hx-post="admin.php" hx-target="closest .timeline-item" hx-swap="outerHTML"
                        hx-vals='{"action":"task-edit-form","task_id":<?= (int)$task['id'] ?>}'>Edit</button>
                    <button class="btn btn-danger btn-xs" hx-post="admin.php" hx-target="#content"
                        hx-confirm="Delete this task? This cannot be undone."
                        hx-vals='{"action":"delete-task","task_id":<?= (int)$task['id'] ?>}'>Delete</button>
                </span>
            </div>
            <?php if ($task['notes']): ?>
            <div class="timeline-item-body"><pre class="notes"><?= h($task['notes']) ?></pre></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (empty($tasks)): ?>
        <p class="empty-state">No tasks yet.</p>
        <?php endif; ?>

        <!-- Oldest event last: the timeline is reverse chronological -->
        <div class="timeline-item timeline-call">
            <div class="timeline-item-head">
                <strong>Call logged — job opened</strong>
                <span class="muted"><?= h(fmtDt($job['opened_at'])) ?></span>
            </div>
            <div class="timeline-item-body">
                <?php if ($job['customer_name']): ?><div><strong>Customer:</strong> <?= h($job['customer_name']) ?></div><?php endif; ?>
                <?php if ($job['phone']): ?><div><strong>Phone:</strong> <?= h($job['phone']) ?></div><?php endif; ?>
                <?php if ($job['call_notes']): ?><pre class="notes"><?= h($job['call_notes']) ?></pre><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="danger-zone">
        <button class="btn btn-danger btn-sm" hx-post="admin.php" hx-target="#content"
            hx-confirm="Delete this job AND all of its tasks? This cannot be undone."
            hx-vals='<?= h(json_encode(['action' => 'delete-job', 'id' => (int)$job['id'], 'group' => $backGroup])) ?>'>Delete Job</button>
    </div>
    <?php
}

function renderJobEditForm($jobId) {
    $job = getJobById($jobId);
    if (!$job) {
        echo '<div class="flash flash-error">Job not found</div>';
        return;
    }
    ?>
    <div class="view-header"><h2>Edit Job</h2></div>
    <form class="panel form-stack" hx-post="admin.php" hx-target="#content">
        <input type="hidden" name="action" value="save-job">
        <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
        <label>Job Name (shown to techs)
            <input type="text" name="name" value="<?= h($job['name']) ?>" required>
        </label>
        <label>Customer Name
            <input type="text" name="customer_name" value="<?= h($job['customer_name']) ?>">
        </label>
        <label>Phone
            <input type="tel" name="phone" value="<?= h($job['phone']) ?>">
        </label>
        <label>Call Notes
            <textarea name="call_notes" rows="4"><?= h($job['call_notes']) ?></textarea>
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn btn-ghost" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"view-job","id":<?= (int)$job['id'] ?>}'>Cancel</button>
        </div>
    </form>
    <?php
}

function renderClockView($tech = '', $month = '', $message = null, $isError = false) {
    $tasks = getClockTasks($tech, $month);
    $techs = getTechNames();
    $months = getClockTaskMonths();
    $totalHours = 0;
    foreach ($tasks as $task) {
        $totalHours += (float)($task['hours'] ?? 0);
    }
    ?>
    <?= flash($message, $isError) ?>
    <div class="view-header">
        <h2>Clock In/Out</h2>
        <span class="muted"><?= count($tasks) ?> entr<?= count($tasks) === 1 ? 'y' : 'ies' ?>, <?= fmtHours($totalHours) ?></span>
    </div>

    <form class="form-row report-controls" hx-post="admin.php" hx-target="#content" hx-trigger="change">
        <input type="hidden" name="action" value="view-clock">
        <label>Tech
            <select name="tech">
                <option value="">All techs</option>
                <?php foreach ($techs as $t): ?>
                <option value="<?= h($t) ?>" <?= $t === $tech ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Month
            <select name="month">
                <option value="">All months</option>
                <?php foreach ($months as $m): ?>
                <option value="<?= h($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= h(fmtMonth($m)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php if (empty($tasks)): ?>
        <p class="empty-state">No clock in/out entries<?= $tech !== '' || $month !== '' ? ' for this filter' : '' ?>.</p>
        <?php return; ?>
    <?php endif; ?>

    <table class="report-table">
        <thead><tr><th>Tech</th><th>Start</th><th>End</th><th>Hours</th><th>Notes</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($tasks as $task): ?>
            <tr>
                <td><?= h($task['tech_name']) ?></td>
                <td><?= h(fmtDt($task['start_time'])) ?></td>
                <td><?= $task['end_time'] ? h(fmtDt($task['end_time'])) : '<em>in progress</em>' ?></td>
                <td><?= fmtHours($task['hours']) ?></td>
                <td class="notes-cell"><?= $task['notes'] ? h(mb_strimwidth($task['notes'], 0, 160, '…')) : '' ?></td>
                <td>
                    <button class="btn btn-ghost btn-xs" hx-post="admin.php" hx-target="#content"
                        hx-vals='<?= h(json_encode(['action' => 'task-edit-form', 'task_id' => (int)$task['id'], 'clock_tech' => $tech, 'clock_month' => $month])) ?>'>Edit</button>
                    <button class="btn btn-danger btn-xs" hx-post="admin.php" hx-target="#content"
                        hx-confirm="Delete this clock entry? This cannot be undone."
                        hx-vals='<?= h(json_encode(['action' => 'delete-task', 'task_id' => (int)$task['id'], 'clock_tech' => $tech, 'clock_month' => $month])) ?>'>Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function renderTaskAddForm($jobId) {
    $job = getJobById($jobId);
    if (!jobAcceptsTasks($job)) {
        echo '<div class="flash flash-error">This job is closed to new tasks</div>';
        return;
    }
    $techs = getTechNames();
    ?>
    <div class="panel">
        <form class="form-stack" hx-post="admin.php" hx-target="#content">
            <input type="hidden" name="action" value="add-task">
            <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
            <div class="panel-title"><label>Add Task (work reported outside the tech app)</label></div>
            <div class="form-row">
                <label>Tech
                    <input type="text" name="tech_name" list="tech-names" required autofocus>
                    <datalist id="tech-names">
                        <?php foreach ($techs as $tech): ?>
                        <option value="<?= h($tech) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label>Start Date
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>Start Time
                    <input type="time" name="start_time" required>
                </label>
                <label>End Date
                    <input type="date" name="end_date">
                </label>
                <label>End Time
                    <input type="time" name="end_time">
                </label>
            </div>
            <label>Notes
                <textarea name="notes" rows="4" placeholder="Notes:&#10;- &#10;&#10;Materials:&#10;- "></textarea>
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm">Add Task</button>
                <button type="button" class="btn btn-ghost btn-sm" hx-post="admin.php" hx-target="#content"
                    hx-vals='{"action":"view-job","id":<?= (int)$job['id'] ?>}'>Cancel</button>
            </div>
        </form>
    </div>
    <?php
}

function renderTaskEditForm($taskId, $clockTech = '', $clockMonth = '') {
    $task = getTaskById($taskId);
    if (!$task) {
        echo '<div class="flash flash-error">Task not found</div>';
        return;
    }
    $startTs = strtotime($task['start_time']);
    $endTs = $task['end_time'] ? strtotime($task['end_time']) : null;
    $isClock = (bool)$task['job_is_system'];
    ?>
    <div class="timeline-item timeline-editing">
        <form class="form-stack" hx-post="admin.php" hx-target="#content">
            <input type="hidden" name="action" value="save-task">
            <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
            <?php if ($isClock): // return to the filtered clock view after saving ?>
            <input type="hidden" name="clock_tech" value="<?= h($clockTech) ?>">
            <input type="hidden" name="clock_month" value="<?= h($clockMonth) ?>">
            <?php endif; ?>
            <div class="form-row">
                <label>Tech
                    <input type="text" name="tech_name" value="<?= h($task['tech_name']) ?>" required>
                </label>
                <label>Start Date
                    <input type="date" name="start_date" value="<?= date('Y-m-d', $startTs) ?>" required>
                </label>
                <label>Start Time
                    <input type="time" name="start_time" value="<?= date('H:i', $startTs) ?>" required>
                </label>
                <label>End Date
                    <input type="date" name="end_date" value="<?= $endTs ? date('Y-m-d', $endTs) : '' ?>">
                </label>
                <label>End Time
                    <input type="time" name="end_time" value="<?= $endTs ? date('H:i', $endTs) : '' ?>">
                </label>
            </div>
            <label>Notes
                <textarea name="notes" rows="6"><?= h($task['notes']) ?></textarea>
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm">Save Task</button>
                <?php if ($isClock): ?>
                <button type="button" class="btn btn-ghost btn-sm" hx-post="admin.php" hx-target="#content"
                    hx-vals='<?= h(json_encode(['action' => 'view-clock', 'tech' => $clockTech, 'month' => $clockMonth])) ?>'>Cancel</button>
                <?php else: ?>
                <button type="button" class="btn btn-ghost btn-sm" hx-post="admin.php" hx-target="#content"
                    hx-vals='{"action":"view-job","id":<?= (int)$task['job_id'] ?>}'>Cancel</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php
}

function renderLogCallForm($message = null, $isError = false, $old = []) {
    $val = function ($key) use ($old, $isError) {
        return $isError ? h($old[$key] ?? '') : '';
    };
    ?>
    <div class="view-header"><h2>Log a Service Call</h2></div>
    <?= flash($message, $isError) ?>
    <form class="panel form-stack" hx-post="admin.php" hx-target="#content">
        <input type="hidden" name="action" value="log-call">
        <label>Customer Name *
            <input type="text" name="customer_name" value="<?= $val('customer_name') ?>" required autofocus>
        </label>
        <label>Location / Address *
            <input type="text" name="location" value="<?= $val('location') ?>" required
                placeholder="e.g. 123 Main St">
        </label>
        <p class="muted hint">Customer name + location becomes the official job name techs will see.</p>
        <label>Phone Number
            <input type="tel" name="phone" value="<?= $val('phone') ?>">
        </label>
        <label>Call Notes
            <textarea name="call_notes" rows="5" placeholder="What does the customer need?"><?= $val('call_notes') ?></textarea>
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Open Job</button>
        </div>
    </form>
    <p class="muted">Standalone version of this form for the office: <a href="log-call.php">log-call.php</a></p>
    <?php
}

function renderReports() {
    $months = reportJobsPerMonth();
    $techs = getTechNames();
    $taskMonths = getTaskMonths();
    $currentMonth = date('Y-m');
    ?>
    <div class="view-header"><h2>Reports</h2></div>

    <div class="panel">
        <div class="panel-title">
            <label>Jobs Completed per Month</label>
            <?php if (!empty($months)): ?>
            <form method="post" action="admin.php" class="inline-form">
                <input type="hidden" name="action" value="export-months-csv">
                <input type="hidden" name="token" value="">
                <button type="submit" class="btn btn-ghost btn-xs">CSV ↓</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (empty($months)): ?>
            <p class="empty-state">No completed jobs yet. Jobs appear here once marked ready for billing.</p>
        <?php else: ?>
        <table class="report-table">
            <thead><tr><th>Month</th><th>Jobs</th><th>Ready</th><th>Billed</th><th>Paid</th><th>Hours</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($months as $m): ?>
                <tr>
                    <td><?= h(fmtMonth($m['month'])) ?></td>
                    <td><?= (int)$m['job_count'] ?></td>
                    <td><?= (int)$m['ready_count'] ?></td>
                    <td><?= (int)$m['billed_count'] ?></td>
                    <td><?= (int)$m['paid_count'] ?></td>
                    <td><?= fmtHours($m['total_hours']) ?></td>
                    <td><button class="btn btn-ghost btn-xs" hx-post="admin.php" hx-target="#month-detail"
                        hx-vals='{"action":"report-month","month":"<?= h($m['month']) ?>"}'>Details</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <div id="month-detail"></div>
    </div>

    <div class="panel">
        <div class="panel-title"><label>Tasks by Tech</label></div>
        <form class="form-row report-controls" hx-post="admin.php" hx-target="#tech-report" hx-trigger="change">
            <input type="hidden" name="action" value="report-tech">
            <label>Tech
                <select name="tech">
                    <option value="">Pick a tech…</option>
                    <?php foreach ($techs as $tech): ?>
                    <option value="<?= h($tech) ?>"><?= h($tech) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Month
                <select name="month">
                    <?php foreach ($taskMonths as $taskMonth): ?>
                    <option value="<?= h($taskMonth) ?>" <?= $taskMonth === $currentMonth ? 'selected' : '' ?>><?= h(fmtMonth($taskMonth)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <div id="tech-report"></div>
    </div>

    <div class="panel">
        <div class="panel-title"><label>Customer Lookup</label></div>
        <input type="search" id="customer-search" placeholder="Type a customer name…" autocomplete="off"
            hx-post="admin.php" hx-target="#customer-results" hx-trigger="keyup changed delay:200ms, search"
            hx-vals='{"action":"customer-search"}' name="q">
        <div id="customer-results"></div>
        <div id="customer-jobs"></div>
    </div>
    <?php
}

// Matching customers as a pick list (fed by the search box as you type)
function renderCustomerSearchResults($query) {
    if (trim($query) === '') {
        echo '<p class="muted hint">Start typing to find a customer.</p>';
        return;
    }
    $customers = searchCustomers($query);
    if (empty($customers)) {
        echo '<p class="empty-state">No matching customers.</p>';
        return;
    }
    ?>
    <div class="customer-matches">
        <?php foreach ($customers as $c): ?>
        <button class="pill" hx-post="admin.php" hx-target="#customer-jobs"
            hx-vals='<?= h(json_encode(['action' => 'customer-jobs', 'customer' => $c['customer']])) ?>'>
            <?= h($c['customer']) ?> <span class="muted">(<?= (int)$c['job_count'] ?>)</span>
        </button>
        <?php endforeach; ?>
    </div>
    <?php
}

// All jobs for one customer, newest first
function renderCustomerJobs($customer) {
    if (trim($customer) === '') {
        return;
    }
    $jobs = getJobsForCustomer($customer);
    $totalHours = 0;
    foreach ($jobs as $job) {
        $totalHours += (float)($job['total_hours'] ?? 0);
    }
    ?>
    <h4><?= h($customer) ?> — <?= count($jobs) ?> job<?= count($jobs) === 1 ? '' : 's' ?>, <?= fmtHours($totalHours) ?></h4>
    <?php if (empty($jobs)): ?>
        <p class="empty-state">No jobs for this customer.</p>
        <?php return; ?>
    <?php endif; ?>
    <table class="report-table">
        <thead><tr><th>Job</th><th>Status</th><th>Opened</th><th>Tasks</th><th>Hours</th></tr></thead>
        <tbody>
            <?php foreach ($jobs as $job): ?>
            <tr class="clickable" hx-post="admin.php" hx-target="#content" hx-swap="innerHTML show:window:top"
                hx-vals='<?= h(json_encode(['action' => 'view-job', 'id' => (int)$job['id']])) ?>'>
                <td><?= h($job['name']) ?></td>
                <td><?= statusBadge($job['status']) ?></td>
                <td><?= h(fmtDate($job['opened_at'])) ?></td>
                <td><?= (int)$job['task_count'] ?></td>
                <td><?= fmtHours($job['total_hours']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function renderMonthDetail($month) {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo '<div class="flash flash-error">Invalid month</div>';
        return;
    }
    $jobs = reportJobsForMonth($month);
    ?>
    <h4>Jobs completed in <?= h(fmtMonth($month)) ?>
        <form method="post" action="admin.php" class="inline-form">
            <input type="hidden" name="action" value="export-month-csv">
            <input type="hidden" name="token" value="">
            <input type="hidden" name="month" value="<?= h($month) ?>">
            <button type="submit" class="btn btn-ghost btn-xs">CSV ↓</button>
        </form>
    </h4>
    <table class="report-table">
        <thead><tr><th>Job</th><th>Status</th><th>Completed</th><th>Tasks</th><th>Hours</th></tr></thead>
        <tbody>
            <?php foreach ($jobs as $job): ?>
            <tr class="clickable" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"view-job","id":<?= (int)$job['id'] ?>}'>
                <td><?= h($job['name']) ?></td>
                <td><?= statusBadge($job['status']) ?></td>
                <td><?= h(fmtDate($job['ready_for_billing_at'])) ?></td>
                <td><?= (int)$job['task_count'] ?></td>
                <td><?= fmtHours($job['total_hours']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function renderTechReport($tech, $month) {
    if ($tech === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo '<p class="muted">Pick a tech and month to see their tasks.</p>';
        return;
    }
    $tasks = reportTasksForTech($tech, $month);
    $totalHours = 0;
    foreach ($tasks as $task) {
        $totalHours += (float)($task['hours'] ?? 0);
    }
    ?>
    <h4><?= h($tech) ?> — <?= h(fmtMonth($month)) ?> (<?= count($tasks) ?> tasks, <?= fmtHours($totalHours) ?>)</h4>
    <?php if (empty($tasks)): ?>
        <p class="empty-state">No tasks for this tech in this month.</p>
        <?php return; ?>
    <?php endif; ?>
    <table class="report-table">
        <thead><tr><th>Job</th><th>Start</th><th>End</th><th>Hours</th><th>Notes</th></tr></thead>
        <tbody>
            <?php foreach ($tasks as $task): ?>
            <tr>
                <td><?= h($task['job_name']) ?></td>
                <td><?= h(fmtDt($task['start_time'])) ?></td>
                <td><?= $task['end_time'] ? h(fmtDt($task['end_time'])) : '<em>in progress</em>' ?></td>
                <td><?= fmtHours($task['hours']) ?></td>
                <td class="notes-cell"><?= $task['notes'] ? h(mb_strimwidth($task['notes'], 0, 160, '…')) : '' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" action="admin.php" class="csv-form">
        <input type="hidden" name="action" value="export-tech-csv">
        <input type="hidden" name="token" value="">
        <input type="hidden" name="tech" value="<?= h($tech) ?>">
        <input type="hidden" name="month" value="<?= h($month) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Export CSV</button>
    </form>
    <?php
}

function csvFilename($base) {
    return preg_replace('/[^A-Za-z0-9_.-]+/', '_', $base);
}

// Monthly jobs-completed summary as CSV
function exportMonthsCsv() {
    $months = reportJobsPerMonth();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jobs-per-month.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Month', 'Jobs Completed', 'Ready for Billing', 'Billed', 'Paid', 'Hours']);
    foreach ($months as $m) {
        fputcsv($out, [
            $m['month'], $m['job_count'], $m['ready_count'], $m['billed_count'], $m['paid_count'],
            $m['total_hours'] !== null ? round((float)$m['total_hours'], 2) : '',
        ]);
    }
    fclose($out);
}

// Jobs completed in one month as CSV
function exportMonthCsv($month) {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        http_response_code(400);
        echo 'Invalid month';
        return;
    }
    $jobs = reportJobsForMonth($month);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jobs-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Job', 'Customer', 'Phone', 'Status', 'Opened', 'Ready for Billing', 'Tasks', 'Hours']);
    foreach ($jobs as $job) {
        fputcsv($out, [
            $job['name'], $job['customer_name'], $job['phone'], STATUS_LABELS[$job['status']] ?? $job['status'],
            $job['opened_at'], $job['ready_for_billing_at'], $job['task_count'],
            $job['total_hours'] !== null ? round((float)$job['total_hours'], 2) : '',
        ]);
    }
    fclose($out);
}

// Full job export: structured data for feeding to an AI or other tools
function jobExportData($jobId) {
    $job = getJobById($jobId);
    if (!$job) {
        return null;
    }
    $tasks = getTasksForJob($jobId);
    $totalHours = 0;
    foreach ($tasks as $task) {
        $totalHours += (float)($task['hours'] ?? 0);
    }
    return [
        'job' => [
            'id' => (int)$job['id'],
            'name' => $job['name'],
            'status' => $job['status'],
            'status_label' => STATUS_LABELS[$job['status']] ?? $job['status'],
            'customer_name' => $job['customer_name'],
            'phone' => $job['phone'],
            'call_notes' => $job['call_notes'],
            'admin_notes' => $job['admin_notes'],
            'opened_at' => $job['opened_at'],
            'ready_for_billing_at' => $job['ready_for_billing_at'],
            'billed_at' => $job['billed_at'],
            'paid_at' => $job['paid_at'],
            'closed_at' => $job['closed_at'],
        ],
        'tasks' => array_map(function ($task) {
            return [
                'tech_name' => $task['tech_name'],
                'start_time' => $task['start_time'],
                'end_time' => $task['end_time'],
                'hours' => $task['hours'] !== null ? round((float)$task['hours'], 2) : null,
                'notes' => $task['notes'],
            ];
        }, $tasks),
        'summary' => [
            'task_count' => count($tasks),
            'total_hours' => round($totalHours, 2),
        ],
    ];
}

function exportJobJson($jobId) {
    $data = jobExportData($jobId);
    if (!$data) {
        http_response_code(404);
        echo 'Job not found';
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . csvFilename('job-' . $jobId . '-' . $data['job']['name']) . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function exportJobMarkdown($jobId) {
    $data = jobExportData($jobId);
    if (!$data) {
        http_response_code(404);
        echo 'Job not found';
        return;
    }
    $job = $data['job'];

    $md = "# {$job['name']}\n\n";
    $md .= "- **Status:** {$job['status_label']}\n";
    if ($job['customer_name']) $md .= "- **Customer:** {$job['customer_name']}\n";
    if ($job['phone']) $md .= "- **Phone:** {$job['phone']}\n";
    $md .= "- **Opened:** " . fmtDt($job['opened_at']) . "\n";
    if ($job['ready_for_billing_at']) $md .= "- **Ready for billing:** " . fmtDate($job['ready_for_billing_at']) . "\n";
    if ($job['billed_at']) $md .= "- **Billed:** " . fmtDate($job['billed_at']) . "\n";
    if ($job['paid_at']) $md .= "- **Paid:** " . fmtDate($job['paid_at']) . "\n";
    if ($job['closed_at']) $md .= "- **Closed:** " . fmtDate($job['closed_at']) . "\n";
    $md .= "- **Work:** {$data['summary']['task_count']} task(s), {$data['summary']['total_hours']} hours\n";

    if ($job['call_notes']) {
        $md .= "\n## Call Notes\n\n{$job['call_notes']}\n";
    }
    if ($job['admin_notes']) {
        $md .= "\n## Job Notes\n\n{$job['admin_notes']}\n";
    }

    $md .= "\n## Task Log\n";
    if (empty($data['tasks'])) {
        $md .= "\n_No tasks recorded._\n";
    }
    foreach ($data['tasks'] as $task) {
        $when = fmtDt($task['start_time']);
        $when .= $task['end_time']
            ? ' → ' . fmtDt($task['end_time']) . " ({$task['hours']} h)"
            : ' — in progress';
        $md .= "\n### {$task['tech_name']} — $when\n";
        if ($task['notes']) {
            $md .= "\n{$task['notes']}\n";
        }
    }

    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . csvFilename('job-' . $jobId . '-' . $job['name']) . '.md"');
    echo $md;
}

function exportTechCsv($tech, $month) {
    if ($tech === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        http_response_code(400);
        echo 'Invalid tech or month';
        return;
    }
    $tasks = reportTasksForTech($tech, $month);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tasks-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $tech) . "-$month.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tech', 'Job', 'Start', 'End', 'Hours', 'Notes']);
    foreach ($tasks as $task) {
        fputcsv($out, [
            $task['tech_name'],
            $task['job_name'],
            $task['start_time'],
            $task['end_time'],
            $task['hours'] !== null ? round((float)$task['hours'], 2) : '',
            $task['notes'],
        ]);
    }
    fclose($out);
}

// ---------------------------------------------------------------------------
// Page shell (GET)
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HandyManager Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/htmx.org@2.0.4/dist/htmx.min.js" crossorigin="anonymous"></script>
    <style>
        :root {
            --bg: #f4f5f7;
            --surface: #ffffff;
            --border: #e2e4e8;
            --text: #1c2024;
            --muted: #6b7280;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --danger: #dc2626;
            --radius: 10px;
        }

        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-size: 15px;
            line-height: 1.5;
        }

        a { color: var(--primary); }

        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .topbar .brand {
            font-weight: 700;
            font-size: 17px;
        }

        nav { display: flex; gap: 4px; flex-wrap: wrap; }

        nav button {
            background: none;
            border: none;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 500;
            color: var(--muted);
            cursor: pointer;
        }

        nav button:hover { background: var(--bg); color: var(--text); }
        nav button.active { background: var(--primary); color: white; }

        .topbar .spacer { flex: 1; }

        .ext-link {
            color: var(--muted);
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            padding: 6px 8px;
        }

        .ext-link:hover { color: var(--primary); text-decoration: underline; }

        /* Right-side items: inline on desktop, dropdown behind ⋮ on mobile */
        .topbar-menu { display: flex; align-items: center; gap: 4px; }
        #menu-toggle { display: none; font-size: 18px; line-height: 1; padding: 4px 12px; }

        @media (max-width: 700px) {
            /* Two compact rows: brand + ⋮ on the first, nav tabs in a single
               scrollable strip on the second */
            .topbar { gap: 8px; }
            .topbar .spacer { display: none; }

            #menu-toggle { display: inline-block; order: 1; margin-left: auto; }

            nav {
                order: 2;
                width: 100%;
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            nav::-webkit-scrollbar { display: none; }
            nav button { white-space: nowrap; flex-shrink: 0; }

            .topbar-menu {
                display: none;
                position: absolute;
                top: 100%;
                right: 10px;
                flex-direction: column;
                align-items: stretch;
                gap: 2px;
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: 10px;
                box-shadow: 0 6px 16px rgba(0,0,0,.12);
                padding: 8px;
                z-index: 30;
                min-width: 160px;
            }

            .topbar-menu.open { display: flex; }
            .topbar-menu .ext-link { padding: 10px 12px; }
            .topbar-menu .btn { text-align: left; }
        }

        .wrap { max-width: 960px; margin: 0 auto; padding: 24px 20px 64px; }

        .view-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        h2 { margin: 0; font-size: 22px; }
        h3 { margin: 28px 0 12px; font-size: 17px; }
        h4 { margin: 20px 0 10px; font-size: 15px; }

        .muted { color: var(--muted); font-size: 13px; }
        .hint { margin: -6px 0 6px; }

        .empty-state { color: var(--muted); padding: 24px 0; text-align: center; }

        /* Job cards */
        .job-cards { display: flex; flex-direction: column; gap: 10px; }

        .job-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            cursor: pointer;
            transition: border-color .12s, box-shadow .12s;
        }

        .job-card:hover { border-color: var(--primary); box-shadow: 0 2px 8px rgba(37,99,235,.10); }

        .job-card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .job-card-name { font-weight: 600; font-size: 15px; }

        .job-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 16px;
            color: var(--muted);
            font-size: 13px;
            margin-top: 4px;
        }

        .job-card-notes {
            margin-top: 8px;
            padding: 8px 10px;
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            border-radius: 4px;
            font-size: 13px;
            white-space: pre-wrap;
        }

        /* Status badges */
        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-open { background: #dbeafe; color: #1e40af; }
        .badge-in_progress { background: #fef3c7; color: #92400e; }
        .badge-on_hold { background: #e5e7eb; color: #374151; }
        .badge-ready_for_billing { background: #ede9fe; color: #5b21b6; }
        .badge-billed { background: #cffafe; color: #155e75; }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-closed { background: #fee2e2; color: #991b1b; }

        /* Filter pills (tab sub-filters) and customer match chips */
        .filter-pills, .customer-matches {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
        }
        .customer-matches { margin-top: 10px; }

        .pill {
            font: inherit;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            border-radius: 999px;
            padding: 5px 14px;
            cursor: pointer;
        }

        .pill:hover { border-color: var(--primary); color: var(--primary); }
        .pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }

        /* Status buttons on job cards */
        .job-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }

        #customer-search { max-width: 360px; }

        /* Panels & forms */
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin: 14px 0;
        }

        .panel-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .panel-title label { font-weight: 600; }

        .saved-tag { color: #16a34a; font-size: 13px; font-weight: 600; }

        .form-stack { display: flex; flex-direction: column; gap: 12px; }
        .form-stack label, .form-row label { display: flex; flex-direction: column; gap: 4px; font-weight: 600; font-size: 13px; }
        .form-row { display: flex; flex-wrap: wrap; gap: 12px; }
        .form-row label { flex: 1; min-width: 130px; }
        .form-actions { display: flex; gap: 8px; }

        input, textarea, select {
            font: inherit;
            padding: 9px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--surface);
            width: 100%;
        }

        input:focus, textarea:focus, select:focus {
            outline: 2px solid rgba(37,99,235,.35);
            border-color: var(--primary);
        }

        textarea { resize: vertical; }

        /* Buttons */
        .btn {
            font: inherit;
            font-weight: 600;
            border: 1px solid transparent;
            border-radius: 6px;
            padding: 9px 16px;
            cursor: pointer;
        }

        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-xs { padding: 3px 9px; font-size: 12px; }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }

        .btn-ghost { background: var(--surface); border-color: var(--border); color: var(--text); }
        .btn-ghost:hover { border-color: var(--muted); }

        .btn-danger { background: var(--surface); border-color: var(--border); color: var(--danger); }
        .btn-danger:hover { background: #fef2f2; border-color: var(--danger); }

        /* Job detail */
        .job-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }

        .job-detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .status-dates {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 18px;
            color: var(--muted);
            font-size: 13px;
            margin: 10px 0;
        }

        /* Timeline */
        .timeline {
            display: flex;
            flex-direction: column;
            gap: 10px;
            border-left: 2px solid var(--border);
            padding-left: 16px;
            margin-left: 6px;
        }

        .timeline-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 14px;
            position: relative;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -22px;
            top: 18px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary);
        }

        .timeline-call { border-left: 3px solid var(--primary); }
        .timeline-call::before { background: #16a34a; }

        .timeline-item-head {
            display: flex;
            gap: 6px 14px;
            align-items: baseline;
            flex-wrap: wrap;
        }

        .timeline-item-actions { margin-left: auto; display: flex; gap: 6px; }

        .timeline-item-body { margin-top: 6px; font-size: 14px; }

        pre.notes {
            font: inherit;
            white-space: pre-wrap;
            margin: 6px 0 0;
            padding: 8px 10px;
            background: var(--bg);
            border-radius: 6px;
        }

        .danger-zone { margin-top: 32px; padding-top: 16px; border-top: 1px dashed var(--border); }

        .timeline-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .timeline-header h3 { margin: 28px 0 12px; }

        /* Tables */
        .report-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .report-table th, .report-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); }
        .report-table th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .report-table tr.clickable { cursor: pointer; }
        .report-table tr.clickable:hover { background: var(--bg); }
        .notes-cell { color: var(--muted); font-size: 13px; max-width: 320px; }

        .report-controls label { max-width: 240px; }
        .csv-form { margin-top: 10px; }
        .inline-form { display: inline-block; margin: 0; }

        /* Flash messages */
        .flash {
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 14px;
            font-weight: 500;
        }

        .flash-ok { background: #dcfce7; color: #166534; }
        .flash-error { background: #fee2e2; color: #991b1b; }

        /* Token gate */
        #token-gate { max-width: 420px; margin: 64px auto; }

        .htmx-request { opacity: .6; transition: opacity .15s; }

        @media (max-width: 600px) {
            .wrap { padding: 16px 12px 48px; }
            .topbar { padding: 10px 12px; gap: 10px; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <span class="brand">HandyManager</span>
        <nav id="main-nav" style="display:none">
            <button data-nav="active" class="active" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"view-jobs","group":"active"}'>Active</button>
            <button data-nav="billing" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"view-jobs","group":"billing"}'>Billing</button>
            <button data-nav="paid" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"view-jobs","group":"paid"}'>Paid</button>
            <button data-nav="clock" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"view-clock"}'>Clock</button>
            <button data-nav="log-call" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"log-call-form"}'>Log Call</button>
            <button data-nav="reports" hx-post="admin.php" hx-target="#content"
                hx-vals='{"action":"view-reports"}'>Reports</button>
        </nav>
        <span class="spacer"></span>
        <button id="menu-toggle" class="btn btn-ghost btn-sm" aria-label="Menu">⋮</button>
        <div id="topbar-menu" class="topbar-menu">
            <a class="ext-link" href="./" target="_blank">Tech App</a>
            <a class="ext-link" href="log-call.php" target="_blank">Call Log</a>
            <button id="logout-btn" class="btn btn-ghost btn-sm" style="display:none">Change Token</button>
        </div>
    </div>

    <div class="wrap">
        <div id="token-gate" class="panel form-stack" style="display:none">
            <h2>Admin Access</h2>
            <label>Admin Token
                <input type="password" id="token-input" placeholder="Enter admin token" autocomplete="current-password">
            </label>
            <button id="token-save" class="btn btn-primary">Continue</button>
        </div>
        <div id="content"></div>
    </div>

    <script>
        const TOKEN_KEY = 'handymanager_admin_token';
        const nav = document.getElementById('main-nav');
        const gate = document.getElementById('token-gate');
        const content = document.getElementById('content');
        const logoutBtn = document.getElementById('logout-btn');
        const menuToggle = document.getElementById('menu-toggle');
        const topbarMenu = document.getElementById('topbar-menu');

        // Mobile: the right-side links live behind the ⋮ dropdown
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            topbarMenu.classList.toggle('open');
        });
        document.addEventListener('click', function(e) {
            if (!topbarMenu.contains(e.target)) {
                topbarMenu.classList.remove('open');
            }
        });
        topbarMenu.addEventListener('click', function() {
            topbarMenu.classList.remove('open');
        });

        // Attach the token to every htmx request - the server checks it each time
        document.body.addEventListener('htmx:configRequest', function(e) {
            e.detail.parameters.token = localStorage.getItem(TOKEN_KEY) || '';
        });

        // An invalid/expired token sends us back to the gate
        document.body.addEventListener('htmx:responseError', function(e) {
            if (e.detail.xhr.status === 401) {
                showGate();
            } else {
                content.innerHTML = '<div class="flash flash-error">Request failed (' + e.detail.xhr.status + '). Please try again.</div>';
            }
        });

        // Highlight the active nav tab
        nav.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', function() {
                nav.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // The CSV export is a plain form POST; fill its token field at submit time
        document.body.addEventListener('submit', function(e) {
            const tokenField = e.target.querySelector('input[name="token"]');
            if (tokenField) tokenField.value = localStorage.getItem(TOKEN_KEY) || '';
        }, true);

        function showGate() {
            gate.style.display = '';
            nav.style.display = 'none';
            logoutBtn.style.display = 'none';
            content.innerHTML = '';
            document.getElementById('token-input').focus();
        }

        function showApp() {
            gate.style.display = 'none';
            nav.style.display = '';
            logoutBtn.style.display = '';
            htmx.ajax('POST', 'admin.php', { target: '#content', values: { action: 'view-jobs', group: 'active' } });
        }

        document.getElementById('token-save').addEventListener('click', saveToken);
        document.getElementById('token-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') saveToken();
        });

        function saveToken() {
            const token = document.getElementById('token-input').value.trim();
            if (!token) return;
            localStorage.setItem(TOKEN_KEY, token);
            showApp();
        }

        logoutBtn.addEventListener('click', function() {
            localStorage.removeItem(TOKEN_KEY);
            showGate();
        });

        if (localStorage.getItem(TOKEN_KEY)) {
            showApp();
        } else {
            showGate();
        }
    </script>
</body>
</html>
