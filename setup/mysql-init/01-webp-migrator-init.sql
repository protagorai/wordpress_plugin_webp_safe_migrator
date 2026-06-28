-- WebP Safe Migrator - MySQL initialisation for the development app stack.
--
-- The application database itself is created automatically by the MySQL image
-- from the MYSQL_DATABASE environment variable (see setup/docker/docker-compose.yml,
-- default: `wordpress`). This script only applies safe, idempotent tuning to that
-- database. If you override WORDPRESS_DB_NAME, update the database name below to match.
--
-- NOTE: This file intentionally does NOT touch mysql.general_log and does NOT
-- reference a separate test database; both caused init failures previously
-- (see review.md CRIT-7). Integration tests use their own throwaway database
-- (setup/docker/docker-compose.test.yml), not this stack.

-- Ensure full Unicode support on the WordPress database.
ALTER DATABASE `wordpress` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Allow large serialized option / postmeta writes during big migrations.
SET GLOBAL max_allowed_packet = 67108864; -- 64MB
