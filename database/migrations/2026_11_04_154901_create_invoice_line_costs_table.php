<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Costs charged back by an invoice line: one line can cover several costs (a single "spese" line).
     * Replaces the single purchase_order_line_id / expense_line_id columns of invoice_lines.
     */
    public function up(): void
    {
        Schema::create('invoice_line_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('expense_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('invoice_lines')
            ->where(fn ($query) => $query->whereNotNull('purchase_order_line_id')->orWhereNotNull('expense_line_id'))
            ->orderBy('id')
            ->get(['id', 'purchase_order_line_id', 'expense_line_id'])
            ->each(fn (object $line) => DB::table('invoice_line_costs')->insert([
                'invoice_line_id' => $line->id,
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'expense_line_id' => $line->expense_line_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropConstrainedForeignId('expense_line_id');
        });
    }

    /**
     * Reverse the migrations (a line keeps only its first cost).
     */
    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')->nullable()->after('order_line_id')->constrained()->nullOnDelete();
            $table->foreignId('expense_line_id')->nullable()->after('purchase_order_line_id')->constrained()->nullOnDelete();
        });

        foreach (DB::table('invoice_line_costs')->orderBy('id')->get() as $link) {
            DB::table('invoice_lines')->where('id', $link->invoice_line_id)->whereNull('purchase_order_line_id')->whereNull('expense_line_id')
                ->update(['purchase_order_line_id' => $link->purchase_order_line_id, 'expense_line_id' => $link->expense_line_id]);
        }

        Schema::dropIfExists('invoice_line_costs');
    }
};
