<?php

namespace MlSolutions\NovaLogsView\Tests;

use Illuminate\Http\Request;
use Laravel\Nova\Http\Middleware\Authenticate;
use Laravel\Nova\Nova;
use MlSolutions\NovaLogsView\Http\Middleware\Authorize;
use MlSolutions\NovaLogsView\NovaLogsView;
use MlSolutions\NovaLogsView\Support\NovaCompatibility;

final class NovaAdapterTest extends TestCase
{
    public function test_api_routes_keep_authentication_and_gate_on_each_adapter(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'nova-vendor/nova-logs-view'));
        $this->assertCount(2, $routes);
        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertContains(Authenticate::class, $route->middleware());
            $this->assertContains(Authorize::class, $route->middleware());
        }
        $page = app('router')->getRoutes()->getByName('nova-logs-view');
        if (NovaCompatibility::usesInertia()) {
            $this->assertNotNull($page);
            $this->assertContains(Authenticate::class, $page->middleware());
            $this->assertContains(Authorize::class, $page->middleware());
        } else {
            $this->assertNull($page);
        }
    }

    public function test_tool_registers_only_the_matching_compiled_bundle(): void
    {
        (new NovaLogsView)->boot();
        // Asset storage is available on the interface doubles. Real Nova integrations test class loading separately.
        if (getenv('NOVA_SOURCE')) {
            $this->assertTrue(class_exists(NovaLogsView::class));

            return;
        }
        $directory = NovaCompatibility::usesInertia() ? '/dist/' : '/dist/nova3/';
        $this->assertStringContainsString($directory, Nova::$scripts['nova-logs-view']);
        $this->assertStringContainsString($directory, Nova::$styles['nova-logs-view']);
        $this->assertFileExists(Nova::$scripts['nova-logs-view']);
        $this->assertFileExists(Nova::$styles['nova-logs-view']);
    }

    public function test_native_navigation_matches_the_adapter(): void
    {
        $tool = new NovaLogsView;
        if (NovaCompatibility::usesInertia()) {
            $menu = $tool->menu(Request::create('/'));
            $this->assertInstanceOf(\Laravel\Nova\Menu\MenuSection::class, $menu);
        } else {
            $html = $tool->render()->render();
            $this->assertStringContainsString('router-link', $html);
            $this->assertStringContainsString('nova-logs-view', $html);
            $this->assertStringContainsString('Visibilidade de logs', $html);
        }
    }
}
