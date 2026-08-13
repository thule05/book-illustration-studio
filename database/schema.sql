CREATE DATABASE IF NOT EXISTS book_illustration_studio
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE book_illustration_studio;

-- 1. USERS
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- 2. PROJECTS
CREATE TABLE projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,

    book_text LONGTEXT NULL,
    book_file_path VARCHAR(500) NULL,

    -- Gemini File API reference.
    -- The book is uploaded once and reused by the text pipeline.
    gemini_book_file_uri VARCHAR(500) NULL,

    -- Latest interaction ID of the text-generation chain.
    -- Used as previous_interaction_id for the next text step.
    gemini_text_interaction_id VARCHAR(255) NULL,

    -- Latest interaction ID of the image-generation chain.
    -- Used as previous_interaction_id for the next image step.
    gemini_image_interaction_id VARCHAR(255) NULL,

    status ENUM('draft', 'in_progress', 'done', 'failed')
        NOT NULL DEFAULT 'draft',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_projects_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_projects_has_book
        CHECK (book_text IS NOT NULL OR book_file_path IS NOT NULL),

    INDEX idx_projects_user_id (user_id)
) ENGINE=InnoDB;


-- 3. PROJECT STEPS 
CREATE TABLE project_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,

    step ENUM('style', 'characters', 'portraits', 'chapters', 'illustrations')
        NOT NULL,
    step_order TINYINT UNSIGNED NOT NULL, -- 1..5, enforces run order in app logic

    state ENUM('pending', 'running', 'completed', 'failed')
        NOT NULL DEFAULT 'pending',

    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    error_message TEXT NULL,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_project_steps_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE,

    CONSTRAINT uq_project_step UNIQUE (project_id, step),

    INDEX idx_project_steps_project_state (project_id, state)
) ENGINE=InnoDB;


-- 4. PROJECT STYLES
CREATE TABLE project_styles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,

    style_text TEXT NOT NULL,
    source ENUM('user', 'generated') NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_project_styles_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE,

    INDEX idx_project_styles_project_id (project_id)
) ENGINE=InnoDB;

-- 5. CHARACTERS 
CREATE TABLE characters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    order_index TINYINT UNSIGNED NOT NULL, -- 1 or 2

    name VARCHAR(255) NOT NULL,
    prompt TEXT NOT NULL,

    portrait_path VARCHAR(500) NULL,
    portrait_status ENUM('pending', 'generating', 'completed', 'failed')
        NOT NULL DEFAULT 'pending',
    portrait_error TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_characters_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE,

    CONSTRAINT uq_character_order UNIQUE (project_id, order_index),

    INDEX idx_characters_project_id (project_id)
) ENGINE=InnoDB;


-- 6. CHAPTERS 
CREATE TABLE chapters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    order_index TINYINT UNSIGNED NOT NULL, -- 1 for current cap

    name VARCHAR(255) NOT NULL,
    prompt TEXT NOT NULL,

    illustration_path VARCHAR(500) NULL,
    illustration_status ENUM('pending', 'generating', 'completed', 'failed')
        NOT NULL DEFAULT 'pending',
    illustration_error TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_chapters_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE,

    CONSTRAINT uq_chapter_order UNIQUE (project_id, order_index),

    INDEX idx_chapters_project_id (project_id)
) ENGINE=InnoDB;