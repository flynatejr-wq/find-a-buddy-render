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
5. **Accept or decline requests.** The student who receives a request answers it from **My Buddies**. Accepted buddies see each other's email; before that only first names are shown.
6. **Messaging.** A **Messages** page lists every conversation with unread counts, and each buddy also has a **Message** button. Accepted buddies can chat. Messages are limited to buddies, capped at 1,000 characters, shown as plain text, and the page checks for new ones every few seconds. A badge in the menu shows unanswered requests and unread messages.
7. **Study sessions.** Buddies can propose a time, place and topic, and the other person accepts or declines. Either can cancel. Dates are Eastern time and cannot be in the past.
8. **Account settings.** Edit your name, change your password (needs the current one), or delete your account and everything tied to it.
9. **Password reset.** "Forgot your password?" sends a one-time link that expires in 1 hour (see below). Only a hash of the link's token is stored.
10. **Admin view.** A key-protected dashboard of the database (see below).
11. **Grades, progress and tutor matching.** For each current course you mark **Passing** or **Failing, I would like help**. For finished courses you enter a final grade (A to F), which moves the course out of your current list. A grade of C or better counts as passed. Students who are failing a course are matched with students who passed it ("Passed CSCI 2231K, can help you"), even if the helper is no longer in the class, and they can send a buddy request. When you enter a passing grade, the app asks **"Are you available to buddy with students taking this class?"**. Only students who answered yes are offered to struggling classmates, and you can change your answer any time from the course list. Other students only ever see that you passed or could use help, never your grade.

12. **Study planner.** Enter your class times (and work or other commitments), a weekly study goal and the hours you are willing to study. The planner builds a week of study blocks that stay clear of your classes (with a 15 minute buffer), split into 30 to 90 minute blocks and spread over the week. Courses you marked **Failing** get the most time, **Passing** the least. If the goal does not fit, it tells you how much was left out.

13. **Ask Rowdy (AI study assistant).** A separate section where students chat with an AI built on the Claude API. Rowdy can answer questions about their courses, take their classes and times in plain language and save them to the planner (adding any missing courses), build the weekly study plan, and recommend study partners, showing plan and partner cards with a **Send request** button right in the chat. It works through tools that call the same code as the rest of the app, so it never guesses someone's free time. See *AI assistant setup* below.

Planned next: notifications, a report/block option.

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
│   ├── get_matches.php  send_request.php  respond_request.php  get_buddies.php
│   ├── get_messages.php  send_message.php
│   ├── get_sessions.php  propose_session.php  respond_session.php
│   ├── set_progress.php  save_grade.php  set_help_availability.php
│   ├── ai_chat.php  ai_history.php     # Ask Rowdy
│   ├── get_planner.php  save_block.php  save_planner_prefs.php
│   ├── update_account.php  change_password.php  delete_account.php
│   ├── request_reset.php  reset_password.php
│   ├── admin_view.php        # Read-only admin dashboard (needs ADMIN_KEY)
│   ├── db.php                # PDO connection (MySQL locally, Postgres on Render)
│   ├── config.example.php    # Template for local settings (real config.php is not committed)
│   ├── schema.sql            # MySQL schema
│   ├── schema.postgres.sql   # PostgreSQL schema
│   └── lib/                  # validation, availability, profile, matching, planner, grades, ai, ... (unit tested)
├── composer.json / composer.lock   # PHP libraries (the Anthropic SDK) and PHPUnit
├── tests/                    # PHPUnit tests
├── Dockerfile
└── render.yaml               # Render Blueprint: web service + Postgres database
```

## Database tables

`users`, `courses`, `user_courses` (who is in which course), `profiles`, `buddy_requests` (pending, accepted or declined), `messages` (buddy chat), `study_sessions`, `password_resets`, `course_progress` (passing or failing in a current course), `course_grades` (final grades for finished courses), `schedule_blocks` and `planner_settings` (the study planner), and `mascot_messages` (reserved for a future feature). The `profiles`, `messages`, `study_sessions`, `password_resets`, `course_progress`, `course_grades`, `schedule_blocks` and `planner_settings` tables are created automatically the first time they are used.

## Running it locally (MySQL via Laragon)

1. Install [Laragon](https://laragon.org/download/), then start Apache and MySQL.
2. Put this project at `C:\laragon\www\find-a-buddy`.
3. Create the tables by running `backend/schema.sql` in HeidiSQL, or: `mysql -u root < backend/schema.sql`.
4. Copy `backend/config.example.php` to `backend/config.php` (the defaults match a stock Laragon install) and set an `admin_key`.
5. `composer install` (installs PHPUnit and the Anthropic SDK)
6. Open `http://localhost/find-a-buddy/frontend/index.html`.

The `design-pages/*.html` files need no setup. Just open them in a browser.

## Admin view

Open `backend/admin_view.php` on the running site and enter the admin key. It shows user counts, profiles, courses, enrollments and buddy requests, with a filter box on each table. It only reads data, except for one action: **Create a password reset link** for a student. The link is shown once and only its hash is stored. It never queries password hashes.

The key is **not** in the code. Set it as the `ADMIN_KEY` environment variable (on Render: your service → Environment), or as `admin_key` in `backend/config.php` locally. If no key is set, the page is disabled.

## AI assistant setup

Ask Rowdy uses the official [Anthropic PHP SDK](https://github.com/anthropics/anthropic-sdk-php). It stays switched off (the page says so) until an API key is set. Create a key at https://console.anthropic.com, then add it on the server:

| Variable | Meaning |
|---|---|
| `ANTHROPIC_API_KEY` | Your Anthropic API key. Required. |
| `ANTHROPIC_MODEL` | Optional. Defaults to `claude-opus-5-5`. Use `claude-sonnet-5-5` or `claude-haiku-5-5` for a much lower cost per message. |
| `AI_EFFORT` | Optional. `low` (default, fastest and cheapest), `medium` or `high`. |
| `AI_DAILY_LIMIT_PER_USER` | Optional. Messages per student per day. Default 40. |
| `AI_DAILY_LIMIT_GLOBAL` | Optional. Messages across the whole site per day. Default 400. |

Locally you can instead put the key in `anthropic_api_key` in `backend/config.php`. The daily limits keep the bill bounded. Every message costs a little (a few cents on the default model, far less on the smaller ones), so set a spending limit in the Anthropic console too.

What Rowdy can see: the student's first name, major and year, current courses and whether they are passing, which completed courses they passed (not the grade), class times, study goal and free time. It never sees emails, last names or grades. Other students appear to it only as first name, year and course codes, and the system prompt tells it to treat that data as information, never as instructions. Messages are sent to Anthropic to generate a reply. Chat history is stored in `mascot_messages`.

## Password reset email

Reset links can be emailed with [Resend](https://resend.com). Set these environment variables on the server (on Render: Environment):

| Variable | Meaning |
|---|---|
| `RESEND_API_KEY` | Your Resend API key |
| `MAIL_FROM` | A sender address you have verified in Resend |
| `APP_URL` | The site's public address, for example `https://find-a-buddy-yu1y.onrender.com` |

`APP_URL` is used to build the link, so the link can never be pointed at another site through a forged request. If any of the three is missing, the Forgot password page says emails are not set up yet, and an admin can create a one-time link for a student from the admin page instead.

## Running the tests

```bash
php vendor/bin/phpunit
```

## Deploying (Render)

`render.yaml` defines a free web service built from the `Dockerfile` plus a free PostgreSQL database. In Render choose **New + → Blueprint** and point it at this repo, then set the `ADMIN_KEY` environment variable. Create the base tables once from `backend/schema.postgres.sql`.
