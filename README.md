# Find a Buddy

A study-buddy matching web app for Savannah State University students. Students register with an `@student.savannahstate.edu` email, add their current courses, and (in planned future features) get matched with classmates in the same class to coordinate study sessions.

**Live demo:** https://find-a-buddy-yu1y.onrender.com/frontend/index.html

## Tech stack

- **Frontend:** vanilla HTML, CSS, and JavaScript — no frameworks, no build step. The entire frontend is a single file, `frontend/index.html`, which switches between views (home, login, register, courses) with JavaScript rather than separate page loads, while still making real `fetch()` calls to the PHP backend for every action.
- **Backend:** PHP 8, organized as small single-purpose endpoint files under `backend/`.
- **Database:** MySQL locally (via PDO), PostgreSQL in production on Render (via PDO) — the backend detects which one it's talking to and adjusts the small handful of database-specific SQL differences automatically. See `backend/db.php`.
- **Testing:** PHPUnit, covering the pure-logic library functions in `backend/lib/`.
- **Deployment:** Dockerized (`Dockerfile`) and deployed on [Render](https://render.com) via a `render.yaml` Blueprint, which provisions the web service and a managed Postgres database together.

## Features currently working

- Registration restricted to `@student.savannahstate.edu` emails, with bcrypt password hashing
- Login with session-based authentication (`HttpOnly` cookies, session regeneration on login to prevent session fixation)
- Logout
- Adding/removing courses, with a type-ahead search against an existing course catalog or adding a new course on the fly
- Setting preferred study locations and a weekly availability grid
- All of the above is backed by a real, persistent database — not mocked data

## Features planned but not yet built

- Matching students who share a course (`buddy_requests` table and matching logic exist in the schema, but no endpoint/UI yet)
- Contact-info reveal only after a mutual buddy request is accepted
- The AI study mascot chat (`mascot_messages` table exists in the schema; not yet wired to an LLM)

## Project structure

```
find-a-buddy/
├── frontend/
│   └── index.html          # The entire frontend: home, login, register, and courses views
├── backend/
│   ├── register.php        # Create account
│   ├── login.php            # Authenticate, start session
│   ├── logout.php           # Destroy session
│   ├── get_user_info.php    # Return the logged-in user's own profile
│   ├── get_courses.php      # Search the course catalog / list a user's courses
│   ├── save_courses.php     # Add/remove a user's courses, save location/availability prefs
│   ├── db.php                # PDO connection (MySQL locally, Postgres on Render)
│   ├── check_session.php     # require_login() guard used by every protected endpoint
│   ├── session_bootstrap.php # Session cookie hardening (HttpOnly, SameSite)
│   ├── config.example.php    # Template for local DB credentials (real config.php is gitignored)
│   ├── schema.sql            # MySQL schema
│   ├── schema.postgres.sql   # PostgreSQL schema (used on Render)
│   └── lib/
│       ├── validation.php    # Registration input validation (unit tested)
│       ├── availability.php  # Availability encode/decode/overlap logic (unit tested)
│       └── matching.php      # Shared-course matching logic (unit tested)
├── tests/                    # PHPUnit tests for backend/lib/
├── Dockerfile                # Builds the PHP+Apache image Render deploys
└── render.yaml                # Render Blueprint: defines the web service + Postgres database
```

## Running it locally (MySQL via Laragon)

1. Install [Laragon](https://laragon.org/download/) to the default `C:\laragon` path, then start Apache + MySQL from its control panel.
2. Place this project at `C:\laragon\www\find-a-buddy`.
3. Import the schema: open Laragon → **Database** (HeidiSQL) and run `backend/schema.sql`, or from a terminal: `mysql -u root find_a_buddy < backend/schema.sql`.
4. Copy `backend/config.example.php` to `backend/config.php`. The defaults match a stock Laragon MySQL install (user `root`, empty password) and should work as-is.
5. Install PHP dev dependencies: `composer install`.
6. Visit `http://find-a-buddy.test/frontend/index.html` in your browser.

## Running the tests

```bash
php vendor/bin/phpunit
```

## Deploying (Render)

The included `render.yaml` defines everything needed: a free web service built from the `Dockerfile`, and a free PostgreSQL database, wired together automatically via Render's Blueprint feature (`New +` → `Blueprint`, point it at this repo). After the first deploy, the Postgres tables need to be created once from `backend/schema.postgres.sql` (there's no admin UI for this in the free tier, so it's run via a one-time script against the live database).
