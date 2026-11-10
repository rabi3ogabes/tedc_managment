<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 16: prices per trainee category, carts and seat holds, entity accounts, orders, payments, refunds, vouchers, discount codes, invoices. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->cascadeOnDelete();
            $table->string('currency', 3)->default('QAR');
            $table->json('rules')->nullable();                        // ordered: [{category, match?, price, label_ar, label_en}]
            $table->decimal('default_price', 10, 2)->default(0);      // used when no rule matches
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->json('refund_policy')->nullable();                // {full_days, partial_days, partial_percent}
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('program_id');
            $table->unique('group_id');
        });

        Schema::create('discount_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('type', 8);                                 // percent | amount
            $table->decimal('value', 10, 2);
            $table->string('scope', 10)->default('all');               // all | program | group
            $table->uuid('scope_id')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->string('source', 20)->default('manual');           // manual | gamification_reward
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('entity_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('type', 16)->default('private_school');    // private_school | company | other
            $table->string('cr_number', 40)->nullable();
            $table->json('contacts')->nullable();
            $table->text('billing_address')->nullable();
            $table->foreignUuid('partner_organization_id')->nullable()->constrained('partner_organizations')->nullOnDelete();
            $table->string('status', 10)->default('active');           // active | suspended
            $table->timestamps();
        });
        Schema::create('entity_account_users', function (Blueprint $table) {
            $table->foreignUuid('entity_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 8)->default('admin');
            $table->primary(['entity_account_id', 'user_id']);
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('entity_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('discount_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'entity_account_id']);
        });
        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('training_groups')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('expires_at');                            // the seat hold
            $table->timestamps();
            $table->unique(['cart_id', 'group_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 24)->unique();
            $table->string('buyer_type', 8);                            // user | entity
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();   // who placed it
            $table->foreignUuid('entity_account_id')->nullable()->constrained()->nullOnDelete();
            $table->json('items');                                      // snapshot
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('vat', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('QAR');
            $table->string('discount_code', 40)->nullable();
            $table->string('status', 20)->default('pending_payment');   // pending_payment | paid | failed | cancelled | refunded | partially_refunded
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('invoice_no', 24)->nullable()->unique();
            $table->string('invoice_pdf_path')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 12);                               // moe_epay | fake
            $table->string('gateway_ref', 80)->nullable()->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status', 12)->default('initiated');          // initiated | authorized | captured | failed | refunded
            $table->json('raw')->nullable();                             // PII-free
            $table->boolean('signature_valid')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('items')->nullable();                           // which seats / registrations
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255)->nullable();
            $table->string('status', 10)->default('requested');          // requested | approved | rejected | refunded | failed
            $table->string('gateway_ref', 80)->nullable();
            $table->string('credit_note_no', 24)->nullable()->unique();
            $table->string('credit_note_pdf_path')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seat_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained('training_groups')->cascadeOnDelete();
            $table->string('kind', 8);                                   // cart | order | voucher
            $table->uuid('owner_id');                                    // cart item / order / voucher id
            $table->unsignedInteger('quantity');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['group_id', 'expires_at']);
            $table->unique(['kind', 'owner_id', 'group_id']);
        });

        Schema::create('seat_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('entity_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('training_groups')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->foreignUuid('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('assigned_email')->nullable();
            $table->string('status', 10)->default('available');          // available | assigned | redeemed | expired | refunded
            $table->foreignUuid('registration_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['entity_account_id', 'status']);
        });

        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->string('key', 16)->primary();                        // INV-2026 | CN-2026
            $table->unsignedInteger('last')->default(0);
        });
        Schema::create('payment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gateway', 12);
            $table->string('event_id', 100);                             // the gateway's id for this callback (idempotency)
            $table->string('kind', 12);                                  // callback | return | refund
            $table->boolean('signature_valid')->default(false);
            $table->string('outcome', 20)->nullable();                   // applied | duplicate | rejected | ignored
            $table->timestamps();
            $table->unique(['gateway', 'event_id', 'kind']);
        });
        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('day');
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('fixed')->default(0);
            $table->json('mismatches')->nullable();
            $table->timestamps();
            $table->unique('day');
        });
    }

    public function down(): void
    {
        foreach (['payment_reconciliations', 'payment_events', 'invoice_counters', 'seat_vouchers', 'seat_holds', 'refunds', 'payments', 'orders', 'cart_items', 'carts', 'entity_account_users', 'entity_accounts', 'discount_codes', 'price_lists'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
