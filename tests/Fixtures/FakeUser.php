<?php

namespace SchoolAid\Assistant\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;

/**
 * A panel person of the test app: an Eloquent user, so the engine reads its key and its table
 * (`PrincipalKey::of()` gives `users:{id}`) as it does with an app's user.
 */
final class FakeUser extends User
{
    protected $table = 'users';

    protected $guarded = [];

    public static function createTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
