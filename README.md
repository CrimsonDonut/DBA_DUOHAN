# DBA_DUOHAN

This project contains a simple PHP + PostgreSQL CRUD example for managing student records.

## Structure

- [db/students_demo.sql](db/students_demo.sql) — PostgreSQL table definition
- [config/db.php](config/db.php) — database connection setup
- [public/crud_demo.php](public/crud_demo.php) — beginner CRUD web interface

## Setup

1. Create the PostgreSQL database and user you want to use.
2. Run the SQL from [db/students_demo.sql](db/students_demo.sql) in pgAdmin or psql.
3. Update the connection settings in [config/db.php](config/db.php) if needed.
4. Start a local PHP server from the project root:

```bash
php -S localhost:8000 -t public
```

5. Open the browser at:

```text
http://localhost:8000/crud_demo.php
```

## Notes

- The script uses prepared statements to protect against SQL injection.
- The example includes create, read, update, and delete operations for the `students_demo` table.
