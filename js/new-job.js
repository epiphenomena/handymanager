// new-job.js - Handles the new job form

document.addEventListener('DOMContentLoaded', function() {
    const newJobForm = document.getElementById('new-job-form');
    const locationInput = document.getElementById('location');
    const locationOptions = document.getElementById('location-options');
    
    // Prefill start date and time with current date and time
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    document.getElementById('start-date').value = `${year}-${month}-${day}`;
    document.getElementById('start-time').value = `${hours}:${minutes}`;
    
    // Fetch and populate location options
    const token = localStorage.getItem('handymanager_token');
    const techName = localStorage.getItem('handymanager_tech_name');
    
    if (token && techName) {
        fetch('get-locations.php', {
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
            if (data.success && data.locations) {
                // Clear existing options
                locationOptions.innerHTML = '';
                
                // Add new options
                data.locations.forEach(location => {
                    const option = document.createElement('option');
                    option.value = location;
                    locationOptions.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error('Error fetching locations:', error);
        });
    }
    
    // Handle form submission
    newJobForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const startDate = document.getElementById('start-date').value;
        const startTime = document.getElementById('start-time').value;
        const location = locationInput.value.trim(); // Trim whitespace
        
        // Combine date and time
        const startDateTime = `${startDate} ${startTime}`;
        
        if (!token || !techName) {
            alert('Please set your token and tech name in settings first.');
            window.location.href = './';
            return;
        }
        
        fetch('create-job.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token: token,
                tech_name: techName.trim(), // Trim whitespace
                start_time: startDateTime,
                location: location
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = './';
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