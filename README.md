# Find a Buddy

SSU study-buddy matching app. See `docs/superpowers/specs/2026-09-10-find-a-buddy-design.md` for the full design.

## Local setup (Laragon)

1. Install [Laragon](https://laragon.org/download/) to the default `C:\laragon` path, start Apache + MySQL.
2. Place this project at `C:\laragon\www\find-a-buddy`.
3. Import the schema: open Laragon → **Database** (HeidiSQL) and run `backend/schema.sql`, or `mysql -u root find_a_buddy < backend/schema.sql`.
4. Copy `backend/config.example.php` to `backend/config.php` and fill in your local DB credentials (defaults work with a stock Laragon MySQL install: user `root`, empty password).
5. Get an Anthropic API key at https://console.anthropic.com/ and paste it into `backend/config.php` as `anthropic_api_key` to enable the AI Study Mascot chat.
6. Install PHP dev dependencies: `composer install`.
7. Visit `http://find-a-buddy.test/frontend/` in your browser.

## Running tests

```bash
php vendor/bin/phpunit
```
