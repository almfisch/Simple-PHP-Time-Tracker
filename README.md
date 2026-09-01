# Simple PHP Time Tracker

A lightweight, self-hosted time tracker built with PHP. It requires no database, framework, build process, or external assets.

## Features

- Customers and projects
- Start, pause, resume, and reopen timers
- Multiple work sessions grouped into one time entry
- Open and billed time entries
- Open-time totals by customer and project
- Optional password-protected access
- Extendable language system with German and English included
- Local PHP data file with no database dependency
- Responsive interface for desktop and mobile

## Requirements

- PHP 7.3 or newer
- Write access for PHP in the application directory
- PHP sessions enabled

No CDN, JavaScript library, external font, API, or internet connection is required.

## Installation

1. Download `index.php` and `lang.php`.
2. Place both files in the same directory on your web server.
3. Ensure PHP can write to that directory.
4. Open `index.php` in your browser.
5. Select the language for the installation.
6. Optionally create a login.

The application creates `data.php` automatically when data is first saved. Do not commit `data.php` to a public repository because it contains your customers, projects, time entries, and password hash.

## Updating

Replace `index.php` and, when necessary, `lang.php`. Keep your existing `data.php` unchanged.

Before updating, download a backup copy of `data.php`.

## Migrating from an older version

If an older `data.json` exists and no `data.php` is present, the application imports it automatically. Verify the imported data, download the old file as a backup, and then remove `data.json` from the server.

## Languages

German and English are included. The selected language is stored globally in `data.php`, making it suitable for a personal installation.

To add a language, copy an existing language block in `lang.php`, assign a new language code and display name, and translate its semantic `strings` keys such as `title`, `save`, and `start_timer`. Languages added there automatically appear in the language dropdown.

## Multiple users

This application is designed as a personal time tracker. For several independent users, the simplest setup is one directory per person, with each directory containing its own `index.php`, `lang.php`, and generated `data.php`.

## Security

- Passwords are stored using PHP's `password_hash()` function, never as plain text.
- `data.php` and `lang.php` reject direct browser access.
- Form submissions use CSRF protection.
- File writes use an exclusive lock.

For public servers, HTTPS and an additional server-level access restriction are recommended. A three-character password is technically accepted by the application, but a long, unique password is strongly recommended.

## Backup

All personal application data is contained in `data.php`. Back up this file regularly and store the backup securely.

## License

Released under the MIT License. See [LICENSE](LICENSE).
