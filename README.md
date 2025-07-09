# Gravity Forms Scheduled Reports

**Schedule and email Gravity Forms entry reports (CSV) automatically.**

---

## Features

- **Custom Schedules:** Send reports daily, weekly (choose day), or monthly, at a specific time.
- **Per-Form Reports:** Select which Gravity Form and fields to include in each report.
- **Field Selection:** Choose any form fields and system fields (Entry ID, Submission Date, etc.) for your CSV.
- **Multiple Schedules:** Create as many schedules as you need, each with its own settings.
- **Email Delivery:** Reports are sent as CSV attachments to one or more recipients.
- **Manual Run:** Instantly send a report with the "Run Now" button.
- **Smart Cron Handling:** Each schedule uses its own cron event and interval.
- **Automatic Cleanup:** CSV files are deleted after being emailed.

---

## Installation

1. Download or clone this repository into your WordPress plugins directory:
   ```sh
   wp-content/plugins/gf-schedule-reports
   ```
2. Activate the plugin in your WordPress admin.
3. Make sure [Gravity Forms](https://www.gravityforms.com/) is installed and activated.

---

## Usage

1. In the WordPress admin, go to **Forms → GF Scheduled Reports**.
2. Click **Add New** to create a schedule.
3. Fill in:
    - **Schedule Type:** Daily, Weekly (choose day), or Monthly.
    - **Time:** When the report should be sent.
    - **Form:** Select the Gravity Form to report on.
    - **Fields:** Choose which fields to include in the CSV.
    - **Email Settings:** Set From Name, From Email, To (comma-separated), Subject, and Message.
4. Save the schedule. The plugin will handle scheduling and delivery automatically.

### Manual Run

- Use the **Run Now** button in the schedule list to send a report immediately.

---

## CSV Filename Format

Reports are named:
`gfsr-report-{ID}-{FORMNAME}-{SCHEDULETYPE}-{yyyymmdd}.csv`

Example:  
`gfsr-report-12-CONTACT-FORM-daily-20240607.csv`

---

## Troubleshooting

- **Fields not updating:** If you add or remove fields in your Gravity Form, resave the form in Gravity Forms to refresh the field list in the schedule editor.
- **Cron jobs not running:** WordPress cron depends on site traffic. Use a real cron job for reliable scheduling, or trigger WP-Cron externally.
- **Caching issues:** If field lists seem stale, clear your browser cache and any WordPress caching plugins (e.g., WP Rocket).
- **Email not sending:** Check your WordPress email configuration. The plugin uses `wp_mail()`.

---

## Uninstall

- Deleting a schedule will remove its cron job.
- Deactivating the plugin will stop all scheduled reports.

---

## Requirements

- WordPress 5.0+
- Gravity Forms 2.0+
- PHP 7.0+

---

## License

GPLv2 or later

---

*Made by [Faraz Ahmed](https://farazthewebguy.com/)*
