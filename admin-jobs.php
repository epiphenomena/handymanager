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

// Check if it's a POST request for data export
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate admin token
    if (!isset($input['token']) || !verifyAdminToken($input['token'])) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid admin token']);
    }
    
    // Get filters from input
    $filters = [];
    if (isset($input['filters'])) {
        $filters = $input['filters'];
    }
    
    // Get jobs from database
    $jobs = getAllJobs($filters);
    
    sendJsonResponse(['success' => true, 'jobs' => $jobs]);
}

// For GET requests, show the admin interface
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
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">HandyManager Admin Dashboard</h1>
                
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
                                <label for="repFilter" class="form-label">Rep</label>
                                <select class="form-select" id="repFilter">
                                    <option value="">All Reps</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="locationFilter" class="form-label">Location</label>
                                <select class="form-select" id="locationFilter">
                                    <option value="">All Locations</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <button type="button" class="btn btn-primary" id="applyFilters">Apply Filters</button>
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
                                <th>Start Date/Time</th>
                                <th>End Date/Time</th>
                                <th>Rep Name</th>
                                <th>Location</th>
                                <th>Notes</th>
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

    <script>
        // Admin token - in a real application, this should be handled more securely
        const ADMIN_TOKEN = 'admin123';
        
        // DOM elements
        const filterForm = document.getElementById('filterForm');
        const dateFromInput = document.getElementById('dateFrom');
        const dateToInput = document.getElementById('dateTo');
        const repFilter = document.getElementById('repFilter');
        const locationFilter = document.getElementById('locationFilter');
        const applyFiltersBtn = document.getElementById('applyFilters');
        const clearFiltersBtn = document.getElementById('clearFilters');
        const exportCsvBtn = document.getElementById('exportCsv');
        const jobsTableBody = document.querySelector('#jobsTable tbody');
        
        // Load filter options and initial data
        document.addEventListener('DOMContentLoaded', function() {
            loadFilterOptions();
            loadJobs();
        });
        
        // Load filter options (reps and locations)
        async function loadFilterOptions() {
            try {
                const response = await fetch('get-filter-options.php');
                const data = await response.json();
                
                if (data.success) {
                    // Populate reps dropdown
                    data.options.reps.forEach(rep => {
                        const option = document.createElement('option');
                        option.value = rep;
                        option.textContent = rep;
                        repFilter.appendChild(option);
                    });
                    
                    // Populate locations dropdown
                    data.options.locations.forEach(location => {
                        const option = document.createElement('option');
                        option.value = location;
                        option.textContent = location;
                        locationFilter.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading filter options:', error);
            }
        }
        
        // Load jobs with current filters
        async function loadJobs(filters = {}) {
            try {
                const response = await fetch('admin-jobs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        token: ADMIN_TOKEN,
                        filters: filters
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    renderJobsTable(data.jobs);
                } else {
                    alert('Error loading jobs: ' + data.message);
                }
            } catch (error) {
                console.error('Error loading jobs:', error);
                alert('Error loading jobs');
            }
        }
        
        // Render jobs in the table
        function renderJobsTable(jobs) {
            jobsTableBody.innerHTML = '';
            
            if (jobs.length === 0) {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="5" class="text-center">No jobs found</td>';
                jobsTableBody.appendChild(row);
                return;
            }
            
            jobs.forEach(job => {
                const row = document.createElement('tr');
                
                // Format dates
                const startDate = job.start_time ? new Date(job.start_time).toLocaleString() : '';
                const endDate = job.end_time ? new Date(job.end_time).toLocaleString() : '';
                
                row.innerHTML = `
                    <td>${startDate}</td>
                    <td>${endDate}</td>
                    <td>${job.rep_name || ''}</td>
                    <td>${job.location || ''}</td>
                    <td>${job.notes || ''}</td>
                `;
                
                jobsTableBody.appendChild(row);
            });
        }
        
        // Apply filters
        applyFiltersBtn.addEventListener('click', function() {
            const filters = {};
            
            if (dateFromInput.value) {
                filters.date_from = dateFromInput.value;
            }
            
            if (dateToInput.value) {
                filters.date_to = dateToInput.value;
            }
            
            if (repFilter.value) {
                filters.rep = repFilter.value;
            }
            
            if (locationFilter.value) {
                filters.location = locationFilter.value;
            }
            
            loadJobs(filters);
        });
        
        // Clear filters
        clearFiltersBtn.addEventListener('click', function() {
            dateFromInput.value = '';
            dateToInput.value = '';
            repFilter.value = '';
            locationFilter.value = '';
            loadJobs();
        });
        
        // Export to CSV
        exportCsvBtn.addEventListener('click', async function() {
            const filters = {};
            
            if (dateFromInput.value) {
                filters.date_from = dateFromInput.value;
            }
            
            if (dateToInput.value) {
                filters.date_to = dateToInput.value;
            }
            
            if (repFilter.value) {
                filters.rep = repFilter.value;
            }
            
            if (locationFilter.value) {
                filters.location = locationFilter.value;
            }
            
            try {
                const response = await fetch('admin-jobs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        token: ADMIN_TOKEN,
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
                }
            } catch (error) {
                console.error('Error exporting data:', error);
                alert('Error exporting data');
            }
        });
        
        // Convert jobs data to CSV format
        function convertToCsv(jobs) {
            // CSV header
            let csv = 'Start Date/Time,End Date/Time,Rep Name,Location,Notes\n';
            
            // CSV rows
            jobs.forEach(job => {
                // Escape any commas or quotes in the data
                const startDate = job.start_time ? `"${new Date(job.start_time).toLocaleString()}"` : '""';
                const endDate = job.end_time ? `"${new Date(job.end_time).toLocaleString()}"` : '""';
                const repName = job.rep_name ? `"${job.rep_name.replace(/"/g, '""')}"` : '""';
                const location = job.location ? `"${job.location.replace(/"/g, '""')}"` : '""';
                const notes = job.notes ? `"${job.notes.replace(/"/g, '""')}"` : '""';
                
                csv += `${startDate},${endDate},${repName},${location},${notes}\n`;
            });
            
            return csv;
        }
    </script>
</body>
</html>