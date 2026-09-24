-- M7: attachments — receipts, invoices, bills, payment proofs linked to a
-- transaction (Module 10, docs/MODULES.md). Decision D-4: files live in
-- storage/documents, outside the web root entirely, and are never reachable
-- by a guessed URL. This table is the only thing that turns an attachment
-- *id* into a file path — App\Controllers\DocumentController@show resolves
-- through it and never accepts a path from the client.
--
-- original_filename is display-only (what the user sees, e.g. "receipt.pdf")
-- and is never used to build a filesystem path. stored_filename is the
-- random, generated name App\Core\Upload::storePrivate() actually wrote to
-- disk — the same "never trust the client's name" rule the public upload
-- path already follows.

CREATE TABLE IF NOT EXISTS attachments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id         BIGINT UNSIGNED NOT NULL,
    transaction_id    BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255)    NOT NULL,
    stored_filename   VARCHAR(255)    NOT NULL,
    mime              VARCHAR(100)    NOT NULL,
    size              INT UNSIGNED    NOT NULL,
    uploaded_by       BIGINT UNSIGNED NOT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Almost every read is either "attachments for this transaction" (the
    -- expense/income/transaction screens) or "for this branch" (a future
    -- audit/cleanup pass) - never a plain scan.
    KEY idx_attachments_transaction (transaction_id),
    KEY idx_attachments_branch (branch_id),

    CONSTRAINT fk_attachments_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_attachments_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
