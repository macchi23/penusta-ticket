<?php

namespace Tests\Feature\Admin;

use App\Models\FootballMatch;
use App\Models\User;
use App\Services\Purchase\CartService;
use App\Services\Purchase\CheckoutService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class BusyConversionTest extends TestCase
{
    use BuildsSaleFixture;
    use RefreshDatabase;

    private FootballMatch $match;

    private function queryException(string $sqlState): QueryException
    {
        $pdo = new PDOException('SQLSTATE: secret_table detail');
        (new ReflectionProperty(\Exception::class, 'code'))->setValue($pdo, $sqlState);

        return new QueryException('pgsql', 'select * from secret_table where x = ?', [1], $pdo);
    }

    /** @return array<string, array{string}> */
    public static function sqlStates(): array
    {
        return ['lock_not_available' => ['55P03'], 'deadlock_detected' => ['40P01']];
    }

    #[DataProvider('sqlStates')]
    public function test_cart_converts_lock_errors_to_503_busy(string $state): void
    {
        config(['ticketing.demo_auth_enabled' => true, 'app.debug' => true]);
        $this->buildSaleFixture();
        $block = $this->makeBlock('F1', 'unreserved', 5);
        $user = User::factory()->create();
        $this->mock(CartService::class, fn ($m) => $m->shouldReceive('addItem')->andThrow($this->queryException($state)));

        $res = $this->postJson("/api/matches/{$this->match->id}/cart/items", ['block_id' => $block->id], ['X-Demo-User-Id' => (string) $user->id]);

        $this->assertBusy($res);
    }

    #[DataProvider('sqlStates')]
    public function test_checkout_converts_lock_errors_to_503_busy(string $state): void
    {
        config(['ticketing.demo_auth_enabled' => true, 'app.debug' => true]);
        $user = User::factory()->create();
        $this->mock(CheckoutService::class, fn ($m) => $m->shouldReceive('checkout')->andThrow($this->queryException($state)));

        $res = $this->postJson('/api/orders/1/checkout', [], ['X-Demo-User-Id' => (string) $user->id]);

        $this->assertBusy($res);
    }

    public function test_other_query_exceptions_are_not_converted(): void
    {
        config(['ticketing.demo_auth_enabled' => true]);
        $user = User::factory()->create();
        $this->mock(CheckoutService::class, fn ($m) => $m->shouldReceive('checkout')->andThrow($this->queryException('23505')));

        $res = $this->postJson('/api/orders/1/checkout', [], ['X-Demo-User-Id' => (string) $user->id]);

        $this->assertSame(500, $res->status());
        $this->assertNotSame('busy', $res->json('code'));
    }

    public function test_lock_errors_outside_api_are_not_converted(): void
    {
        Route::get('/non-api-busy-probe', fn () => throw $this->queryException('55P03'));

        $res = $this->getJson('/non-api-busy-probe');

        $this->assertSame(500, $res->status());
        $this->assertNotSame('busy', $res->json('code'));
    }

    private function assertBusy($res): void
    {
        $res->assertStatus(503)->assertJsonPath('code', 'busy');
        $this->assertSame(['code', 'message'], array_keys($res->json()));
        $this->assertStringNotContainsString('secret_table', $res->getContent());
    }
}
