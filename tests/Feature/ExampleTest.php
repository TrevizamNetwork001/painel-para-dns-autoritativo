<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_redirects_guests_to_login(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
    }
}
