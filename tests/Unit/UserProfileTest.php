<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    public function test_user_has_profile_relationship(): void
    {
        $user = new User();

        $this->assertInstanceOf(HasOne::class, $user->profile());
    }
}
