// main.js - Handles the main page functionality

// Register service worker for PWA functionality
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/service-worker.js')
            .then(function(registration) {
                console.log('ServiceWorker registration successful with scope: ', registration.scope);
            })
            .catch(function(err) {
                console.log('ServiceWorker registration failed: ', err);
            });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const settingsForm = document.getElementById('settings-form');
    const settingsSection = document.getElementById('settings-section');
    const jobsSection = document.getElementById('jobs-section');
    const historySection = document.getElementById('history-section');
    const jobsList = document.getElementById('jobs-list');
    const historyList = document.getElementById('history-list');
    const newJobBtn = document.getElementById('new-job-btn');
    const settingsBtn = document.getElementById('settings-btn');
    const historyBtn = document.getElementById('history-btn');
    const backToJobsBtn = document.getElementById('back-to-jobs-btn');
    
    // Load saved settings
    const savedToken = localStorage.getItem('handymanager_token');
    const savedRepName = localStorage.getItem('handymanager_rep_name');
    
    if (savedToken && savedRepName) {
        document.getElementById('token').value = savedToken;
        document.getElementById('rep-name').value = savedRepName;
        showJobsSection();
        loadJobs(savedToken, savedRepName);
    } else {
        // Show jobs section by default even if no saved settings
        showJobsSection();
    }
    
    // Handle settings form submission
    settingsForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const token = document.getElementById('token').value;
        const repName = document.getElementById('rep-name').value;
        
        localStorage.setItem('handymanager_token', token);
        localStorage.setItem('handymanager_rep_name', repName);
        
        showJobsSection();
        loadJobs(token, repName);
    });
    
    // Handle new job button click
    newJobBtn.addEventListener('click', function() {
        window.location.href = 'new-job.html';
    });
    
    // Handle settings button click
    settingsBtn.addEventListener('click', function() {
        showSettingsSection();
    });
    
    // Handle history button click
    historyBtn.addEventListener('click', function() {
        const token = localStorage.getItem('handymanager_token');
        const repName = localStorage.getItem('handymanager_rep_name');
        showHistorySection();
        loadHistory(token, repName);
    });
    
    // Handle back to jobs button click
    backToJobsBtn.addEventListener('click', function() {
        showJobsSection();
    });
    
    // Show jobs section and hide others
    function showJobsSection() {
        settingsSection.style.display = 'none';
        historySection.style.display = 'none';
        jobsSection.style.display = 'block';
    }
    
    // Show settings section and hide others
    function showSettingsSection() {
        jobsSection.style.display = 'none';
        historySection.style.display = 'none';
        settingsSection.style.display = 'block';
    }
    
    // Show history section and hide others
    function showHistorySection() {
        jobsSection.style.display = 'none';
        settingsSection.style.display = 'none';
        historySection.style.display = 'block';
    }
    
    // Load jobs from backend
    function loadJobs(token, repName) {
        fetch('get-jobs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                rep_name: repName
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayJobs(data.jobs);
            } else {
                alert('Error loading jobs: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading jobs. Please try again.');
        });
    }
    
    // Load history from backend
    function loadHistory(token, repName) {
        fetch('get-latest-jobs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                rep_name: repName
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayHistory(data.jobs);
            } else {
                alert('Error loading history: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading history. Please try again.');
        });
    }
    
    // Display jobs in the list
    function displayJobs(jobs) {
        jobsList.innerHTML = '';
        
        if (jobs.length === 0) {
            const noJobsItem = document.createElement('li');
            noJobsItem.textContent = 'No in-progress jobs';
            jobsList.appendChild(noJobsItem);
            return;
        }
        
        jobs.forEach(job => {
            const listItem = document.createElement('li');
            const link = document.createElement('a');
            link.href = `job-details.html?id=${job.id}`;
            link.className = 'job-link';
            link.textContent = `${job.location} - Started: ${new Date(job.start_time).toLocaleString()}`;
            listItem.appendChild(link);
            jobsList.appendChild(listItem);
        });
    }
    
    // Display history in the list
    function displayHistory(jobs) {
        historyList.innerHTML = '';
        
        if (jobs.length === 0) {
            const noJobsItem = document.createElement('li');
            noJobsItem.textContent = 'No job history';
            historyList.appendChild(noJobsItem);
            return;
        }
        
        jobs.forEach(job => {
            const listItem = document.createElement('li');
            listItem.className = 'history-item';
            
            const jobInfo = document.createElement('div');
            jobInfo.className = 'job-info';
            
            const location = document.createElement('div');
            location.className = 'job-location';
            location.textContent = job.location;
            
            const timeInfo = document.createElement('div');
            timeInfo.className = 'job-time';
            const startTime = new Date(job.start_time).toLocaleString();
            const endTime = job.end_time ? new Date(job.end_time).toLocaleString() : 'In Progress';
            timeInfo.textContent = `Started: ${startTime} | Ended: ${endTime}`;
            
            const editBtn = document.createElement('button');
            editBtn.className = 'edit-btn';
            editBtn.textContent = 'Edit';
            editBtn.onclick = function() {
                editJob(job);
            };
            
            jobInfo.appendChild(location);
            jobInfo.appendChild(timeInfo);
            listItem.appendChild(jobInfo);
            listItem.appendChild(editBtn);
            historyList.appendChild(listItem);
        });
    }
    
    // Edit job function
    function editJob(job) {
        // Redirect to edit page with job ID
        window.location.href = `edit-job.html?id=${job.id}`;
    }
});