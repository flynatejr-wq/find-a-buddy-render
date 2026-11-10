CREATE TABLE users (
  id SERIAL PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  preferred_locations VARCHAR(255),
  availability TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE courses (
  id SERIAL PRIMARY KEY,
  course_code VARCHAR(20) NOT NULL,
  section VARCHAR(10),
  course_name VARCHAR(255)
);

CREATE TABLE user_courses (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_id INT NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  UNIQUE (user_id, course_id)
);

CREATE TYPE buddy_request_status AS ENUM ('pending', 'accepted', 'declined');

CREATE TABLE buddy_requests (
  id SERIAL PRIMARY KEY,
  sender_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  receiver_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_id INT REFERENCES courses(id),
  status buddy_request_status DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TYPE mascot_sender AS ENUM ('user', 'mascot');

CREATE TABLE mascot_messages (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  sender mascot_sender NOT NULL,
  message TEXT NOT NULL,
  meta TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS profiles (
  user_id INT NOT NULL PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  major VARCHAR(100) NOT NULL,
  year VARCHAR(20) NOT NULL,
  study_times VARCHAR(100) NOT NULL DEFAULT '',
  study_style VARCHAR(50) NOT NULL,
  study_place VARCHAR(50) NOT NULL,
  help_text VARCHAR(500) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages (
  id SERIAL PRIMARY KEY,
  sender_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  receiver_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  body VARCHAR(1000) NOT NULL,
  is_read SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS study_sessions (
  id SERIAL PRIMARY KEY,
  proposer_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  buddy_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  topic VARCHAR(100) NOT NULL,
  session_date VARCHAR(10) NOT NULL,
  session_time VARCHAR(5) NOT NULL,
  place VARCHAR(50) NOT NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'proposed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_resets (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash CHAR(64) NOT NULL,
  expires_at BIGINT NOT NULL,
  used SMALLINT NOT NULL DEFAULT 0,
  created_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS course_progress (
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_id INT NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  status VARCHAR(10) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, course_id)
);

CREATE TABLE IF NOT EXISTS course_grades (
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_id INT NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  grade CHAR(1) NOT NULL,
  available_to_help SMALLINT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, course_id)
);

CREATE TABLE IF NOT EXISTS schedule_blocks (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_id INT NULL REFERENCES courses(id) ON DELETE CASCADE,
  label VARCHAR(60) NOT NULL DEFAULT '',
  day SMALLINT NOT NULL,
  start_min SMALLINT NOT NULL,
  end_min SMALLINT NOT NULL
);

CREATE TABLE IF NOT EXISTS planner_settings (
  user_id INT NOT NULL PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  hours SMALLINT NOT NULL,
  day_start SMALLINT NOT NULL,
  day_end SMALLINT NOT NULL
);

-- Lock the tables down. Supabase (and similar hosts) expose the public schema through a web API for
-- the roles "anon" and "authenticated". This app talks to the database directly as the table owner,
-- which is not affected, so no API access is wanted at all: switch on row level security with no
-- policies (blocks everything) and remove any grants those roles were given. Harmless on other hosts.
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE courses ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_courses ENABLE ROW LEVEL SECURITY;
ALTER TABLE buddy_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE mascot_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE study_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE password_resets ENABLE ROW LEVEL SECURITY;
ALTER TABLE course_progress ENABLE ROW LEVEL SECURITY;
ALTER TABLE course_grades ENABLE ROW LEVEL SECURITY;
ALTER TABLE schedule_blocks ENABLE ROW LEVEL SECURITY;
ALTER TABLE planner_settings ENABLE ROW LEVEL SECURITY;

DO $$
DECLARE r text;
BEGIN
  FOREACH r IN ARRAY ARRAY['anon', 'authenticated'] LOOP
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
      EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %I', r);
      EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM %I', r);
    END IF;
  END LOOP;
END
$$;
