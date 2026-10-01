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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
