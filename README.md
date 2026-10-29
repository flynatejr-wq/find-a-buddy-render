# Find a Buddy

A study-buddy matching web app for Savannah State University students. Students register with an `@student.savannahstate.edu` email, set up a profile, add their courses, and get matched with classmates who share a course so they can study together.

**Live demo:** https://find-a-buddy-yu1y.onrender.com/frontend/index.html
*(Free hosting: the first load after a quiet period can take ~20 seconds.)*

## Tech stack

- **Frontend:** vanilla HTML, CSS and JavaScript. No frameworks and no build step. The whole app is one file, `frontend/index.html`, which switches between views with JavaScript and makes real `fetch()` calls to the PHP backend.
- **Backend:** PHP 8, as small single-purpose endpoint files under `backend/`, using PDO prepared statements.
- **Database:** MySQL locally, PostgreSQL in production on Render. `backend/db.php` detects which one it is talking to and handles the small SQL differences.
- **Testing:** PHPUnit tests for the logic in `backend/lib/`.
- **Deployment:** Docker (`Dockerfile`) on [Render](https://render.com), described by `render.yaml`.

## Features working now

1. **Home, register and login.** Registration is limited to `@student.savannahstate.edu` emails. Passwords are hashed with bcrypt, sessions use HttpOnly cookies, and the session ID is regenerated on login.
2. **Courses.** Search the course catalog or add a new course, pick preferred study locations, and set a weekly availability grid.
3. **Profile.** Major, year, study times, study style, study place and what you want help with, stored in the `profiles` table.
4. **Buddy matching.** Finds classmates who share a course and scores them out of 100 (shared courses, overlapping availability, same study style and place). You can send a buddy request to a classmate. Emails are never shown to other students.
5. **Admin view.** A read-only, key-protected dashboard of the database (see below).

Planned next: accepting or declining requests, messaging between buddies, password reset.

## Project structure

```
find-a-buddy/
├── frontend/
│   └── index.html            # The working app: home, login, register, profile, courses, find buddies
├── design-pages/             # Static design mockups (no backend): home, login, register,
│                             #   onboarding, profile, matches, messages, settings, reset-password
├── backend/
│   ├── register.php  login.php  logout.php  check_session.php  session_bootstrap.php
│   ├── get_user_info.php
│   ├── get_courses.php  save_courses.php
│   ├── get_profile.php  save_profile.php
│   ├── get_matches.php  send_request.php
│   ├── admin_view.php        # Read-only admin dashboard (needs ADMIN_KEY)
│   ├── db.php                # PDO connection (MySQL locally, Postgres on Render)
│   ├── config.example.php    # Template for local settings (real config.php is not committed)
│   ├── schema.sql            # MySQL schema
│   ├── schema.postgres.sql   # PostgreSQL schema
│   └── lib/                  # validation, availability, profile, matching (unit tested)
├── tests/                    # PHPUnit tests
├── Dockerfile
└── render.yaml               # Render Blueprint: web service + Postgres database
```

## Database tables

`users`, `courses`, `user_courses` (who is in which course), `profiles`, `buddy_requests`, and `mascot_messages` (reserved for a future feature). The `profiles` table is created automatically the first time it is used.

## Running it locally (MySQL via Laragon)

1. Install [Laragon](https://laragon.org/download/), then start Apache and MySQL.
2. Put this project at `C:\laragon\www\find-a-buddy`.
3. Create the tables by running `backend/schema.sql` in HeidiSQL, or: `mysql -u root < backend/schema.sql`.
4. Copy `backend/config.example.php` to `backend/config.php` (the defaults match a stock Laragon install) and set an `admin_key`.
5. `composer install`
6. Open `http://localhost/find-a-buddy/frontend/index.html`.

The `design-pages/*.html` files need no setup. Just open them in a browser.

## Admin view

Open `backend/admin_view.php` on the running site and enter the admin key. It shows user counts, profiles, courses, enrollments and buddy requests, with a filter box on each table. It is read-only and never queries password hashes.

The key is **not** in the code. Set it as the `ADMIN_KEY` environment variable (on Render: your service → Environment), or as `admin_key` in `backend/config.php` locally. If no key is set, the page is disabled.

## Running the tests

```bash
php vendor/bin/phpunit
```

## Deploying (Render)

`render.yaml` defines a free web service built from the `Dockerfile` plus a free PostgreSQL database. In Render choose **New + → Blueprint** and point it at this repo, then set the `ADMIN_KEY` environment variable. Create the base tables once from `backend/schema.postgres.sql`.
