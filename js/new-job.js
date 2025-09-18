// new-job.js - Handles the new job form

document.addEventListener('DOMContentLoaded', function() {
    const newJobForm = document.getElementById('new-job-form');
    const locationInput = document.getElementById('location');
    const locationSuggestions = document.getElementById('location-suggestions');
    
    // Prefill start date and time with current date and time
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    document.getElementById('start-date').value = `${year}-${month}-${day}`;
    document.getElementById('start-time').value = `${hours}:${minutes}`;
    
    // Store locations for autocomplete
    let allLocations = [];
    
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
                allLocations = data.locations;
            }
        })
        .catch(error => {
            console.error('Error fetching locations:', error);
        });
    }
    
    // Autocomplete functionality
    let selectedIndex = -1;
    
    // Filter locations based on input
    function filterLocations(query) {
        if (!query) return [];
        return allLocations.filter(location => 
            location.toLowerCase().includes(query.toLowerCase())
        );
    }
    
    // Show suggestions
    function showSuggestions(suggestions) {
        locationSuggestions.innerHTML = '';
        
        if (suggestions.length === 0) {
            locationSuggestions.style.display = 'none';
            return;
        }
        
        suggestions.forEach((suggestion, index) => {
            const suggestionElement = document.createElement('div');
            suggestionElement.className = 'autocomplete-suggestion';
            suggestionElement.textContent = suggestion;
            suggestionElement.addEventListener('click', () => {
                locationInput.value = suggestion;
                locationSuggestions.style.display = 'none';
                selectedIndex = -1;
            });
            locationSuggestions.appendChild(suggestionElement);
        });
        
        locationSuggestions.style.display = 'block';
        selectedIndex = -1;
    }
    
    // Handle keyboard navigation
    function handleKeyNavigation(e) {
        const suggestions = locationSuggestions.querySelectorAll('.autocomplete-suggestion');
        
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                updateSelection(suggestions);
                break;
            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelection(suggestions);
                break;
            case 'Enter':
                if (selectedIndex >= 0 && suggestions.length > 0) {
                    e.preventDefault();
                    locationInput.value = suggestions[selectedIndex].textContent;
                    locationSuggestions.style.display = 'none';
                    selectedIndex = -1;
                }
                break;
            case 'Escape':
                locationSuggestions.style.display = 'none';
                selectedIndex = -1;
                break;
        }
    }
    
    // Update selected item styling
    function updateSelection(suggestions) {
        suggestions.forEach((suggestion, index) => {
            suggestion.classList.toggle('selected', index === selectedIndex);
        });
    }
    
    // Event listeners for autocomplete
    locationInput.addEventListener('input', function() {
        const query = this.value;
        const suggestions = filterLocations(query);
        showSuggestions(suggestions);
    });
    
    locationInput.addEventListener('keydown', handleKeyNavigation);
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target !== locationInput && !locationSuggestions.contains(e.target)) {
            locationSuggestions.style.display = 'none';
            selectedIndex = -1;
        }
    });
    
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