-- EMP: documents an admin publishes to every employee of their branch
-- (policies, handbooks, notices). PDF only.
--
-- Same storage rule as attachments (decision D-4): the file lives under
-- storage/documents/employee-documents, outside the web root, and is only
-- ever streamed by App\Controllers\EmployeeDocumentController after a
-- branch-scoped lookup by id. stored_filename is the random name
-- App\Core\Upload generated (32 hex chars + .pdf); original_filename is
-- display-only and never touches the filesystem.
--
-- Safe to re-run: CREATE ... IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS employee_documents (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id         BIGINT UNSIGNED NOT NULL,
    title             VARCHAR(150)    NOT NULL,
    description       VARCHAR(1000)   NULL,
    original_filename VARCHAR(255)    NOT NULL,
    stored_filename   VARCHAR(64)     NOT NULL,
    size              INT UNSIGNED    NOT NULL,
    uploaded_by       BIGINT UNSIGNED NOT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_employee_documents_stored (stored_filename),
    -- Every list is "this branch, newest first".
    KEY idx_employee_documents_branch_created (branch_id, created_at),

    CONSTRAINT fk_employee_documents_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_employee_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
