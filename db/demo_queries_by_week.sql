-- =====================================================================
-- FinalLabChallengeWk4 - queries and tests required by the rubric,
-- organized by week. Run in pgAdmin's Query Tool, ONE block at a time
-- (select the block, press F5) so you can show each result.
--
-- Prerequisite: db/week07_10_update.sql has been run.
--
-- Notes
--   * Blocks marked "EXPECTED TO FAIL" must be rejected by PostgreSQL.
--     pgAdmin stops a script at the first error, so run them singly.
--   * After the failed multi-step transaction in Week 8, run ROLLBACK;
--   * Block 8.5a COMMITs a real row; the optional cleanup is at the end.
-- =====================================================================


-- #####################################################################
-- WEEK 6 - DATA RETRIEVAL, FILTERING, SORTING AND GROUPING
-- #####################################################################

-- 6.1 Equipment Inventory Listing (SELECT, aliases, ORDER BY DESC + ASC)
SELECT equipment_name      AS equipment,
       property_no         AS property_number,
       quantity_available  AS available_quantity
FROM equipment
ORDER BY quantity_available DESC, equipment_name ASC;

-- 6.2 Filtered Borrowing Records
-- (a) by status
SELECT t.borrow_id, b.borrower_no, b.full_name AS borrower_name,
       t.borrow_date, t.due_date, t.status
FROM borrow_transactions AS t
JOIN borrowers AS b ON b.borrower_id = t.borrower_id
WHERE t.status = 'Active'
ORDER BY t.borrow_date DESC;

-- (b) by selected date range
SELECT t.borrow_id, b.borrower_no, b.full_name AS borrower_name,
       t.borrow_date, t.due_date, t.status
FROM borrow_transactions AS t
JOIN borrowers AS b ON b.borrower_id = t.borrower_id
WHERE t.borrow_date BETWEEN DATE '2026-09-01' AND DATE '2026-09-15'
ORDER BY t.borrow_date, t.borrow_id;

-- (c) AND / OR / IN combined, plus a text search
SELECT t.borrow_id, b.borrower_no, b.full_name AS borrower_name,
       b.borrower_type, t.borrow_date, t.status
FROM borrow_transactions AS t
JOIN borrowers AS b ON b.borrower_id = t.borrower_id
WHERE t.status IN ('Active', 'Overdue')
  AND (b.borrower_type = 'Student' OR b.borrower_type = 'Faculty')
  AND b.full_name ILIKE '%an%'
ORDER BY t.borrow_date DESC, t.borrow_id DESC;

-- 6.3 DISTINCT (borrower types that have at least one transaction)
SELECT DISTINCT b.borrower_type AS borrower_type_with_loans
FROM borrowers AS b
JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
ORDER BY b.borrower_type;

-- 6.4 Borrower Type Summary (GROUP BY)
SELECT borrower_type, COUNT(*) AS borrowers
FROM borrowers
GROUP BY borrower_type
ORDER BY borrowers DESC, borrower_type;

-- 6.5 Transaction Status Summary (every status, including those with 0)
SELECT s.status, COUNT(t.borrow_id) AS transactions
FROM (VALUES ('Active'), ('Returned'), ('Overdue'), ('Cancelled')) AS s(status)
LEFT JOIN borrow_transactions AS t ON t.status = s.status
GROUP BY s.status
ORDER BY transactions DESC, s.status;

-- 6.6 Grouped Borrowing Analysis
--     WHERE filters rows BEFORE grouping; HAVING filters groups AFTER.
SELECT b.borrower_type, COUNT(*) AS transactions
FROM borrow_transactions AS t
JOIN borrowers AS b ON b.borrower_id = t.borrower_id
WHERE t.status <> 'Cancelled'
GROUP BY b.borrower_type
HAVING COUNT(*) >= 2
ORDER BY transactions DESC, b.borrower_type;

-- 6.7 Show that results change when data changes (run, then re-run 6.4/6.5)
-- INSERT INTO borrowers (borrower_no, full_name, borrower_type)
-- VALUES ('AN-2026-900', 'Sample Faculty', 'Faculty');
-- UPDATE borrow_transactions SET status = 'Returned' WHERE borrow_id = 2;


-- #####################################################################
-- WEEK 7 - JOINS, AGGREGATION, SET OPERATIONS, SQL FUNCTIONS
-- #####################################################################

-- 7.1 Complete Borrowing Report (INNER JOINs + string/date formatting)
SELECT UPPER(b.borrower_no)                      AS borrower_number,
       INITCAP(TRIM(b.full_name))                AS borrower_name,
       e.equipment_name,
       i.quantity                                AS borrowed_quantity,
       TO_CHAR(t.borrow_date, 'Mon DD, YYYY')    AS borrowed_on,
       TO_CHAR(t.due_date,    'Mon DD, YYYY')    AS due_on,
       t.status
