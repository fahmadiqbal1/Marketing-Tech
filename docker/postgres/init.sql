-- Enable extensions on the main database
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Create a separate database for tests so RefreshDatabase never touches production data
CREATE DATABASE marketing_tech_test;

-- Grant the app user full access to the test database
GRANT ALL PRIVILEGES ON DATABASE marketing_tech_test TO postgres;

-- Enable extensions on the test database as well
\connect marketing_tech_test
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
