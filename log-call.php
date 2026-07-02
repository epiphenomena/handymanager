<?php
// log-call.php - Standalone page for the administrative assistant to log
// service calls, which opens a job.
//
// GET  -> the form page (token gate on first visit)
// POST -> JSON API: verifies the admin token and creates the job

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getValidatedInput([], true);

    // Known customers/locations + the tag vocabulary for the form
    if (($input['action'] ?? '') === 'suggestions') {
        sendJsonResponse(['success' => true, 'tags' => getAllTags()] + getCallSuggestions());
    }

    $customer = trim($input['customer_name'] ?? '');
    $location = trim($input['location'] ?? '');
    if ($customer === '' || $location === '') {
        sendJsonResponse(['success' => false, 'message' => 'Customer name and location are required'], 400);
    }

    // Call date/time: blank falls back to now; a given value is validated so a
    // call logged after the fact can be recorded at its real time.
    $openedInput = trim(($input['opened_date'] ?? '') . ' ' . ($input['opened_time'] ?? ''));
    $openedAt = $openedInput === '' ? now() : validateDateTime($openedInput);
    if ($openedAt === false) {
        sendJsonResponse(['success' => false, 'message' => 'A valid call date and time are required'], 400);
    }

    // The customer name + location becomes the official job name
    $name = "$customer - $location";
    $jobId = createJob($name, $customer, $input['phone'] ?? '', $input['call_notes'] ?? '', $openedAt);
    setJobTags($jobId, $input['tags'] ?? []);

    sendJsonResponse(['success' => true, 'job_name' => $name]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log a Call - HandyManager</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            margin: 0;
            background: #f4f5f7;
            color: #1c2024;
            font-size: 16px;
            line-height: 1.5;
        }

        .wrap { max-width: 520px; margin: 0 auto; padding: 24px 16px 64px; }

        h1 { font-size: 22px; margin: 0 0 4px; }
        .subtitle { color: #6b7280; font-size: 14px; margin: 0 0 20px; }

        form {
            background: #fff;
            border: 1px solid #e2e4e8;
            border-radius: 10px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-weight: 600;
            font-size: 14px;
        }

        input, textarea {
            font: inherit;
            padding: 11px 12px;
            border: 1px solid #e2e4e8;
            border-radius: 6px;
            width: 100%;
        }

        input:focus, textarea:focus {
            outline: 2px solid rgba(37,99,235,.35);
            border-color: #2563eb;
        }

        textarea { resize: vertical; }

        .hint { color: #6b7280; font-size: 13px; font-weight: 400; margin: -8px 0 0; }

        .dt-row { display: flex; gap: 12px; }
        .dt-row label { flex: 1; }

        /* Tag picker: toggle pills backed by checkboxes (the checkbox is
           visually hidden but still submits when checked) */
        .tag-picker { border: 1px solid #e2e4e8; border-radius: 8px; padding: 12px 14px; margin: 0; }
        .tag-picker legend { font-weight: 600; font-size: 14px; padding: 0 4px; }
        .tag-options { display: flex; flex-wrap: wrap; gap: 8px; }
        .tag-options label {
            display: inline-flex; align-items: center;
            font-size: 14px; font-weight: 500;
            border: 1px solid #e2e4e8; background: #fff; color: #1c2024;
            border-radius: 999px; padding: 7px 14px;
            cursor: pointer; user-select: none;
        }
        .tag-options input { position: absolute; opacity: 0; width: 0; height: 0; }
        .tag-options label:hover { border-color: #2563eb; color: #2563eb; }
        .tag-options label:has(input:checked) { background: #2563eb; border-color: #2563eb; color: #fff; }
        .tag-options label:has(input:focus-visible) { outline: 2px solid rgba(37,99,235,.35); outline-offset: 1px; }

        /* Custom autocomplete dropdown */
        .hm-ac-wrap { position: relative; }
        .hm-ac-list {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 50;
            max-height: min(260px, 45vh); overflow-y: auto;
            background: #fff; border: 1px solid #ccc; border-top: none;
            border-radius: 0 0 6px 6px; box-shadow: 0 4px 10px rgba(0,0,0,.12);
            -webkit-overflow-scrolling: touch; overscroll-behavior: contain;
        }
        .hm-ac-item { padding: 12px; font-size: 16px; cursor: pointer; border-bottom: 1px solid #eee; }
        .hm-ac-item:last-child { border-bottom: none; }
        .hm-ac-item:hover, .hm-ac-item.active { background: #e8f0fe; }

        button {
            font: inherit;
            font-weight: 600;
            font-size: 17px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 14px;
            cursor: pointer;
        }

        button:hover { background: #1d4ed8; }

        .banner {
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 600;
            display: none;
        }

        .banner-ok { background: #dcfce7; color: #166534; }
        .banner-error { background: #fee2e2; color: #991b1b; }

        #change-token {
            background: none;
            border: none;
            color: #6b7280;
            font-size: 13px;
            font-weight: 400;
            padding: 0;
            margin-top: 24px;
            text-decoration: underline;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Log a Service Call</h1>
        <p class="subtitle">Fill this out for each call — it opens the job for the techs.</p>

        <div id="banner" class="banner"></div>

        <form id="token-form" style="display:none">
            <label>Access Token
                <input type="password" id="token-input" placeholder="Enter the token you were given" autocomplete="current-password">
            </label>
            <button type="submit">Continue</button>
        </form>

        <form id="call-form" style="display:none">
            <label>Customer Name *
                <input type="text" id="customer-name" required autocomplete="off">
            </label>
            <label>Phone Number
                <input type="tel" id="phone" autocomplete="off">
            </label>
            <label>Call Notes
                <textarea id="call-notes" rows="5" placeholder="What does the customer need?"></textarea>
            </label>
            <label>Location / Address *
                <input type="text" id="location" placeholder="e.g. 123 Main St" required autocomplete="off">
            </label>
            <div class="dt-row">
                <label>Call Date
                    <input type="date" id="opened-date" required>
                </label>
                <label>Call Time
                    <input type="time" id="opened-time" required>
                </label>
            </div>
            <p class="hint">Customer name + location becomes the job name the techs will see.
                Call date/time defaults to now — change it for a call logged after the fact.</p>
            <fieldset id="tag-picker" class="tag-picker" style="display:none">
                <legend>Tags</legend>
                <div id="tag-options" class="tag-options"></div>
            </fieldset>
            <button type="submit">Open Job</button>
        </form>

        <button id="change-token" style="display:none">Change token</button>
    </div>

    <script src="js/autocomplete.js"></script>
    <script>
        const TOKEN_KEY = 'handymanager_admin_token';
        const tokenForm = document.getElementById('token-form');
        const callForm = document.getElementById('call-form');
        const banner = document.getElementById('banner');
        const changeTokenBtn = document.getElementById('change-token');

        function showBanner(message, isError) {
            banner.textContent = message;
            banner.className = 'banner ' + (isError ? 'banner-error' : 'banner-ok');
            banner.style.display = 'block';
        }

        function showTokenForm() {
            tokenForm.style.display = '';
            callForm.style.display = 'none';
            changeTokenBtn.style.display = 'none';
            banner.style.display = 'none';
        }

        function showCallForm() {
            tokenForm.style.display = 'none';
            callForm.style.display = '';
            changeTokenBtn.style.display = '';
            document.getElementById('customer-name').focus();
            setNowDefaults();
            loadSuggestions();
        }

        // Pre-fill the call date/time with "now" (local). The office edits it
        // only when logging a call after the fact.
        const openedDateInput = document.getElementById('opened-date');
        const openedTimeInput = document.getElementById('opened-time');
        function setNowDefaults() {
            const d = new Date();
            const pad = n => String(n).padStart(2, '0');
            openedDateInput.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            openedTimeInput.value = pad(d.getHours()) + ':' + pad(d.getMinutes());
        }

        // Known customers/locations for the autocomplete. Suggestions only -
        // new (freeform) entries are always allowed.
        let customersByName = {};
        let customerNames = [];
        let locationNames = [];

        const customerInput = document.getElementById('customer-name');
        const locationInput = document.getElementById('location');
        const phoneInput = document.getElementById('phone');

        // Custom dropdowns (native datalist is unreliable across browsers)
        HMAutocomplete.attach(customerInput, { getItems: () => customerNames });
        HMAutocomplete.attach(locationInput, { getItems: () => locationNames });

        function loadSuggestions() {
            fetch('log-call.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    token: localStorage.getItem(TOKEN_KEY) || '',
                    action: 'suggestions'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) return;
                customersByName = {};
                customerNames = (data.customers || []).map(c => {
                    customersByName[c.name.toLowerCase()] = c;
                    return c.name;
                });
                locationNames = data.locations || [];
                renderTags(data.tags || []);
            })
            .catch(error => console.error('Error loading suggestions:', error));
        }

        // Render the curated tag vocabulary as checkboxes (no free-text - tags
        // are managed in the admin dashboard).
        const tagPicker = document.getElementById('tag-picker');
        const tagOptions = document.getElementById('tag-options');
        function renderTags(tags) {
            tagOptions.textContent = '';
            if (!tags.length) { tagPicker.style.display = 'none'; return; }
            tags.forEach(function(tag) {
                const label = document.createElement('label');
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'tags';
                input.value = tag.id;
                const span = document.createElement('span');
                span.textContent = tag.name;
                label.appendChild(input);
                label.appendChild(span);
                tagOptions.appendChild(label);
            });
            tagPicker.style.display = '';
        }

        function maybePrefill(input, value) {
            if (!value) return;
            if (input.value.trim() === '' || input.dataset.autofilled === '1') {
                input.value = value;
                input.dataset.autofilled = '1';
            }
        }

        customerInput.addEventListener('input', function() {
            const known = customersByName[this.value.trim().toLowerCase()];
            if (known) {
                maybePrefill(locationInput, known.location);
                maybePrefill(phoneInput, known.phone);
            }
        });

        // Hand-typed edits stop future autofill overwrites
        [locationInput, phoneInput].forEach(input => {
            input.addEventListener('input', function(e) {
                if (e.isTrusted) delete this.dataset.autofilled;
            });
        });

        tokenForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const token = document.getElementById('token-input').value.trim();
            if (!token) return;
            localStorage.setItem(TOKEN_KEY, token);
            showCallForm();
        });

        changeTokenBtn.addEventListener('click', function() {
            localStorage.removeItem(TOKEN_KEY);
            showTokenForm();
        });

        callForm.addEventListener('submit', function(e) {
            e.preventDefault();
            banner.style.display = 'none';

            fetch('log-call.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    token: localStorage.getItem(TOKEN_KEY) || '',
                    customer_name: document.getElementById('customer-name').value.trim(),
                    location: document.getElementById('location').value.trim(),
                    phone: document.getElementById('phone').value.trim(),
                    call_notes: document.getElementById('call-notes').value,
                    opened_date: openedDateInput.value,
                    opened_time: openedTimeInput.value,
                    tags: Array.from(tagOptions.querySelectorAll('input:checked')).map(cb => cb.value)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showBanner('Job opened: ' + data.job_name, false);
                    callForm.reset();
                    setNowDefaults();
                    document.getElementById('customer-name').focus();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    loadSuggestions();
                } else {
                    showBanner('Error: ' + data.message, true);
                    if ((data.message || '').includes('token')) {
                        showTokenForm();
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showBanner('Error logging the call. Please try again.', true);
            });
        });

        if (localStorage.getItem(TOKEN_KEY)) {
            showCallForm();
        } else {
            showTokenForm();
        }
    </script>
</body>
</html>
