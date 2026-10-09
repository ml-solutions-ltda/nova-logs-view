<?php

namespace MlSolutions\NovaLogsView\Support;

use Composer\InstalledVersions;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;

final class NovaCompatibility
{
    public static function major(): int
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('laravel/nova')) {
            $version = InstalledVersions::getVersion('laravel/nova');
            if ($version !== null && preg_match('/^(\d+)\./', $version, $matches)) {
                return (int) $matches[1];
            }
        }

        // Nova 3 has no MenuSection. Development branches may have no numeric Composer version.
        if (! class_exists(MenuSection::class)) {
            return 3;
        }

        return preg_match('/^(\d+)\./', Nova::version(), $matches) ? (int) $matches[1] : 4;
    }

    public static function usesInertia(): bool
    {
        return self::major() >= 4;
    }
}
