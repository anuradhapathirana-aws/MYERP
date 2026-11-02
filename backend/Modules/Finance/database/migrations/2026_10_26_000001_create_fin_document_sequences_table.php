<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One counter row per document type per year (BILL 2026, BILL 2027, ...).
 *
 * A counter row rather than max(number) + 1: the row is locked with
 * lockForUpdate inside the caller's transaction, so two people saving at the
 * same moment queue on it instead of racing, and a number is never reissued
 * even after its document is deleted -- last_number only ever goes up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_document_sequences', function (Blueprint $table): void {
            $table->id();

            $table->string('document_type', 40);
            $table->unsignedSmallInteger('year');
            $table->string('prefix', 20);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->unsignedInteger('last_number')->default(0);

            $table->timestamps();

            $table->unique(['document_type', 'year'], 'fin_doc_seq_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_document_sequences');
    }
};
