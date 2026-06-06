<?php
// log-call.php - Standalone page for the administrative assistant to log
// service calls, which opens a job.
//
// GET  -> the form page (token gate on first visit)
// POST -> JSON API: verifies the admin token and creates the job

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getValidatedInput(['customer_name', 'location'], true);

    $customer = trim($input['customer_name']);
    $location = trim($input['location']);
    if ($customer === '' || $location === '') {
        sendJsonResponse(['success' => false, 'message' => 'Customer name and location are required'], 400);
    }

    // The customer name + location becomes the official job name
    $name = "$customer - $location";
    createJob($name, $customer, $input['phone'] ?? '', $input['call_notes'] ?? '');

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
            <label>Location / Address *
                <input type="text" id="location" placeholder="e.g. 123 Main St" required autocomplete="off">
            </label>
            <p class="hint">Customer name + location becomes the job name the techs will see.</p>
            <label>Phone Number
                <input type="tel" id="phone" autocomplete="off">
            </label>
            <label>Call Notes
                <textarea id="call-notes" rows="5" placeholder="What does the customer need?"></textarea>
            </label>
            <button type="submit">Open Job</button>
        </form>

        <button id="change-token" style="display:none">Change token</button>
    </div>

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
        }

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
                    call_notes: document.getElementById('call-notes').value
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showBanner('Job opened: ' + data.job_name, false);
                    callForm.reset();
                    document.getElementById('customer-name').focus();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
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
