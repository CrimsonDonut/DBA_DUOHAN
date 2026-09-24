CREATE TABLE students_demo (
    student_id SERIAL PRIMARY KEY,
    student_no VARCHAR(20) NOT NULL UNIQUE,
    student_name VARCHAR(100) NOT NULL,
    email VARCHAR(120)
);
