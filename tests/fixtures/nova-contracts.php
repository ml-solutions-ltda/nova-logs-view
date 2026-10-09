<?php

// Minimal public-interface doubles, not copies of proprietary Nova implementation.

namespace Laravel\Nova {
    abstract class Tool
    {
        public function boot() {}
    }

    final class Nova
    {
        public static array $scripts = [];

        public static array $styles = [];

        public static function version(): string
        {
            return (getenv('NOVA_TEST_MAJOR') ?: '5').'.0.0';
        }

        public static function script($name, $path): void
        {
            self::$scripts[$name] = $path;
        }

        public static function style($name, $path): void
        {
            self::$styles[$name] = $path;
        }

        public static function router(array $middleware, string $prefix)
        {
            return \Illuminate\Support\Facades\Route::middleware($middleware)->prefix('nova/'.$prefix);
        }
    }
}

namespace Laravel\Nova\Menu {
    if ((getenv('NOVA_TEST_MAJOR') ?: '5') !== '3') {
        final class MenuSection
        {
            public string $label;

            public string $url;

            public string $icon;

            public static function make(string $label): self
            {
                $section = new self;
                $section->label = $label;

                return $section;
            }

            public function path(string $path): self
            {
                $this->url = $path;

                return $this;
            }

            public function icon(string $icon): self
            {
                $this->icon = $icon;

                return $this;
            }
        }
    }
}

namespace Laravel\Nova\Http\Middleware {
    class Authenticate extends \Illuminate\Auth\Middleware\Authenticate {}
}
