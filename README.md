# Excel SMS Add-in

A PHP-based Excel add-in for sending bulk SMS messages directly from Excel spreadsheets.

## Features

- Send SMS from Excel data
- Template-based messaging with variables
- Auto-mapping of Excel columns to message variables
- Bulk SMS processing (up to 10,000 per request)
- Real-time logging and error handling

## Deployment

This add-in is designed to be hosted on cloud platforms like Render.com.

## Files

- `index.php` - Main application file
- `composer.json` - PHP dependencies
- `render.yaml` - Render.com deployment config
- `manifest-updated.xml` - Excel add-in manifest

## Setup

1. Deploy to hosting platform
2. Update manifest URLs with your hosted URL
3. Sideload manifest in Excel