<?php
namespace Tests\Feature;

use App\Exceptions\ShopifyGraphQLException;
use App\Models\Shop;
use App\Services\ShopifyGraphQLService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const Q = 'query { shop { name } }';

    private function shop(array $over = []): Shop
    {
        return Shop::create(array_merge([
            'shop' => 't.myshopify.com', 'access_token' => 'old-access', 'refresh_token' => 'old-refresh',
            'access_token_expires_at' => now()->subMinutes(10), 'refresh_token_expires_at' => now()->addDays(80),
            'is_active' => true,
        ], $over));
    }

    private function refreshReturns(): array
    {
        return ['access_token' => 'new-access', 'expires_in' => 3600, 'refresh_token' => 'new-refresh', 'refresh_token_expires_in' => 7776000, 'scope' => 'x'];
    }

    public function test_expired_token_is_refreshed_before_call(): void
    {
        $this->mock(ShopifyService::class, fn ($m) => $m->shouldReceive('refreshAccessToken')->once()->with('t.myshopify.com', 'old-refresh')->andReturn($this->refreshReturns()));
        Http::fake(['*' => Http::response(['data' => ['shop' => ['name' => 'X']]], 200)]);
        $shop = $this->shop();

        $data = ShopifyGraphQLService::for($shop)->query(self::Q);

        $this->assertSame('X', $data['shop']['name']);
        Http::assertSent(fn (Request $r) => $r->header('X-Shopify-Access-Token')[0] === 'new-access');
        $fresh = Shop::first();
        $this->assertSame('new-access', $fresh->access_token);
        $this->assertSame('new-refresh', $fresh->refresh_token);
        $this->assertTrue($fresh->access_token_expires_at->between(now()->addMinutes(58), now()->addMinutes(61)));
        $this->assertTrue($fresh->refresh_token_expires_at->gt(now()->addDays(89)));
    }

    public function test_valid_token_is_not_refreshed(): void
    {
        $this->mock(ShopifyService::class, fn ($m) => $m->shouldNotReceive('refreshAccessToken'));
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $shop = $this->shop(['access_token_expires_at' => now()->addMinutes(30)]);

        ShopifyGraphQLService::for($shop)->query(self::Q);

        Http::assertSent(fn (Request $r) => $r->header('X-Shopify-Access-Token')[0] === 'old-access');
    }

    public function test_401_triggers_one_refresh_and_retry(): void
    {
        $this->mock(ShopifyService::class, fn ($m) => $m->shouldReceive('refreshAccessToken')->once()->andReturn($this->refreshReturns()));
        Http::fake(['*' => Http::sequence()
            ->push(['errors' => 'Invalid API key or access token'], 401)
            ->push(['data' => ['shop' => ['name' => 'OK']]], 200)]);
        $shop = $this->shop(['access_token_expires_at' => now()->addMinutes(30)]); // expiry hju door chhe pan Shopify e reject karyo

        $data = ShopifyGraphQLService::for($shop)->query(self::Q);

        $this->assertSame('OK', $data['shop']['name']);
        $sent = Http::recorded()->map(fn ($p) => $p[0]->header('X-Shopify-Access-Token')[0])->all();
        $this->assertSame(['old-access', 'new-access'], $sent);
    }

    public function test_legacy_shop_without_refresh_token_still_throws_401(): void
    {
        $this->mock(ShopifyService::class, fn ($m) => $m->shouldNotReceive('refreshAccessToken'));
        Http::fake(['*' => Http::response(['errors' => 'x'], 401)]);
        $shop = $this->shop(['refresh_token' => null, 'access_token_expires_at' => null]);

        $this->expectException(ShopifyGraphQLException::class);
        $this->expectExceptionMessage('HTTP 401');
        ShopifyGraphQLService::for($shop)->query(self::Q);
    }

    public function test_expired_refresh_token_gives_clear_message(): void
    {
        $this->mock(ShopifyService::class, fn ($m) => $m->shouldNotReceive('refreshAccessToken'));
        $shop = $this->shop(['refresh_token_expires_at' => now()->subDay()]);

        $this->expectException(ShopifyGraphQLException::class);
        $this->expectExceptionMessage('pachhi install karo');
        ShopifyGraphQLService::for($shop)->query(self::Q);
    }
}