FROM borrow_transactions AS t
JOIN borrowers    AS b ON b.borrower_id  = t.borrower_id
JOIN borrow_items AS i ON i.borrow_id    = t.borrow_id
JOIN equipment    AS e ON e.equipment_id = i.equipment_id
ORDER BY t.borrow_id, e.equipment_name;

-- 7.2 Equipment Borrowing Summary (COUNT, SUM, AVG, MIN, MAX per item)
SELECT e.property_no,
       e.equipment_name,
       COUNT(DISTINCT t.borrow_id)                AS transaction_count,
       COALESCE(SUM(i.quantity), 0)               AS total_quantity_borrowed,
       COALESCE(ROUND(AVG(i.quantity), 2), 0)     AS average_per_borrowing,
       MIN(i.quantity)                            AS smallest_borrowing,
       MAX(i.quantity)                            AS largest_borrowing
FROM equipment AS e
LEFT JOIN borrow_items        AS i ON i.equipment_id = e.equipment_id
LEFT JOIN borrow_transactions AS t ON t.borrow_id    = i.borrow_id
GROUP BY e.equipment_id, e.property_no, e.equipment_name
ORDER BY total_quantity_borrowed DESC, e.equipment_name;

-- 7.3 Borrowers Without Transactions (LEFT JOIN ... IS NULL)
SELECT b.borrower_no,
       CONCAT_WS(' - ', b.borrower_no, b.full_name) AS borrower,
       b.borrower_type
FROM borrowers AS b
LEFT JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
WHERE t.borrow_id IS NULL
ORDER BY b.borrower_no;

-- 7.4 Equipment Without Borrowing History
SELECT e.property_no, e.equipment_name, e.quantity_available
FROM equipment AS e
LEFT JOIN borrow_items AS i ON i.equipment_id = e.equipment_id
WHERE i.borrow_item_id IS NULL
ORDER BY e.property_no;

-- 7.5 Combined Borrower Activity
--     Set A: borrowers with an open loan.  Set B: borrowers who borrowed
--     on/after 2026-09-01.  Both return (borrower_no, full_name).
-- (a) UNION ALL - keeps duplicates
SELECT b.borrower_no, b.full_name
FROM borrowers AS b JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
WHERE t.status IN ('Active', 'Overdue')
UNION ALL
SELECT b.borrower_no, b.full_name
FROM borrowers AS b JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
WHERE t.borrow_date >= DATE '2026-09-01'
ORDER BY borrower_no;

-- (b) UNION - removes duplicates
SELECT b.borrower_no, b.full_name
FROM borrowers AS b JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
WHERE t.status IN ('Active', 'Overdue')
UNION
SELECT b.borrower_no, b.full_name
FROM borrowers AS b JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
WHERE t.borrow_date >= DATE '2026-09-01'
ORDER BY borrower_no;

-- (c) Verify the difference: UNION ALL returns more rows than UNION
SELECT 'UNION' AS operation, COUNT(*) AS rows_returned
FROM (
    SELECT b.borrower_no FROM borrowers b
      JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
     WHERE t.status IN ('Active', 'Overdue')
    UNION
    SELECT b.borrower_no FROM borrowers b
      JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
     WHERE t.borrow_date >= DATE '2026-09-01'
) AS u
UNION ALL
SELECT 'UNION ALL', COUNT(*)
FROM (
    SELECT b.borrower_no FROM borrowers b
      JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
     WHERE t.status IN ('Active', 'Overdue')
    UNION ALL
    SELECT b.borrower_no FROM borrowers b
      JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
     WHERE t.borrow_date >= DATE '2026-09-01'
) AS ua;

-- 7.6 Monthly Borrowing Report (DATE_TRUNC + TO_CHAR)
SELECT TRIM(TO_CHAR(DATE_TRUNC('month', t.borrow_date), 'Month YYYY')) AS month,
       COUNT(DISTINCT t.borrow_id)  AS transactions,
       COALESCE(SUM(i.quantity), 0) AS total_items
FROM borrow_transactions AS t
LEFT JOIN borrow_items AS i ON i.borrow_id = t.borrow_id
GROUP BY DATE_TRUNC('month', t.borrow_date)
ORDER BY DATE_TRUNC('month', t.borrow_date);

-- 7.7 Overdue records (CURRENT_DATE, EXTRACT, TO_CHAR)
SELECT t.borrow_id,
       b.borrower_no,
       TO_CHAR(t.due_date, 'Mon DD, YYYY')   AS due_on,
       EXTRACT(MONTH FROM t.due_date)::int   AS due_month,
       CURRENT_DATE - t.due_date             AS days_overdue,
       t.status
FROM borrow_transactions AS t
JOIN borrowers AS b ON b.borrower_id = t.borrower_id
WHERE t.status = 'Overdue'
   OR (t.status = 'Active' AND t.due_date < CURRENT_DATE)
