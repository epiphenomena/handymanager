<?php
// admin-jobs.php - Admin endpoint to view all jobs with filtering

require_once 'config.php';

// Enable CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate admin token
    if (!isset($input['token']) || !verifyAdminToken($input['token'])) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid admin token']);
    }

    // Check if it's a delete action
    if (isset($input['action']) && $input['action'] === 'delete_job') {
        // Handle job deletion
        if (!isset($input['job_id'])) {
            sendJsonResponse(['success' => false, 'message' => 'Job ID is required']);
        }

        $jobId = $input['job_id'];

        // Delete the job from database
        if (deleteJob($jobId)) {
            sendJsonResponse(['success' => true, 'message' => 'Job deleted successfully']);
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Failed to delete job']);
        }
    } else {
        // Handle data export
        // Get filters from input
        $filters = [];
        if (isset($input['filters'])) {
            $filters = $input['filters'];
        }

        // Get jobs from database
        $jobs = getAllJobs($filters);

        sendJsonResponse(['success' => true, 'jobs' => $jobs]);
    }
}

// For GET requests, show the admin interface
// We'll show the interface but require token authentication for data access
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HandyManager Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            padding: 20px;
            background-color: #f8f9fa;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .filter-section {
            background-color: #fff;
            border-radius: 0.375rem;
            padding: 15px;
            margin-bottom: 20px;
        }
        .table-container {
            background-color: #fff;
            border-radius: 0.375rem;
            padding: 15px;
            overflow-x: scroll;
        }
        .status-badge {
            font-size: 0.75em;
        }
        .in-progress {
            background-color: #fff3cd;
            color: #856404;
        }
        .completed {
            background-color: #d4edda;
            color: #155724;
        }
        .settings-section {
            background-color: #fff;
            border-radius: 0.375rem;
            padding: 15px;
            margin-bottom: 20px;
        }
        .main-content {
            display: none;
        }
        .settings-btn {
            position: absolute;
            top: 20px;
            right: 20px;
        }
        /* Set widths for date/time columns */
        #jobsTable th:nth-child(1),
        #jobsTable td:nth-child(1) {
            width: 10ch;
            min-width: 10ch;
        }

        #jobsTable th:nth-child(2),
        #jobsTable td:nth-child(2) {
            width: 8ch;
            min-width: 8ch;
        }

        #jobsTable th:nth-child(3),
        #jobsTable td:nth-child(3) {
            width: 10ch;
            min-width: 10ch;
        }

        #jobsTable th:nth-child(4),
        #jobsTable td:nth-child(4) {
            width: 8ch;
            min-width: 8ch;
        }

        /* Set minimum width for location column */
        #jobsTable th:nth-child(7),
        #jobsTable td:nth-child(7) {
            min-width: 35ch;
        }

        /* Make textareas fill the cell vertically */
        #jobsTable textarea {
            height: calc(100% - 8px);
            resize: vertical;
            min-height: 60px;
        }

        /* Make inputs fill the cell vertically when in edit mode */
        #jobsTable .editing-row input,
        #jobsTable .editing-row textarea {
            height: calc(100% - 8px);
        }

        /* Ensure adequate spacing for buttons */
        .button-cell {
            min-width: 180px;
            white-space: nowrap;
        }

        /* Style for buttons in edit mode */
        .btn-group-edit {
            display: flex;
            gap: 0.25rem;
        }

        /* Expanded row height in edit mode */
        .editing-row {
            height: 300px !important;
        }

        .editing-row td {
            height: 300px !important;
            vertical-align: top;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">HandyManager Admin Dashboard</h1>

                <!-- Settings Section -->
                <div class="settings-section mb-4" id="settingsSection">
                    <h4>Settings</h4>
                    <div class="mb-3">
                        <label for="adminToken" class="form-label">Admin Token</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="adminToken" placeholder="Enter admin token">
                            <button class="btn btn-outline-secondary" type="button" id="toggleTokenVisibility">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Enter your admin token to access the dashboard. Token will be saved in browser storage.</div>
                    </div>
                    <button type="button" class="btn btn-primary" id="saveSettings">Save Settings</button>
                </div>

                <!-- Main Content (initially hidden) -->
                <div class="main-content" id="mainContent">
                    <button type="button" class="btn btn-secondary settings-btn" id="showSettings">
                        <i class="bi bi-gear"></i> Settings
                    </button>

                    <div class="filter-section mb-4">
                        <h4>Filters</h4>
                        <form id="filterForm">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="dateFrom" class="form-label">Date From</label>
                                    <input type="date" class="form-control" id="dateFrom">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="dateTo" class="form-label">Date To</label>
                                    <input type="date" class="form-control" id="dateTo">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="techFilter" class="form-label">Tech</label>
                                    <select class="form-select" id="techFilter">
                                        <option value="">All Techs</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="locationFilter" class="form-label">Location</label>
                                    <select class="form-select" id="locationFilter" multiple>
                                        <option value="" selected>All Locations</option>
                                    </select>
                                    <div class="form-text">Hold Ctrl/Cmd to select multiple locations</div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <button type="button" class="btn btn-secondary" id="clearFilters">Clear Filters</button>
                                    <button type="button" class="btn btn-success float-end" id="exportCsv">Export to CSV</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="table-container">
                        <table class="table table-striped table-hover" id="jobsTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Start Date</th>
                                    <th>Start Time</th>
                                    <th>End Date</th>
                                    <th>End Time</th>
                                    <th>Duration (Hours)</th>
                                    <th>Tech Name</th>
                                    <th>Location</th>
                                    <th>Notes</th>
                                    <th>Edit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data will be populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // DOM elements
        const settingsSection = document.getElementById('settingsSection');
        const mainContent = document.getElementById('mainContent');
        const adminTokenInput = document.getElementById('adminToken');
        const toggleTokenVisibilityBtn = document.getElementById('toggleTokenVisibility');
        const saveTokenCheckbox = document.getElementById('saveToken');
        const saveSettingsBtn = document.getElementById('saveSettings');
        const showSettingsBtn = document.getElementById('showSettings');
        const filterForm = document.getElementById('filterForm');
        const dateFromInput = document.getElementById('dateFrom');
        const dateToInput = document.getElementById('dateTo');
        const techFilter = document.getElementById('techFilter');
        const locationFilter = document.getElementById('locationFilter');
        const clearFiltersBtn = document.getElementById('clearFilters');
        const exportCsvBtn = document.getElementById('exportCsv');
        const jobsTableBody = document.querySelector('#jobsTable tbody');

        // Toggle token visibility
        toggleTokenVisibilityBtn.addEventListener('click', function() {
            if (adminTokenInput.type === 'password') {
                adminTokenInput.type = 'text';
                toggleTokenVisibilityBtn.innerHTML = '<i class="bi bi-eye-slash"></i>';
            } else {
                adminTokenInput.type = 'password';
                toggleTokenVisibilityBtn.innerHTML = '<i class="bi bi-eye"></i>';
            }
        });

        // Show settings section
        function showSettingsSection() {
            settingsSection.style.display = 'block';
            mainContent.style.display = 'none';
        }

        // Show main content
        function showMainContent() {
            settingsSection.style.display = 'none';
            mainContent.style.display = 'block';
        }

        // Format date without seconds and year in 12-hour format
        function formatDateWithoutSecondsOrYear(date) {
            // Format: MM/DD h:MM AM/PM (12-hour format)
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            let hours = date.getHours();
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12; // Convert 0 to 12
            return `${month}/${day} ${hours}:${minutes} ${ampm}`;
        }

        // Show settings button event
        showSettingsBtn.addEventListener('click', function() {
            showSettingsSection();
        });

        // Load saved token if available
        document.addEventListener('DOMContentLoaded', function() {
            const savedToken = localStorage.getItem('handymanager_admin_token');
            if (savedToken) {
                adminTokenInput.value = savedToken;
                showMainContent();
                verifyTokenWithServer(savedToken); // This will load filters and jobs
            } else {
                showSettingsSection();
            }
        });

        // Save settings
        saveSettingsBtn.addEventListener('click', function() {
            const token = adminTokenInput.value.trim();
            if (!token) {
                alert('Please enter an admin token');
                return;
            }

            // Always save token in browser storage
            localStorage.setItem('handymanager_admin_token', token);

            // Verify token with server
            verifyTokenWithServer(token);
        });

        // Verify token with server
        async function verifyTokenWithServer(token) {
            try {
                const response = await fetch('admin-jobs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        token: token,
                        filters: {}
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Token is valid - always save it
                    localStorage.setItem('handymanager_admin_token', token);

                    showMainContent();
                    loadFilterOptions(data); // Pass the data to loadFilterOptions
                    loadJobs({}, data); // Pass the data to loadJobs
                } else {
                    alert('Invalid admin token: ' + data.message);
                }
            } catch (error) {
                console.error('Error verifying token:', error);
                alert('Error verifying token');
            }
        }

        // Load filter options (techs and locations) from the provided data
        function loadFilterOptions(data) {
            try {
                // Clear existing options except the first one (default)
                while (techFilter.options.length > 1) {
                    techFilter.remove(1);
                }
                while (locationFilter.options.length > 1) {
                    locationFilter.remove(1);
                }

                // Extract unique techs and locations from the jobs data
                const techs = [...new Set(data.jobs.map(job => job.tech_name).filter(Boolean))].sort();
                const locations = [...new Set(data.jobs.map(job => job.location).filter(Boolean))].sort();

                // Populate techs dropdown
                techs.forEach(tech => {
                    const option = document.createElement('option');
                    option.value = tech;
                    option.textContent = tech;
                    techFilter.appendChild(option);
                });

                // Populate locations dropdown
                locations.forEach(location => {
                    const option = document.createElement('option');
                    option.value = location;
                    option.textContent = location;
                    locationFilter.appendChild(option);
                });
            } catch (error) {
                console.error('Error loading filter options:', error);
            }
        }

        // Load jobs with current filters
        async function loadJobs(filters = {}, initialData = null) {
            // If we have initial data and no filters, use the initial data
            if (initialData && Object.keys(filters).length === 0) {
                renderJobsTable(initialData.jobs);
                return;
            }

            const token = localStorage.getItem('handymanager_admin_token');
            if (!token) {
                alert('Admin token not found. Please re-authenticate.');
                showSettingsSection();
                return;
            }

            try {
                const response = await fetch('admin-jobs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        token: token,
                        filters: filters
                    })
                });

                const data = await response.json();

                if (data.success) {
                    renderJobsTable(data.jobs);
                } else {
                    alert('Error loading jobs: ' + data.message);
                    if (data.message.includes('Invalid admin token')) {
                        // Token is no longer valid, show settings again
                        localStorage.removeItem('handymanager_admin_token');
                        showSettingsSection();
                    }
                }
            } catch (error) {
                console.error('Error loading jobs:', error);
                alert('Error loading jobs');
            }
        }

        // Track if a cell is currently being edited
        let currentlyEditingCell = null;

        // Enter edit mode for a row
        function enterEditMode(row, jobId) {
            // Prevent editing if another row is currently being edited
            if (currentlyEditingCell) {
                alert('Please save or cancel changes before editing another row.');
                return;
            }

            // Mark that we're currently editing this row
            currentlyEditingCell = row;

            // Add class to expand row height
            row.classList.add('editing-row');

            // Get all editable cells in the row and make them editable
            const editableCells = row.querySelectorAll('.editable-cell');
            const originalValues = {};

            editableCells.forEach(cell => {
                const field = cell.getAttribute('data-field');
                const currentValue = cell.textContent;

                originalValues[field] = currentValue;

                let input;
                if (field === 'notes') {
                    // Create textarea for notes field
                    input = document.createElement('textarea');
                    input.className = 'form-control form-control-sm';
                    input.style.height = '100%';
                    input.style.resize = 'vertical';
                    input.value = currentValue;
                } else if (field === 'start_date' || field === 'end_date') {
                    // Create date input for date fields
                    input = document.createElement('input');
                    input.type = 'date';
                    input.className = 'form-control form-control-sm';
                    // Convert the displayed date to YYYY-MM-DD format for the date input
                    if (currentValue) {
                        const date = new Date(currentValue);
                        if (!isNaN(date.getTime())) {
                            input.value = date.toISOString().split('T')[0];
                        } else {
                            // If it's already in YYYY-MM-DD format, use as is
                            input.value = currentValue;
                        }
                    }
                } else if (field === 'start_time' || field === 'end_time') {
                    // Create time input for time fields
                    input = document.createElement('input');
                    input.type = 'time';
                    input.className = 'form-control form-control-sm';

                    // The currentValue is in 12-hour format (e.g. "2:30 PM"), convert to 24-hour for the input
                    if (currentValue) {
                        input.value = convert12HourTo24Hour(currentValue);
                    }
                } else {
                    // Create input element for other fields
                    input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control form-control-sm';
                    input.value = currentValue;
                }

                input.setAttribute('data-original-value', currentValue); // Store original value

                // Replace cell content with input
                cell.innerHTML = '';
                cell.appendChild(input);
            });

            // Store original values on the row element
            row.setAttribute('data-original-values', JSON.stringify(originalValues));

            // Change the edit button to save, cancel, and delete buttons
            const buttonCell = row.querySelector('td:last-child');
            buttonCell.className = 'button-cell';
            buttonCell.innerHTML = '';

            const buttonGroup = document.createElement('div');
            buttonGroup.className = 'btn-group-edit';

            const saveBtn = document.createElement('button');
            saveBtn.className = 'btn btn-success save-btn';
            saveBtn.innerHTML = 'Save';
            saveBtn.title = 'Save Changes';

            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn btn-secondary cancel-btn';
            cancelBtn.innerHTML = 'Cancel';
            cancelBtn.title = 'Cancel Changes';

            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'btn btn-danger delete-btn';
            deleteBtn.innerHTML = 'Delete?';
            deleteBtn.title = 'Delete Job (double-click to confirm)';

            buttonGroup.appendChild(saveBtn);
            buttonGroup.appendChild(cancelBtn);
            buttonGroup.appendChild(deleteBtn);
            buttonCell.appendChild(buttonGroup);

            // No change detection - save button is always enabled

            // Add listeners to the buttons
            saveBtn.onclick = function() {
                saveRowChanges(row, jobId);
            };

            cancelBtn.onclick = function() {
                cancelRowEditing(row);
            };

            deleteBtn.addEventListener('click', function() {
                if (this.classList.contains('btn-danger') && !this.classList.contains('confirm-delete')) {
                    // First click: change to "DELETE!" button
                    this.classList.remove('btn-danger');
                    this.classList.add('btn-warning', 'confirm-delete');
                    this.textContent = 'DELETE!';
                    this.title = 'Confirm Delete';
                } else if (this.classList.contains('confirm-delete')) {
                    // Second click: delete the job
                    deleteJob(jobId);
                }
            });
        }

        // Function to convert separate date and time values to ISO format
        function convertToISOString(dateStr, timeStr) {
            if (!dateStr) return null;

            // If we have time, combine date and time; otherwise just use date
            let isoString = dateStr;
            if (timeStr) {
                isoString += `T${timeStr}:00`;
            } else {
                isoString += "T00:00:00";
            }

            return isoString;
        }

        // Function to convert 12-hour time format to 24-hour format
        function convert12HourTo24Hour(time12h) {
            if (!time12h) return '';

            const [time, modifier] = time12h.split(' ');
            let [hours, minutes] = time.split(':');

            hours = parseInt(hours, 10);

            if (modifier === 'AM') {
                if (hours === 12) {
                    hours = 0;  // 12:XX AM is midnight, so 00:XX in 24-hour format
                }
            } else if (modifier === 'PM') {
                if (hours !== 12) {
                    hours += 12; // 1:XX PM to 11:XX PM becomes 13:XX to 23:XX
                }
                // 12:XX PM stays 12:XX in 24-hour format
            }

            return `${hours.toString().padStart(2, '0')}:${minutes}`;
        }

        // Save changes to the entire row
        async function saveRowChanges(row, jobId) {
            const token = localStorage.getItem('handymanager_admin_token');
            if (!token) {
                alert('Admin token not found. Please re-authenticate.');
                showSettingsSection();
                return;
            }

            try {
                // Prepare update request to update job
                const updatePayload = {
                    token: token,
                    job_id: jobId
                };

                // Process date and time fields to combine them properly
                const start_date_input = row.querySelector('td[data-field="start_date"] input');
                const start_time_input = row.querySelector('td[data-field="start_time"] input');
                const end_date_input = row.querySelector('td[data-field="end_date"] input');
                const end_time_input = row.querySelector('td[data-field="end_time"] input');
                console.log(start_date_input, start_time_input, end_date_input, end_time_input)

                // Handle start time changes
                if (start_date_input && start_time_input) {
                    const start_date_original = start_date_input.getAttribute('data-original-value');
                    const start_time_original = start_time_input.getAttribute('data-original-value');
                    const start_date_current = start_date_input.value;
                    const start_time_current = start_time_input.value;

                    updatePayload['start_time'] = convertToISOString(start_date_current, convert12HourTo24Hour(start_time_current));

                }

                // Handle end time changes
                if (end_date_input && end_time_input) {
                    const end_date_original = end_date_input.getAttribute('data-original-value');
                    const end_time_original = end_time_input.getAttribute('data-original-value');
                    const end_date_current = end_date_input.value;
                    const end_time_current = end_time_input.value;

                    updatePayload['end_time'] = convertToISOString(end_date_current, convert12HourTo24Hour(end_time_current));
                }

                // Process other fields (tech_name, location, notes)
                const inputs = row.querySelectorAll('input, textarea');
                inputs.forEach(input => {
                    const field = input.closest('.editable-cell').getAttribute('data-field');
                    const originalValue = input.getAttribute('data-original-value');

                    // Process non-date/time fields
                    if (field !== 'start_date' && field !== 'start_time' &&
                        field !== 'end_date' && field !== 'end_time') {
                        if (input.value !== originalValue) {
                            updatePayload[field] = input.value;
                        }
                    }
                });

                console.log("POSTING: ", updatePayload)

                const updateResponse = await fetch('update-job.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(updatePayload)
                });

                const updateData = await updateResponse.json();

                if (updateData.success) {
                    // Update all cells in the row
                    const inputs = row.querySelectorAll('input, textarea');
                    inputs.forEach(input => {
                        const cell = input.closest('.editable-cell');
                        const field = cell.getAttribute('data-field');

                        if (field === 'start_date' || field === 'end_date') {
                            // Convert date from YYYY-MM-DD to locale date string
                            if (input.value) {
                                const date = new Date(input.value);
                                cell.textContent = date.toLocaleDateString();
                            } else {
                                cell.textContent = '';
                            }
                        } else if (field === 'start_time' || field === 'end_time') {
                            // Format time back to 12-hour display format
                            const timeValue = input.value;
                            if (timeValue) {
                                const [hours, minutes] = timeValue.split(':');
                                const date = new Date();
                                date.setHours(parseInt(hours, 10));
                                date.setMinutes(parseInt(minutes, 10));
                                // Format as 12-hour time with AM/PM
                                cell.textContent = date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', hour12: true});
                            } else {
                                cell.textContent = '';
                            }
                        } else {
                            cell.textContent = input.value;
                        }
                    });

                    // Calculate and update duration
                    const startDateInput = row.querySelector('input[data-field="start_date"]');
                    const startTimeInput = row.querySelector('input[data-field="start_time"]');
                    const endDateInput = row.querySelector('input[data-field="end_date"]');
                    const endTimeInput = row.querySelector('input[data-field="end_time"]');

                    if (startDateInput && startTimeInput && endDateInput && endTimeInput) {
                        const startDateTime = new Date(`${startDateInput.value}T${startTimeInput.value}`);
                        const endDateTime = new Date(`${endDateInput.value}T${endTimeInput.value}`);

                        if (!isNaN(startDateTime) && !isNaN(endDateTime)) {
                            const diffMs = endDateTime - startDateTime;
                            const diffHours = diffMs / (1000 * 60 * 60);
                            const durationText = diffHours.toFixed(2);

                            // Duration is now in the 5th column (index 4)
                            const durationCell = row.cells[4];
                            if (durationCell) {
                                durationCell.textContent = durationText;
                            }
                        }
                    }

                    // Change buttons back to edit button
                    const buttonCell = row.querySelector('td:last-child');
                    buttonCell.innerHTML = '<button class="btn btn-primary edit-btn" data-job-id="' + jobId + '">Edit</button>';

                    // Add event listener to the new edit button
                    const editBtn = buttonCell.querySelector('.edit-btn');
                    editBtn.addEventListener('click', function() {
                        const jobId = this.getAttribute('data-job-id');
                        const currentRow = this.closest('tr');
                        enterEditMode(currentRow, jobId);
                    });

                    // Remove the expanded row class
                    row.classList.remove('editing-row');

                    // Clear the currently editing flag
                    currentlyEditingCell = null;
                } else {
                    alert('Error saving changes: ' + updateData.message);
                }
            } catch (error) {
                console.error('Error saving row changes:', error);
                alert('Error saving changes');
            }
        }

        // Cancel editing and revert to original state
        function cancelRowEditing(row) {
            // Revert all cells in the row to original values
            const originalValues = JSON.parse(row.getAttribute('data-original-values'));
            const editableCells = row.querySelectorAll('.editable-cell');

            editableCells.forEach(cell => {
                const field = cell.getAttribute('data-field');

                if (field === 'start_date' || field === 'end_date') {
                    // Convert date from YYYY-MM-DD to locale date string
                    if (originalValues[field]) {
                        const date = new Date(originalValues[field]);
                        cell.innerHTML = date.toLocaleDateString();
                    } else {
                        cell.innerHTML = '';
                    }
                } else if (field === 'start_time' || field === 'end_time') {
                    // Format time back to 12-hour display format from the original ISO value
                    const timeValue = originalValues[field];
                    if (timeValue) {
                        const date = new Date(timeValue);
                        if (!isNaN(date.getTime())) {
                            cell.innerHTML = date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', hour12: true});
                        } else {
                            // If it's already in 12-hour format, use as is
                            cell.innerHTML = timeValue;
                        }
                    } else {
                        cell.innerHTML = '';
                    }
                } else {
                    cell.innerHTML = originalValues[field];
                }
            });

            // Calculate and update duration when canceling
            if (originalValues['start_date'] && originalValues['start_time'] &&
                originalValues['end_date'] && originalValues['end_time']) {
                const startDateTime = new Date(`${originalValues['start_date']}T${originalValues['start_time']}`);
                const endDateTime = new Date(`${originalValues['end_date']}T${originalValues['end_time']}`);

                if (!isNaN(startDateTime) && !isNaN(endDateTime)) {
                    const diffMs = endDateTime - startDateTime;
                    const diffHours = diffMs / (1000 * 60 * 60);
                    const durationText = diffHours.toFixed(2);

                    // Duration is now in the 5th column (index 4)
                    const durationCell = row.cells[4];
                    if (durationCell) {
                        durationCell.textContent = durationText;
                    }
                }
            }

            // Get job ID
            const jobId = row.getAttribute('data-job-id');

            // Change buttons back to edit button
            const buttonCell = row.querySelector('td:last-child');
            buttonCell.innerHTML = '<button class="btn btn-primary edit-btn" data-job-id="' + jobId + '">Edit</button>';

            // Add event listener to the new edit button
            const editBtn = buttonCell.querySelector('.edit-btn');
            editBtn.addEventListener('click', function() {
                const clickedJobId = this.getAttribute('data-job-id');
                const currentRow = this.closest('tr');
                enterEditMode(currentRow, clickedJobId);
            });

            // Remove the expanded row class
            row.classList.remove('editing-row');

            // Clear the currently editing flag
            currentlyEditingCell = null;
        }

        // Render jobs in the table
        function renderJobsTable(jobs) {
            jobsTableBody.innerHTML = '';

            if (jobs.length === 0) {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="7" class="text-center">No jobs found</td>';
                jobsTableBody.appendChild(row);
                return;
            }

            jobs.forEach(job => {
                const row = document.createElement('tr');
                row.setAttribute('data-job-id', job.id);

                // Format dates without seconds and years
                const startDate = job.start_time ? formatDateWithoutSecondsOrYear(new Date(job.start_time)) : '';
                const endDate = job.end_time ? formatDateWithoutSecondsOrYear(new Date(job.end_time)) : '';

                // Calculate duration in hours (with 2 decimal precision)
                let duration = '';
                if (job.start_time && job.end_time) {
                    const start = new Date(job.start_time);
                    const end = new Date(job.end_time);
                    const diffMs = end - start;
                    const diffHours = diffMs / (1000 * 60 * 60);
                    duration = diffHours.toFixed(2);
                }

                // Format time in 12-hour format for display
                const formatTime12Hour = (isoString) => {
                    if (!isoString) return '';
                    const date = new Date(isoString);
                    if (isNaN(date.getTime())) return '';
                    return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', hour12: true});
                };

                row.innerHTML = `
                    <td class="editable-cell" data-field="start_date">${job.start_time ? new Date(job.start_time).toLocaleDateString() : ''}</td>
                    <td class="editable-cell" data-field="start_time">${formatTime12Hour(job.start_time)}</td>
                    <td class="editable-cell" data-field="end_date">${job.end_time ? new Date(job.end_time).toLocaleDateString() : ''}</td>
                    <td class="editable-cell" data-field="end_time">${formatTime12Hour(job.end_time)}</td>
                    <td>${duration}</td>
                    <td class="editable-cell" data-field="tech_name">${job.tech_name || ''}</td>
                    <td class="editable-cell" data-field="location">${job.location || ''}</td>
                    <td class="editable-cell" data-field="notes">${job.notes || ''}</td>
                    <td><button class="btn btn-primary edit-btn" data-job-id="${job.id}">Edit</button></td>
                `;

                jobsTableBody.appendChild(row);
            });

            // Add event listeners for edit buttons
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const jobId = this.getAttribute('data-job-id');
                    const row = this.closest('tr');
                    enterEditMode(row, jobId);
                });
            });
        }



        // Delete job function
        async function deleteJob(jobId) {
            const token = localStorage.getItem('handymanager_admin_token');
            if (!token) {
                alert('Admin token not found. Please re-authenticate.');
                showSettingsSection();
                return;
            }

            try {
                const response = await fetch('admin-jobs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        token: token,
                        action: 'delete_job',
                        job_id: jobId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Reload the page to show updated data
                    location.reload();
                } else {
                    alert('Error deleting job: ' + data.message);
                    if (data.message.includes('Invalid admin token')) {
                        // Token is no longer valid, show settings again
                        localStorage.removeItem('handymanager_admin_token');
                        showSettingsSection();
                    }
                }
            } catch (error) {
                console.error('Error deleting job:', error);
                alert('Error deleting job');
            }
        }

        // Apply filters automatically when they change
        function applyFilters() {
            const filters = {};

            if (dateFromInput.value) {
                filters.date_from = dateFromInput.value;
            }

            if (dateToInput.value) {
                filters.date_to = dateToInput.value;
            }

            if (techFilter.value) {
                filters.tech = techFilter.value;
            }

            // Handle multiple location selections
            const selectedLocations = Array.from(locationFilter.selectedOptions)
                .map(option => option.value)
                .filter(value => value !== ""); // Exclude the empty "All Locations" option

            if (selectedLocations.length > 0) {
                filters.location = selectedLocations;
            }

            loadJobs(filters);
        }

        // Add event listeners for automatic filter application
        dateFromInput.addEventListener('change', applyFilters);
        dateToInput.addEventListener('change', applyFilters);
        techFilter.addEventListener('change', applyFilters);
        locationFilter.addEventListener('change', applyFilters);

        // Clear filters
        clearFiltersBtn.addEventListener('click', function() {
            // Don't allow clearing filters if a cell is currently being edited
            if (currentlyEditingCell) {
                alert('Please save or cancel changes before clearing filters.');
                return;
            }

            dateFromInput.value = '';
            dateToInput.value = '';
            techFilter.value = '';
            // Deselect all location options except the first one (All Locations)
            Array.from(locationFilter.options).forEach((option, index) => {
                if (index !== 0) {
                    option.selected = false;
                }
            });
            locationFilter.options[0].selected = true;
            loadJobs();
        });

        // Export to CSV
        exportCsvBtn.addEventListener('click', async function() {
            // Don't allow export if a cell is currently being edited
            if (currentlyEditingCell) {
                alert('Please save or cancel changes before exporting.');
                return;
            }

            const filters = {};

            if (dateFromInput.value) {
                filters.date_from = dateFromInput.value;
            }

            if (dateToInput.value) {
                filters.date_to = dateToInput.value;
            }

            if (techFilter.value) {
                filters.tech = techFilter.value;
            }

            // Handle multiple location selections for export
            const selectedLocations = Array.from(locationFilter.selectedOptions)
                .map(option => option.value)
                .filter(value => value !== ""); // Exclude the empty "All Locations" option

            if (selectedLocations.length > 0) {
                filters.location = selectedLocations;
            }

            const token = localStorage.getItem('handymanager_admin_token');
            if (!token) {
                alert('Admin token not found. Please re-authenticate.');
                showSettingsSection();
                return;
            }

            try {
                const response = await fetch('admin-jobs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        token: token,
                        filters: filters
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Convert to CSV
                    const csvContent = convertToCsv(data.jobs);

                    // Create download link
                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.setAttribute('href', url);
                    link.setAttribute('download', 'handymanager-jobs.csv');
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    alert('Error exporting data: ' + data.message);
                    if (data.message.includes('Invalid admin token')) {
                        // Token is no longer valid, show settings again
                        localStorage.removeItem('handymanager_admin_token');
                        showSettingsSection();
                    }
                }
            } catch (error) {
                console.error('Error exporting data:', error);
                alert('Error exporting data');
            }
        });

        // Convert jobs data to CSV format
        function convertToCsv(jobs) {
            // CSV header
            let csv = 'Start Date/Time,End Date/Time,Duration (Hours),Tech Name,Location,Notes\n';

            // CSV rows
            jobs.forEach(job => {
                // Escape any commas or quotes in the data
                const startDate = job.start_time ? `"${formatDateWithoutSecondsOrYear(new Date(job.start_time))}"` : '""';
                const endDate = job.end_time ? `"${formatDateWithoutSecondsOrYear(new Date(job.end_time))}"` : '""';

                // Calculate duration in hours (with 2 decimal precision)
                let duration = '';
                if (job.start_time && job.end_time) {
                    const start = new Date(job.start_time);
                    const end = new Date(job.end_time);
                    const diffMs = end - start;
                    const diffHours = diffMs / (1000 * 60 * 60);
                    duration = diffHours.toFixed(2);
                }

                const techName = job.tech_name ? `"${job.tech_name.replace(/"/g, '""')}"` : '""';
                const location = job.location ? `"${job.location.replace(/"/g, '""')}"` : '""';
                const notes = job.notes ? `"${job.notes.replace(/"/g, '""')}"` : '""';

                csv += `${startDate},${endDate},${duration},${techName},${location},${notes}\n`;
            });

            return csv;
        }
    </script>
</body>
</html>