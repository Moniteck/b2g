<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('kind')->default('proceso')->index()->after('name');
            $table->string('owner_name')->nullable()->after('kind');
            $table->string('required_for')->nullable()->after('owner_name');
            $table->unsignedInteger('version')->default(1)->after('required_for');
            $table->foreignId('parent_id')->nullable()->after('version')->constrained('documents')->nullOnDelete();
            $table->boolean('is_current')->default(true)->index()->after('parent_id');
            $table->string('hash')->nullable()->after('is_current');
            $table->timestamp('signed_at')->nullable()->after('hash');
            $table->foreignId('signed_by')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('sealed_at')->nullable()->after('signed_by');
            $table->string('renewal_owner_id')->nullable()->after('sealed_at');
            $table->index(['kind', 'category', 'status']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('path');
            $table->string('disk')->default('local');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('hash')->nullable()->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['document_id', 'version']);
        });

        Schema::create('document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->string('context')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'linkable_type', 'linkable_id'], 'document_links_unique_target');
            $table->index(['linkable_type', 'linkable_id']);
        });

        Schema::create('signing_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('name');
            $table->string('path');
            $table->string('disk')->default('local');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('signature_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signing_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('page')->default(1);
            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(0);
            $table->decimal('height', 10, 2)->default(0);
            $table->decimal('rotation', 8, 2)->default(0);
            $table->string('document_hash')->nullable();
            $table->foreignId('authorization_id')->nullable()->constrained('mcp_authorizations')->nullOnDelete();
            $table->string('result_path')->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();
            $table->index(['document_version_id', 'page']);
        });

        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('Pendiente')->index();
            $table->json('extracted')->nullable();
            $table->json('evidence')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('method')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
        Schema::dropIfExists('signature_applications');
        Schema::dropIfExists('signing_assets');
        Schema::dropIfExists('document_links');
        Schema::dropIfExists('document_versions');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn([
                'kind', 'owner_name', 'required_for', 'version', 'parent_id', 'is_current',
                'hash', 'signed_at', 'signed_by', 'sealed_at', 'renewal_owner_id',
            ]);
        });
    }
};
