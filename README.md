# handymanager
A simple PWA + backend for capturing basic info from service rep visits

## Architecture

Consists of 2 parts:

1) a simple PWA frontend. The front end only supports a few operations:
   1) A settings page that allows saving a token and rep name to localstorage
   2) default home page that POSTs the token and rep name to the backend, receives a list of In Progress jobs, if any, and shows:
      1) a list of jobs that are "In Progress" (clicking on one opens the In Progress job page for that job) and
      2) a button that starts a new job, opening the new job page and prefilling the start date and time with now
      3) a button to open and edit the settings
   3) a new job page that is a form with 3 fields:
      1) the start date which is prefilled with today's date
      2) the start time which is prefilled with the current time
      3) the job location
      4) and a submit button that POSTs those 3 fields and the token and rep name to the backend
   4) an "In Progress" job page (open) that is a form with 4 fields:
      1) The end date which is prefilled with today's date
      2) The end time which is prefilled with the current time
      3) Notes
      4) a hidden job id provided by the backend
      5) and a submit button that POSTs those 3 fields and the token and job id to the backend
2) A php based backend that responds to the POST requests described above by
   1) Matching the token to a hard coded token, if they don't match error
   2) saving or reading from a JSON data file. The data structure has a single array of jobs with these properties:
      1) required job id
      2) required create datetime (created on insert by the backend)
      3) required rep name
      4) required start datetime
      5) optional closed datetime (created when the end time and notes are inserted)
      6) optional end datetime
      7) optional notes
      8) required location

The frontend PWA is very simple, clear and clean. The frontend is intended to be used almost exclusively on mobile. The buttons are large. The form fields prefill or present native value appropriate controls (for example, native date and time selectors). The backend is clean handwritten vanilla php with no external dependencies.

## Setup and Running

1. Clone the repository
2. Navigate to the project directory
3. Start the development server:
   ```bash
   php dev-server.php
   ```
   Or specify a port:
   ```bash
   php dev-server.php 8080
   ```
4. Open your browser to http://localhost:8000/index.html (or your chosen port)
5. For the token, use the hardcoded value in `config.php` (default: "handymanager-secret-token")