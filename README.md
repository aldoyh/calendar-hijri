# Calendarify - With Hijri Support

![Calendarify Cover](./uploads/header_cover.jpg)

## Features & Architecture

* **Dual Calendar Engine**: Seamless synchronization of Gregorian and Umm al-Qura Hijri lunar dates with dual-date matrix.
* **Modern Swiss Flip Clock**: Precision chronometer with strictly LTR layout (`[HH] : [MM] : [SS]`) in both Arabic and English modes.
* **Inspirational Arabic Quotes Engine**: Initial cache of 500 randomized quotes from Hugging Face's `AhmedBou/Arabic_Quotes` dataset, with automatic per-quote tracking, exhaustion detection, and cycle reset.
* **Smart Media Resizer**:
  - **Cover Banner**: Proportional downscaling up to 1920×1080px with vertical drag repositioning.
  - **Avatar**: 1:1 square center-crop and high-DPI 400×400px optimization.
* **Local Persistence**: Zero-dependency SQLite database (`calendarify.sqlite`) with bundled offline seed (`quotes_seed.json`).
* **Interactive Suite**: Features 5 distinct dashboard modules: Finance Tracker, Goal Setting, Daily Journaling, Habit Tracking grid, and a Secure Vault for passwords/notes, alongside Quick Tasks and Reminders.

## How it works

1. Serve locally with PHP built-in server:
   ```bash
   php -S localhost:8011 -t ./
   ```
2. Open `http://localhost:8011` in your browser.


