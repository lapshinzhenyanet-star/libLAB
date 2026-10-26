CREATE DATABASE IF NOT EXISTS library_is CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_is;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('reader','admin') NOT NULL DEFAULT 'reader',
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS books (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(160) NOT NULL,
    isbn VARCHAR(20) NOT NULL UNIQUE,
    genre VARCHAR(80) NOT NULL,
    publication_year SMALLINT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    icon VARCHAR(20) NOT NULL DEFAULT 'book',
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (title), INDEX (author)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS copies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    archived_at DATETIME NULL,
    UNIQUE KEY copy_book (id, book_id),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    book_id INT UNSIGNED NOT NULL,
    status ENUM('pending','fulfilled','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    pending_user INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN user_id ELSE NULL END) STORED,
    UNIQUE KEY one_pending_request (pending_user, book_id),
    UNIQUE KEY request_reader_book (id, user_id, book_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    book_id INT UNSIGNED NOT NULL,
    copy_id INT UNSIGNED NOT NULL,
    request_id INT UNSIGNED NULL UNIQUE,
    issued_by INT UNSIGNED NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_date DATE NOT NULL,
    returned_at DATETIME NULL,
    returned_by INT UNSIGNED NULL,
    active_copy INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN returned_at IS NULL THEN copy_id ELSE NULL END) STORED,
    active_user INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN returned_at IS NULL THEN user_id ELSE NULL END) STORED,
    UNIQUE KEY one_active_loan_per_copy (active_copy),
    UNIQUE KEY one_active_book_per_reader (active_user, book_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
    CONSTRAINT loan_copy_book FOREIGN KEY (copy_id, book_id) REFERENCES copies(id, book_id) ON DELETE RESTRICT,
    CONSTRAINT loan_request_reader_book FOREIGN KEY (request_id, user_id, book_id) REFERENCES requests(id, user_id, book_id) ON DELETE RESTRICT,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX (due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS extensions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    old_due_date DATE NOT NULL,
    new_due_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE RESTRICT,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identity_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (identity_hash, created_at)
) ENGINE=InnoDB;
