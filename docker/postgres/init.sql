-- PostgreSQL init script — runs automatically on first container start.
-- Executed against the database named by POSTGRES_DB.

-- Track slow queries and query patterns (requires shared_preload_libraries
-- set on the postgres command line in compose.yaml).
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Trigram indexes for fuzzy text search (LIKE '%foo%', similarity(), etc.)
CREATE EXTENSION IF NOT EXISTS pg_trgm;