ORDER BY days_overdue DESC, t.borrow_id;


-- #####################################################################
-- WEEK 8 - VIEWS, TRANSACTIONS, CONSTRAINTS, CONDITIONAL SQL
-- #####################################################################

-- 8.1 View: vw_borrowing_details
SELECT * FROM vw_borrowing_details ORDER BY borrow_id DESC, property_no;

-- 8.2 View: vw_equipment_usage (includes CASE availability class)
SELECT * FROM vw_equipment_usage ORDER BY property_no;

-- 8.3 Mathematical functions - reuses vw_equipment_usage
--     NULLIF prevents division by zero when stock is 0.
SELECT equipment_name,
       quantity_available,
       quantity_out,
       ROUND(quantity_out * 100.0 / NULLIF(quantity_available, 0), 1) AS percent_out,
       ABS(quantity_available - quantity_out)                          AS stock_gap
FROM vw_equipment_usage
ORDER BY percent_out DESC NULLS LAST, equipment_name;

-- 8.4 CASE expression - conditions are ordered most specific first
SELECT t.borrow_id,
       b.full_name AS borrower,
       t.due_date,
       t.status,
       CASE
           WHEN t.status = 'Returned'  THEN 'Returned'
           WHEN t.status = 'Cancelled' THEN 'Cancelled'
           WHEN t.status = 'Overdue' OR t.due_date < CURRENT_DATE THEN 'Overdue'
           WHEN t.due_date <= CURRENT_DATE + 3 THEN 'Due Soon'
           ELSE 'On Loan'
       END AS loan_class
FROM borrow_transactions AS t
JOIN borrowers AS b ON b.borrower_id = t.borrower_id
ORDER BY t.borrow_id;


-- =====================================================================
-- WEEK 8 - REQUIRED TRANSACTION TESTS
-- =====================================================================

-- ---------------------------------------------------------------------
-- 8.5a Successful borrowing transaction  (header + items saved with COMMIT)
-- ---------------------------------------------------------------------
BEGIN;

INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status)
VALUES (5, CURRENT_DATE, CURRENT_DATE + 7, 'Active');

INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
VALUES (currval('borrow_transactions_borrow_id_seq'), 2, 1),
       (currval('borrow_transactions_borrow_id_seq'), 4, 2);

-- Visible inside the transaction before it is committed
SELECT * FROM vw_borrowing_details
WHERE borrow_id = currval('borrow_transactions_borrow_id_seq');

COMMIT;

-- Still there after COMMIT (a new session would see it too)
SELECT * FROM vw_borrowing_details ORDER BY borrow_id DESC, property_no LIMIT 5;


-- ---------------------------------------------------------------------
-- 8.5b Voluntary ROLLBACK (header and items are both discarded)
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS transactions_before FROM borrow_transactions;

BEGIN;

INSERT INTO borrow_transactions (borrower_id, due_date)
VALUES (1, CURRENT_DATE + 7);

INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
VALUES (currval('borrow_transactions_borrow_id_seq'), 6, 1);

ROLLBACK;

SELECT COUNT(*) AS transactions_after FROM borrow_transactions;   -- same number


-- ---------------------------------------------------------------------
-- 8.5c FAILED multi-step borrowing transaction, rolled back
--    Step 2 asks for more than the stock available, so it fails.
--    In psql the script continues; in pgAdmin run the lines one by one.
--    After the error the transaction is aborted: run ROLLBACK.
-- ---------------------------------------------------------------------
BEGIN;

INSERT INTO borrow_transactions (borrower_id, due_date)
VALUES (1, CURRENT_DATE + 7);                         -- step 1 succeeds

INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
VALUES (currval('borrow_transactions_borrow_id_seq'), 7, 999);  -- step 2 FAILS

ROLLBACK;

SELECT COUNT(*) AS transactions_after_failure FROM borrow_transactions;
-- No incomplete header was left behind:
SELECT t.borrow_id
FROM borrow_transactions t
LEFT JOIN borrow_items i ON i.borrow_id = t.borrow_id
WHERE i.borrow_item_id IS NULL;                        -- expect 0 rows


-- =====================================================================
-- WEEK 8 - REQUIRED CONSTRAINT TESTS  (each statement must be rejected)
-- If a statement errors inside pgAdmin, simply run the next one.
-- =====================================================================

-- 8.6a Duplicate property number            -> unique violation (23505)
INSERT INTO equipment (property_no, equipment_name, quantity_available)
VALUES ('GYM-TCM-01', 'Duplicate Treadmill', 1);

-- 8.6b Negative available quantity          -> check violation (23514)
INSERT INTO equipment (property_no, equipment_name, quantity_available)
VALUES ('GYM-NEG-01', 'Negative Stock Item', -5);

