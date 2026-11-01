<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages that render the full layout call @vite, which needs built
        // assets (public/build, not committed). Tests don't need them.
        $this->withoutVite();
    }
}
