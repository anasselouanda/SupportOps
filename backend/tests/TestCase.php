<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $configuration = $application->make('config');

        if (
            ! $application->environment('testing')
            || $application->configurationIsCached()
            || $configuration->get('database.default') !== 'sqlite'
            || $configuration->get('database.connections.sqlite.driver') !== 'sqlite'
            || $configuration->get('database.connections.sqlite.database') !== ':memory:'
            || ! in_array($configuration->get('database.connections.sqlite.url'), ['', null], true)
            || $configuration->get('cache.default') !== 'array'
            || $configuration->get('session.driver') !== 'array'
        ) {
            throw new RuntimeException('Refusing to run tests without an isolated in-memory database and process-local stores.');
        }

        return $application;
    }
}
