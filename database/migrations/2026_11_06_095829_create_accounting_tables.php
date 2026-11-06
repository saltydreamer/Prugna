<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business accounting: categories chosen by the user (they replace the fixed expense categories),
     * business lines, bank/cash accounts with their movements (customer collections, payments, other
     * entries), invoice collections, business share of mixed costs.
     */
    public function up(): void
    {
        Schema::create('accounting_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // cost / revenue / tax / contribution
            $table->string('kind', 16)->default('cost');
            // Default share of the cost used for the business (e.g. car 50%).
            $table->decimal('business_share', 5, 2)->default(100);
            // Value of the old fixed category, for the expenses recorded before.
            $table->string('legacy_key', 24)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('business_lines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // bank / card / cash / other
            $table->string('type', 16)->default('bank');
            $table->string('iban', 34)->nullable();
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->date('opening_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('account_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->date('date')->index();
            // Positive: money in; negative: money out.
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            // Collection of an invoice (or refund of a credit note) / payment of an expense.
            $table->foreignId('invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->cascadeOnDelete();
            // Movements without a document (bank fees, F24, contributions...).
            $table->foreignId('category_id')->nullable()->constrained('accounting_categories')->nullOnDelete();
            $table->foreignId('business_line_id')->nullable()->constrained()->nullOnDelete();
            // The two sides of a transfer between accounts.
            $table->uuid('transfer_uuid')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('category')->constrained('accounting_categories')->nullOnDelete();
            $table->foreignId('business_line_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
            $table->decimal('business_share', 5, 2)->default(100)->after('business_line_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('paid_amount', 12, 2)->default(0)->after('amount_due');
            $table->date('paid_at')->nullable()->index()->after('paid_amount');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('business_line_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
        });

        $now = now();
        $categories = [
            ['materials', 'Materiale', 'cost'],
            ['services', 'Servizi', 'cost'],
            ['travel', 'Trasferte, vitto e alloggio', 'cost'],
            ['fuel', 'Carburante e auto', 'cost'],
            ['utilities', 'Utenze e telefonia', 'cost'],
            ['rent', 'Affitti e noleggi', 'cost'],
            ['software', 'Software e abbonamenti', 'cost'],
            ['consulting', 'Consulenze', 'cost'],
            ['equipment', 'Attrezzature', 'cost'],
            ['bank', 'Commissioni e spese bancarie', 'cost'],
            ['other', 'Altro', 'cost'],
            ['taxes', 'Imposte e tasse', 'tax'],
            ['contributions', 'Contributi previdenziali', 'contribution'],
            ['other_revenue', 'Altri ricavi', 'revenue'],
        ];

        DB::table('accounting_categories')->insert(array_map(fn (array $category, int $sort) => [
            'legacy_key' => $category[0],
            'name' => $category[1],
            'kind' => $category[2],
            'business_share' => 100,
            'is_active' => true,
            'sort' => $sort,
            'created_at' => $now,
            'updated_at' => $now,
        ], $categories, array_keys($categories)));

        foreach (DB::table('accounting_categories')->pluck('id', 'legacy_key') as $key => $id) {
            DB::table('expenses')->where('category', $key)->update(['category_id' => $id]);
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('category', 24)->nullable()->index()->after('type');
        });

        foreach (DB::table('accounting_categories')->whereNotNull('legacy_key')->pluck('legacy_key', 'id') as $id => $key) {
            DB::table('expenses')->where('category_id', $id)->update(['category' => $key]);
        }

        Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('business_line_id'));
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
            $table->dropColumn(['paid_amount', 'paid_at']);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_line_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('business_share');
        });

        Schema::dropIfExists('account_movements');
        Schema::dropIfExists('financial_accounts');
        Schema::dropIfExists('business_lines');
        Schema::dropIfExists('accounting_categories');
    }
};
