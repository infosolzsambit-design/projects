<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every /api/v1 route requires this header — tests opt in explicitly so
     * it's obvious in each test which requests are meant to pass that gate.
     */
    protected function withApiKey(): static
    {
        return $this->withHeaders([
            'X-API-KEY' => config('app.api_key'),
        ]);
    }
}
