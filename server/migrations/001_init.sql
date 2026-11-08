-- Superhero P.R. - initial schema. All tables prefixed prg_ so they coexist
-- with The Historians. Run once via cPanel phpMyAdmin.
-- prg_shim_users is the placeholder auth table; it is dropped when the
-- Historians users table is wired in.

CREATE TABLE prg_shim_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(24) NOT NULL UNIQUE,
  display_name VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE prg_card_sets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(32) NOT NULL UNIQUE,
  imported_by INT UNSIGNED NOT NULL,
  imported_at DATETIME NOT NULL,
  notes VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE prg_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_set_id INT UNSIGNED NOT NULL,
  kind ENUM('crisis','mandate','fallen','spin','location') NOT NULL,
  card_id VARCHAR(8) NOT NULL,
  data JSON NOT NULL,
  UNIQUE KEY uq_card (card_set_id, kind, card_id),
  CONSTRAINT fk_cards_set FOREIGN KEY (card_set_id) REFERENCES prg_card_sets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE prg_games (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  join_code CHAR(6) NOT NULL UNIQUE,
  status ENUM('lobby','active','finished','abandoned') NOT NULL,
  player_count TINYINT UNSIGNED NOT NULL,
  card_set_id INT UNSIGNED NOT NULL,
  rng_seed BIGINT UNSIGNED NOT NULL,
  state_version INT UNSIGNED NOT NULL DEFAULT 0,
  state MEDIUMTEXT NOT NULL,
  turn_user_id INT UNSIGNED NULL,
  result JSON NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY k_turn (turn_user_id, status),
  CONSTRAINT fk_games_set FOREIGN KEY (card_set_id) REFERENCES prg_card_sets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE prg_game_players (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id INT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_seat (game_id, seat),
  UNIQUE KEY uq_user (game_id, user_id),
  CONSTRAINT fk_gp_game FOREIGN KEY (game_id) REFERENCES prg_games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE prg_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id INT UNSIGNED NOT NULL,
  seq INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  action JSON NOT NULL,
  state_version_after INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_seq (game_id, seq),
  CONSTRAINT fk_actions_game FOREIGN KEY (game_id) REFERENCES prg_games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
