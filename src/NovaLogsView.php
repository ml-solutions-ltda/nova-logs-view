<?php

namespace MlSolutions\NovaLogsView;

use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool;
use MlSolutions\NovaLogsView\Support\NovaCompatibility;

class NovaLogsView extends Tool
{
    /**
     * Perform any tasks that need to happen when the tool is booted.
     */
    public function boot(): void
    {
        $directory = NovaCompatibility::usesInertia() ? 'dist' : 'dist/nova3';
        Nova::script('nova-logs-view', __DIR__.'/../'.$directory.'/js/tool.js');
        Nova::style('nova-logs-view', __DIR__.'/../'.$directory.'/css/tool.css');
    }

    /** Render the native Nova 3 sidebar entry. */
    public function render()
    {
        return view('nova-logs-view::navigation');
    }

    /**
     * Build the menu that renders the navigation links for the tool.
     */
    public function menu(Request $request): MenuSection
    {
        return MenuSection::make('Visibilidade de logs')
            ->path('/nova-logs-view')
            ->icon(NovaCompatibility::major() >= 5 ? 'document-magnifying-glass' : 'document-search');
    }
}
