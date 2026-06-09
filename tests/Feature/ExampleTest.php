<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function test_guests_are_redirected_to_login()
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
