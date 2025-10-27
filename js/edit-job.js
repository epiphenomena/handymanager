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
    
    // Variable to store the loaded job data
    let loadedJob = null;
    
    // Get job ID from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const jobId = urlParams.get('id');
    
    if (!jobId) {
        alert('No job ID provided');
        window.location.href = './';
        return;
    }
    
    jobIdInput.value = jobId;
    
    // Load job details and set up form submission after loading
    loadJobDetails(jobId);
    
    // Load job details from backend
    function loadJobDetails(jobId) {
        const token = localStorage.getItem('handymanager_token');
        const techName = localStorage.getItem('handymanager_tech_name');
        
        if (!token) {
            alert('Authentication token not found. Please log in again.');
            window.location.href = './';
            return;
        }
        
        if (!techName) {
            alert('Tech name not found. Please log in again.');
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
                tech_name: techName,  // Include tech name for verification
                job_id: jobId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadedJob = data.job; // Store the loaded job data in the global variable
                
                // Parse start time
                const startDateTime = new Date(loadedJob.start_time);
                startDateInput.value = startDateTime.toISOString().split('T')[0];
                startTimeInput.value = startDateTime.toTimeString().substring(0, 5);
                
                // Parse end time if exists
                if (loadedJob.end_time) {
                    const endDateTime = new Date(loadedJob.end_time);
                    endDateInput.value = endDateTime.toISOString().split('T')[0];
                    endTimeInput.value = endDateTime.toTimeString().substring(0, 5);
                }
                
                locationInput.value = loadedJob.location;
                // Prefill notes with template if empty
                if (loadedJob.notes) {
                    notesInput.value = loadedJob.notes;
                } else {
                    notesInput.value = "Notes:\n- \n\nMaterials:\n- ";
                }
                
                // Now that the job is loaded, set up the form submission
                setupFormSubmission();
            } else {
                alert('Error loading job: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading job. Please try again.');
        });
    }
    
    // Set up form submission after job is loaded
    function setupFormSubmission() {
        // Handle form submission
        editJobForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const token = localStorage.getItem('handymanager_token');
            if (!token) {
                alert('Authentication token not found. Please log in again.');
                window.location.href = './';
                return;
            }
            
            // Use the tech name from the loaded job data
            // If a tech can load the job details, they should be able to update it
            const techName = loadedJob.tech_name;
            if (!techName) {
                alert('Unable to verify job ownership. Please reload the page.');
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
                    job_tech_name: techName, // Send the tech name from the loaded job for verification
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
    }
});