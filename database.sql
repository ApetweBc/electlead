CREATE DATABASE IF NOT EXISTS electlead CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE electlead;

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS voters (
  voter_id VARCHAR(50) PRIMARY KEY,
  full_name VARCHAR(200) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS nominations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  candidate_name VARCHAR(200) NOT NULL,
  criminal_check ENUM('pending', 'pass', 'fail') NOT NULL DEFAULT 'pending',
  character_check ENUM('pending', 'pass', 'fail') NOT NULL DEFAULT 'pending',
  status ENUM('nominated', 'verified', 'rejected') NOT NULL DEFAULT 'nominated',
  committee_notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_nominations_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  CONSTRAINT uq_nominations_category_candidate UNIQUE (category_id, candidate_name)
);

CREATE TABLE IF NOT EXISTS nomination_nominators (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomination_id INT NOT NULL,
  nominator_name VARCHAR(200) NOT NULL,
  CONSTRAINT fk_nominators_nomination FOREIGN KEY (nomination_id) REFERENCES nominations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS voter_participation (
  voter_id VARCHAR(50) NOT NULL,
  category_id INT NOT NULL,
  cast_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (voter_id, category_id),
  CONSTRAINT fk_participation_voter FOREIGN KEY (voter_id) REFERENCES voters(voter_id) ON DELETE CASCADE,
  CONSTRAINT fk_participation_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ballots (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  nomination_id INT NOT NULL,
  cast_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ballots_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_ballots_nomination FOREIGN KEY (nomination_id) REFERENCES nominations(id) ON DELETE CASCADE
);

INSERT INTO admins (username, password_hash)
VALUES ('Root', '$2y$12$zIt4LwSdG.o4w.7E1nAfjOHROhBhD1aKwrtqRXp5I.cTv9Rfv1pmq')
ON DUPLICATE KEY UPDATE username = VALUES(username);

INSERT INTO categories (name)
VALUES ('President'), ('Vice President'), ('Secretary')
ON DUPLICATE KEY UPDATE name = VALUES(name);
