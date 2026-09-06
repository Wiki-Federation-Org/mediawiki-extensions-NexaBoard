-- Postgres maps both columns to TEXT, which is unbounded, so nothing was ever
-- truncated there and no change is required. This patch exists so the updater
-- has a file to point at on every dialect. See the MySQL patch of the same name.
SELECT 1;
