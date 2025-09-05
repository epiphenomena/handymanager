// edit-job.js - Handles the edit job page functionality

document.addEventListener('DOMContentLoaded', function() {
    const editJobForm = document.getElementById('edit-job-form');
    const jobIdInput = document.getElementById('job-id');
    const startDateInput = document.getElementById('start-date');
    const startTimeInput = document.getElementById('start-time');
    const endDateInput = document.getElementById('end-date');
    const endTimeInput = document.getElementById('end-time');
    const locationInput = document.getElementById('location');
    const notesInput = document.getElementById('notes');
    
    // Get job ID from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const jobId = urlParams.get('id');
    
    if (!jobId) {
        alert('No job ID provided');
        window.location.href = './';
        return;
    }
    
    jobIdInput.value = jobId;
    
    // Load job details
    loadJobDetails(jobId);
    
    // Handle form submission
    editJobForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const token = localStorage.getItem('handymanager_token');
        if (!token) {
            alert('Authentication token not found. Please log in again.');
            window.location.href = './';
            return;
        }
        
        const startDate = startDateInput.value;
        const startTime = startTimeInput.value;
        const endDate = endDateInput.value;
        const endTime = endTimeInput.value;
        const location = locationInput.value.trim(); // Trim whitespace
        const notes = notesInput.value; // Don't trim whitespace to preserve formatting
        
        if (!startDate || !startTime || !location) {
            alert('Please fill in all required fields');
            return;
        }
        
        const startDateTime = startDate + ' ' + startTime;
        const endDateTime = endDate && endTime ? endDate + ' ' + endTime : null;
        
        // Update job
        fetch('update-job.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                job_id: jobId,
                start_time: startDateTime,
                end_time: endDateTime,
                location: location,
                notes: notes
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Redirect to home page without alert
                window.location.href = './';
            } else {
                alert('Error updating job: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating job. Please try again.');
        });
    });
    
    // Load job details from backend
    function loadJobDetails(jobId) {
        const token = localStorage.getItem('handymanager_token');
        if (!token) {
            alert('Authentication token not found. Please log in again.');
            window.location.href = './';
            return;
        }
        
        fetch('get-job.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                job_id: jobId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const job = data.job;
                
                // Parse start time
                const startDateTime = new Date(job.start_time);
                startDateInput.value = startDateTime.toISOString().split('T')[0];
                startTimeInput.value = startDateTime.toTimeString().substring(0, 5);
                
                // Parse end time if exists
                if (job.end_time) {
                    const endDateTime = new Date(job.end_time);
                    endDateInput.value = endDateTime.toISOString().split('T')[0];
                    endTimeInput.value = endDateTime.toTimeString().substring(0, 5);
                }
                
                locationInput.value = job.location;
                // Prefill notes with template if empty
                if (job.notes) {
                    notesInput.value = job.notes;
                } else {
                    notesInput.value = "Notes:\n- \n\nMaterials:\n- ";
                }
            } else {
                alert('Error loading job: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading job. Please try again.');
        });
    }
});