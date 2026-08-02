<?php

namespace Tests\Unit;

use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use Tests\TestCase;

class RegisterRequestTest extends TestCase
{
    public function test_it_allows_gender_and_school_grade_fields(): void
    {
        $request = new RegisterRequest();
        $rules = $request->rules();

        $this->assertArrayHasKey('gender', $rules);
        $this->assertArrayHasKey('school_grade', $rules);
        $this->assertContains('nullable', $rules['gender']);
        $this->assertContains('string', $rules['gender']);
        $this->assertContains('nullable', $rules['school_grade']);
        $this->assertContains('string', $rules['school_grade']);
    }
}
