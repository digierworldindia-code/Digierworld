# Database migrations

Changes to the database after version 1.0.0 go here as plain SQL files, named so
they sort in order:

    0001_add_part_family.sql
    0002_widen_remarks.sql

`php bin/qms.php migrate` applies every file that is not yet recorded in the
`schema_migrations` table, in name order, and records it. The base schema
(`database/schema/qms_schema.sql`) is recorded as `0000_qms_schema`.

Rules: never edit a file that was already released; use `DELIMITER $$ … $$` for
triggers (the runner understands it); keep data changes and schema changes in
separate files; test on a copy of the production database first.
