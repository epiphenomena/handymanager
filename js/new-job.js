// new-job.js - Handles the new job form

document.addEventListener('DOMContentLoaded', function() {
    const newJobForm = document.getElementById('new-job-form');
    
    // Prefill start date and time with current date and time
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    document.getElementById('start-date').value = `${year}-${month}-${day}`;
    document.getElementById('start-time').value = `${hours}:${minutes}`;
    
    // Handle form submission
    newJobForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const token = localStorage.getItem('handymanager_token');
        const repName = localStorage.getItem('handymanager_rep_name');
        const startDate = document.getElementById('start-date').value;
        const startTime = document.getElementById('start-time').value;
        const location = document.getElementById('location').value;
        
        // Combine date and time
        const startDateTime = `${startDate} ${startTime}`;
        
        if (!token || !repName) {
            alert('Please set your token and rep name in settings first.');
            window.location.href = 'index.html';
            return;
        }
        
        fetch('create-job.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                rep_name: repName,
                start_time: startDateTime,
                location: location
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'index.html';
            } else {
                alert('Error creating job: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error creating job. Please try again.');
        });
    });
});