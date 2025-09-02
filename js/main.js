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
    const savedTechName = localStorage.getItem('handymanager_tech_name');
    
    // Show settings section if either token or tech name is missing
    if (savedToken && savedTechName) {
        document.getElementById('token').value = savedToken;
        document.getElementById('tech-name').value = savedTechName;
        showJobsSection();
        loadJobs(savedToken, savedTechName);
    } else {
        // Show settings section if either token or tech name is missing
        showSettingsSection();
    }
    
    // Handle settings form submission
    settingsForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const token = document.getElementById('token').value.trim();
        const techName = document.getElementById('tech-name').value.trim();
        
        // Validate that both fields are filled
        if (!token || !techName) {
            alert('Please fill in both token and tech name');
            return;
        }
        
        localStorage.setItem('handymanager_token', token);
        localStorage.setItem('handymanager_tech_name', techName);
        
        showJobsSection();
        loadJobs(token, techName);
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
        const techName = localStorage.getItem('handymanager_tech_name');
        
        // Validate that both fields are present
        if (!token || !techName) {
            showSettingsSection();
            return;
        }
        
        showHistorySection();
        loadHistory(token, techName);
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
    
    // Format date without seconds and year
    function formatDateWithoutSecondsOrYear(date) {
        // Format: MM/DD HH:MM (24-hour format)
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${month}/${day} ${hours}:${minutes}`;
    }

    // Show history section and hide others
    function showHistorySection() {
        jobsSection.style.display = 'none';
        settingsSection.style.display = 'none';
        historySection.style.display = 'block';
    }
    
    // Load jobs from backend
    function loadJobs(token, techName) {
        fetch('get-jobs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                tech_name: techName
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
    function loadHistory(token, techName) {
        fetch('get-latest-jobs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                tech_name: techName
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
            link.textContent = `${job.location} - Started: ${formatDateWithoutSecondsOrYear(new Date(job.start_time))}`;
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
            const startTime = formatDateWithoutSecondsOrYear(new Date(job.start_time));
            const endTime = job.end_time ? formatDateWithoutSecondsOrYear(new Date(job.end_time)) : 'In Progress';
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