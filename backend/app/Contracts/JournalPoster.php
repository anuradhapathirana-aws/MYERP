<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Journal\JournalRequest;

/**
 * The seam between a document and the general ledger.
 *
 * This interface is the reason Inventory and Finance do not depend on each
 * other. Both depend on core, which depends on nothing:
 *
 *       core  (JournalPoster, JournalRequest)
 *        ^                  ^
 *        |                  |
 *   Inventory            Finance
 *        \__ no arrow between them __/
 *
 * Inventory type-hints this interface and calls post() inside the transaction
 * it already opens in confirm(). The container decides who answers:
 *
 *   Finance installed and entitled  ->  JournalPostingService, a voucher appears
 *   Finance not sold                ->  NullJournalPoster, nothing happens
 *
 * Inventory's code is byte-identical in both cases and never asks whether
 * Finance exists.
 *
 * Implementations are called from inside a caller-owned DB transaction, so
 * they must not open or commit one of their own, and a failure must throw so
 * the whole document rolls back with it. A document that believes it posted
 * when it did not is worse than a document that failed to save.
 */
interface JournalPoster
{
    /**
     * Whether a ledger is present and entitled to receive postings.
     *
     * Exists so a caller can skip BUILDING a JournalRequest when nothing will
     * consume it. That matters because building one is not free and can fail
     * on its own terms -- a supplier payment needs its Pay From account named,
     * which an Inventory-only client has no chart of accounts to choose from.
     * Without this, such a client could not confirm a payment at all.
     *
     * Note what the question is: "is there a ledger", not "is Finance
     * installed". The caller still never names the Finance module.
     */
    public function acceptsPostings(): bool;

    /**
     * Post a balanced journal.
     *
     * @return string|null The journal number (e.g. "JV-2026-0042"), or null when
     *                     no ledger is active — which is a normal outcome, not an error.
     *
     * @throws \Illuminate\Validation\ValidationException When the request is unbalanced,
     *         a role has no mapped account, or an account is not postable.
     */
    public function post(JournalRequest $request): ?string;

    /**
     * Reverse whatever was posted for a document, for Void and Cancel.
     *
     * Posts a mirror-image journal rather than deleting the original, because
     * a posted entry is part of the audit trail. Safe to call when nothing was
     * ever posted for that document.
     *
     * @return string|null The reversing journal's number, or null if there was
     *                     nothing to reverse.
     */
    public function reverseDocument(string $sourceType, int $sourceId, ?string $narration = null): ?string;

    /**
     * Whether a live (non-reversed) journal exists for this document.
     *
     * Lets a document screen show a "Posted" badge, and lets a caller avoid
     * duplicate work, without Inventory querying any fin_ table itself.
     */
    public function hasPostingFor(string $sourceType, int $sourceId): bool;
}
