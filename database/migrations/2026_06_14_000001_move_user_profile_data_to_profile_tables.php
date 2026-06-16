<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->char('middle_initial', 1)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->char('middle_initial', 1)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('provider_name')->nullable();
            $table->string('provider_type')->nullable();
            $table->string('provider_website')->nullable();
            $table->string('provider_address', 500)->nullable();
            $table->text('provider_description')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->char('middle_initial', 1)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('display_name')->nullable();
            $table->timestamps();
        });

        $timestamp = now();

        DB::table('users')
            ->select([
                'id',
                'name',
                'first_name',
                'last_name',
                'middle_initial',
                'contact_number',
                'provider_name',
                'provider_type',
                'provider_website',
                'provider_address',
                'provider_description',
                'is_admin',
                'role',
            ])
            ->orderBy('id')
            ->get()
            ->each(function ($user) use ($timestamp): void {
                $profile = [
                    'user_id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'middle_initial' => $user->middle_initial,
                    'contact_number' => $user->contact_number,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                $role = $user->role ?: ($user->is_admin ? 'admin' : 'applicant');

                if ($role === 'provider') {
                    DB::table('provider_profiles')->insert([
                        ...$profile,
                        'provider_name' => $user->provider_name ?: $user->name,
                        'provider_type' => $user->provider_type,
                        'provider_website' => $user->provider_website,
                        'provider_address' => $user->provider_address,
                        'provider_description' => $user->provider_description,
                    ]);

                    return;
                }

                if ($role === 'admin') {
                    DB::table('admin_profiles')->insert([
                        ...$profile,
                        'display_name' => $user->name,
                    ]);

                    return;
                }

                DB::table('student_profiles')->insert($profile);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'first_name',
                'last_name',
                'middle_initial',
                'contact_number',
                'provider_name',
                'provider_type',
                'provider_website',
                'provider_address',
                'provider_description',
                'is_admin',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->char('middle_initial', 1)->nullable()->after('last_name');
            $table->string('contact_number', 30)->nullable()->after('username');
            $table->string('provider_name')->nullable()->after('contact_number');
            $table->string('provider_type')->nullable()->after('provider_name');
            $table->string('provider_website')->nullable()->after('provider_type');
            $table->string('provider_address', 500)->nullable()->after('provider_website');
            $table->text('provider_description')->nullable()->after('provider_address');
            $table->boolean('is_admin')->default(false)->after('provider_description');
        });

        DB::table('users')
            ->select(['id', 'email', 'username', 'role'])
            ->orderBy('id')
            ->get()
            ->each(function ($user): void {
                if ($user->role === 'provider') {
                    $profile = DB::table('provider_profiles')->where('user_id', $user->id)->first();

                    DB::table('users')->where('id', $user->id)->update([
                        'name' => $profile?->provider_name ?: $this->contactName($profile, $user),
                        'first_name' => $profile?->first_name,
                        'last_name' => $profile?->last_name,
                        'middle_initial' => $profile?->middle_initial,
                        'contact_number' => $profile?->contact_number,
                        'provider_name' => $profile?->provider_name,
                        'provider_type' => $profile?->provider_type,
                        'provider_website' => $profile?->provider_website,
                        'provider_address' => $profile?->provider_address,
                        'provider_description' => $profile?->provider_description,
                        'is_admin' => false,
                    ]);

                    return;
                }

                if ($user->role === 'admin') {
                    $profile = DB::table('admin_profiles')->where('user_id', $user->id)->first();

                    DB::table('users')->where('id', $user->id)->update([
                        'name' => $profile?->display_name ?: $this->contactName($profile, $user),
                        'first_name' => $profile?->first_name,
                        'last_name' => $profile?->last_name,
                        'middle_initial' => $profile?->middle_initial,
                        'contact_number' => $profile?->contact_number,
                        'is_admin' => true,
                    ]);

                    return;
                }

                $profile = DB::table('student_profiles')->where('user_id', $user->id)->first();

                DB::table('users')->where('id', $user->id)->update([
                    'name' => $this->contactName($profile, $user),
                    'first_name' => $profile?->first_name,
                    'last_name' => $profile?->last_name,
                    'middle_initial' => $profile?->middle_initial,
                    'contact_number' => $profile?->contact_number,
                    'is_admin' => false,
                ]);
            });

        Schema::dropIfExists('admin_profiles');
        Schema::dropIfExists('provider_profiles');
        Schema::dropIfExists('student_profiles');
    }

    private function contactName(?object $profile, object $user): string
    {
        $parts = collect([
            $profile?->first_name,
            $profile?->middle_initial ? "{$profile->middle_initial}." : null,
            $profile?->last_name,
        ])->filter()->values();

        return $parts->isEmpty() ? ($user->username ?: $user->email) : $parts->implode(' ');
    }
};
