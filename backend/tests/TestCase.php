<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase 等が走る前に接続先を確認し、開発 DB をテストで壊さないようにする。
     */
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== 'penusta_test') {
            throw new RuntimeException(
                "テストは penusta_test で実行する（現在: {$database}）。php artisan config:clear を実行すること。"
            );
        }

        return parent::setUpTraits();
    }
}
