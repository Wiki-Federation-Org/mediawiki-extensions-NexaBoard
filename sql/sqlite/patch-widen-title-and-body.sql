-- SQLite does not enforce declared column widths, so nothing was ever truncated
-- there and no column change is required. This patch exists so the updater has
-- a file to point at on every dialect.
SELECT 1;
