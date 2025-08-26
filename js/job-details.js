// job-details.js - Handles the job details/complete job form

document.addEventListener('DOMContentLoaded', function() {
    const completeJobForm = document.getElementById('complete-job-form');
    const urlParams = new URLSearchParams(window.location.search);
    const jobId = urlParams.get('id');
    
    // Set job ID in hidden field
    document.getElementById('job-id').value = jobId;
    
    // Prefill end date and time with current date and time
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    document.getElementById('end-date').value = `${year}-${month}-${day}`;
    document.getElementById('end-time').value = `${hours}:${minutes}`;
    
    // Handle form submission
    completeJobForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const token = localStorage.getItem('handymanager_token');
        const endDate = document.getElementById('end-date').value;
        const endTime = document.getElementById('end-time').value;
        const notes = document.getElementById('notes').value;
        
        // Combine date and time
        const endDateTime = `${endDate} ${endTime}`;
        
        if (!token) {
            alert('Please set your token in settings first.');
            window.location.href = './';
            return;
        }
        
        fetch('complete-job.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                job_id: jobId,
                end_time: endDateTime,
                notes: notes
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = './';
            } else {
                alert('Error completing job: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error completing job. Please try again.');
        });
    });
});