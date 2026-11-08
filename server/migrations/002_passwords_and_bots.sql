-- Passwords for the auth shim + bot seats for solo play.
-- Run in phpMyAdmin after deploying this delivery, then sign in immediately
-- with your admin username and a password: the FIRST password login on an
-- existing account claims it.

ALTER TABLE prg_shim_users
  ADD COLUMN pass_hash VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE prg_game_players
  ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0;
