# Find a Buddy — Design Spec

## Purpose

A web app that helps Savannah State University (SSU) students find compatible study partners for their current courses. Mobile-first, works on desktop too.

## Tech Stack

- **Frontend:** HTML5, CSS3 (flexbox/grid, responsive/mobile-first), vanilla JavaScript (fetch-based, no jQuery)
- **Backend:** PHP 8+, session-based authentication
- **Database:** MySQL 8.0
- **Hosting target:** standard shared PHP/MySQL hosting (e.g. Hostinger)
- **Local dev:** Laragon/XAMPP (Apache/Nginx + MySQL + PHP already installed by the user)
- **AI Mascot:** server-side call from PHP to the Anthropic Claude API, model `claude-haiku-4-5-20251001`. API key stored in `backend/config.php`, gitignored, not committed. User does not yet have a key — README documents how to obtain one and where to paste it.

## Folder Structure

```
find-a-buddy/
├── frontend/
│   ├── index.html          (landing / redirect)
│   ├── login.html
│   ├── register.html
│   ├── courses.html        (post/manage current courses — part of profile setup)
│   ├── buddy.html          (Find a Buddy: search + incoming/outgoing requests)
│   ├── mascot.html         (AI Study Mascot chat)
│   ├── profile.html
│   ├── app.js              (shared state/controller logic)
│   ├── api.js              (fetch wrappers to backend)
│   ├── buddyView.js
│   ├── mascotView.js
├── static/
│   ├── main.css
│   ├── login.css
├── backend/
│   ├── db.php                    (PDO connection)
│   ├── register.php
│   ├── login.php
│   ├── logout.php
│   ├── check_session.php
│   ├── get_user_info.php
│   ├── save_courses.php          (add/update a student's course list)
│   ├── get_courses.php
│   ├── search_buddies.php        (find students in same course/section)
│   ├── send_buddy_request.php
│   ├── respond_buddy_request.php (accept/decline)
│   ├── get_buddy_requests.php    (incoming + outgoing + accepted)
│   ├── mascot_chat.php           (proxies to AI API, stores conversation history)
│   └── config.php                (DB creds + AI API key, gitignored)
└── uploads/ (if profile photos are added later)
```

## Database Schema

```sql
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,       -- must end in @savannahstate.edu
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  preferred_locations VARCHAR(255),          -- e.g. "Library, Student Union"
  availability TEXT,                         -- freeform or structured JSON of days/times
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(20) NOT NULL,          -- e.g. "CSCI 3350"
  section VARCHAR(10),
  course_name VARCHAR(255)
);

CREATE TABLE user_courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_id INT NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_course (user_id, course_id)
);

CREATE TABLE buddy_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  receiver_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_id INT REFERENCES courses(id),
  status ENUM('pending','accepted','declined') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE mascot_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  sender ENUM('user','mascot') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Pages & Functionality

### 1. Register / Login

- Register requires an `@savannahstate.edu` email, password, first/last name.
- On first login after registering, route to course setup.
- Session-based auth via PHP `$_SESSION`; `check_session.php` guards all other pages.

### 2. Post Current Courses (part of profile)

- Student adds courses by course code + section (typeahead/autocomplete against the `courses` table; allow adding a new course if not found).
- Student sets preferred study locations (checkboxes: Library, Student Union, Dorm Lounge, Online) and general availability (checkboxes for Mon–Fri × Morning/Afternoon/Evening).
- Save via `save_courses.php`; list current courses with a remove option.

### 3. Find a Buddy

- Search/browse students who share at least one course, matched via `user_courses`.
- Show only: first name, last initial, shared course(s), and overlapping availability — no email/contact info until a request is accepted.
- "Send Buddy Request" button per result → `send_buddy_request.php`.
- Tabs for: Incoming Requests (accept/decline), Sent Requests (pending), My Buddies (accepted — contact info/email becomes visible here so they can coordinate).

### 4. AI Study Mascot

- Simple chat UI: message list + input box.
- `mascot_chat.php` receives the user's message, appends recent conversation history (last ~10 messages) for context, sends it to the Claude API (model `claude-haiku-4-5-20251001`) with a system prompt establishing a friendly, encouraging campus-mascot persona focused on study tips/motivation (not general Q&A), and returns the reply.
- Store both user and mascot messages in `mascot_messages` for persistence across sessions.
- If the API call fails or times out, show a canned friendly fallback message rather than an error.

## Styling

- Mobile-first responsive layout across all pages, applied in a dedicated styling pass (build step 6) after functionality is working.
- SSU brand colors: navy blue and orange/gold, applied via `static/main.css` and `static/login.css`.

## Security Requirements

- Passwords hashed with `password_hash()` / verified with `password_verify()`.
- All SQL via PDO prepared statements — no string-concatenated queries.
- Email domain restricted to `@savannahstate.edu` at registration.
- Session cookies `HttpOnly`; regenerate session ID on login.
- Contact info (email) hidden from search results and only revealed once a buddy request is accepted.
- `config.php` (DB credentials, AI API key) excluded from version control via `.gitignore`.

## Local Development

- User has Laragon/XAMPP installed locally (Apache/Nginx + MySQL + PHP).
- README will document: importing the schema, copying `config.php` from a `config.example.php` template, filling in local DB creds, obtaining an Anthropic API key and where to paste it, and pointing the local vhost/document root at this project.

## Build Order

1. `config.php` + `db.php` + schema migration script.
2. Register/login/session pages.
3. Course posting (profile setup) page + endpoints.
4. Buddy search + request endpoints, then the Find a Buddy page.
5. Mascot chat endpoint + page.
6. Styling pass (responsive layout, mobile-first, SSU colors) across all pages.
7. Manual test pass: register two test accounts sharing a course, send/accept a request, confirm contact info reveal, test mascot chat and history persistence.