-- 8.6c Invalid foreign key (borrower)       -> FK violation (23503)
INSERT INTO borrow_transactions (borrower_id, due_date)
VALUES (999, CURRENT_DATE + 7);

-- 8.6d Invalid foreign key (equipment)      -> FK violation (23503)
INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
VALUES (2, 999, 1);

-- 8.6e Duplicate equipment in one transaction (borrow 2 already has item 3)
--                                          -> unique violation (23505)
INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
VALUES (2, 3, 1);

-- 8.6f Invalid borrowed quantity            -> check violation (23514)
INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
VALUES (2, 2, 0);

-- 8.6g Invalid date order                   -> check violation (chk_due_date)
INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date)
VALUES (1, CURRENT_DATE, CURRENT_DATE - 1);

-- #####################################################################
-- WEEK 10 - FUNCTIONS AND TRIGGERS
-- #####################################################################
-- =====================================================================
-- WEEK 10 - FUNCTIONS (different inputs)
-- =====================================================================

-- 10.1 SQL function fn_total_borrowed (status rule: Active + Overdue only)
SELECT equipment_id, equipment_name, fn_total_borrowed(equipment_id) AS units_out
FROM equipment
ORDER BY equipment_id;

SELECT fn_total_borrowed(1) AS treadmill, fn_total_borrowed(3) AS barbell,
       fn_total_borrowed(2) AS squat_rack_never_borrowed;

-- 10.2 PL/pgSQL function fn_check_equipment_availability
SELECT fn_check_equipment_availability(2, 1)   AS squat_rack_ask_1,    -- true  (4 available)
       fn_check_equipment_availability(2, 4)   AS squat_rack_ask_4,    -- true
       fn_check_equipment_availability(2, 5)   AS squat_rack_ask_5,    -- false
       fn_check_equipment_availability(2, 0)   AS squat_rack_ask_0;    -- false

-- Error branch: equipment that does not exist (expected to fail)
SELECT fn_check_equipment_availability(999, 1);


-- =====================================================================
-- WEEK 10 - REQUIRED LIVE TRIGGER TESTS  (borrow_items)
-- Uses borrow_id 2 (an Active transaction) and equipment 5 (3 available).
-- =====================================================================

SELECT COUNT(*) AS audit_rows_start FROM borrow_items_audit;

-- 10.3a Valid quantity: accepted, and an INSERT audit row is generated
INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
VALUES (2, 5, 2);

SELECT * FROM borrow_items_audit ORDER BY audit_id DESC LIMIT 1;

-- 10.3b Quantity exceeding available stock: rejected by the BEFORE trigger,
--     and stock stays consistent (no row added, no audit row, stock same)
SELECT quantity_available FROM equipment WHERE equipment_id = 6;      -- 8
INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
VALUES (2, 6, 50);                                    -- expected to FAIL
SELECT quantity_available FROM equipment WHERE equipment_id = 6;      -- still 8
SELECT COUNT(*) FROM borrow_items WHERE borrow_id = 2 AND equipment_id = 6;  -- 0

-- 10.3c Update a quantity: validated (try the failing one first), then audited
UPDATE borrow_items SET quantity = 500
WHERE borrow_id = 2 AND equipment_id = 5;             -- expected to FAIL

UPDATE borrow_items SET quantity = 3
WHERE borrow_id = 2 AND equipment_id = 5;             -- valid (3 available)

SELECT audit_id, operation, old_quantity, new_quantity, changed_at
FROM borrow_items_audit
WHERE operation = 'UPDATE'
ORDER BY audit_id DESC LIMIT 1;                       -- shows 2 -> 3

-- 10.3d Delete a borrowing item: audit records the old values
DELETE FROM borrow_items WHERE borrow_id = 2 AND equipment_id = 5;

SELECT audit_id, operation, old_borrow_id, old_equipment_id, old_quantity, changed_at
FROM borrow_items_audit
WHERE operation = 'DELETE'
ORDER BY audit_id DESC LIMIT 1;

-- 10.3e Trigger automation: every change above was logged without any
--     INSERT into borrow_items_audit being written by hand
SELECT audit_id, borrow_item_id, operation,
       old_quantity, new_quantity, changed_by, changed_at
FROM borrow_items_audit
ORDER BY audit_id;

-- 10.3f Other BEFORE rule: future borrow date (expected to FAIL)
INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date)
VALUES (1, CURRENT_DATE + 1, CURRENT_DATE + 8);


-- #####################################################################
-- OPTIONAL CLEANUP of the row COMMITted in block 8.5a
-- (the delete is itself recorded by the audit trigger)
-- #####################################################################
-- DELETE FROM borrow_transactions
-- WHERE borrower_id = 5 AND borrow_date = CURRENT_DATE AND status = 'Active'
--   AND borrow_id = (SELECT MAX(borrow_id) FROM borrow_transactions);
