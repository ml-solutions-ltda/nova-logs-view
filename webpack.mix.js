const mix = require('laravel-mix')

mix
  .setPublicPath('dist')
  .js('resources/js/tool.js', 'js')
  .vue({ version: 3 })
  .css('resources/css/tool.css', 'css')
  .webpackConfig({
    externals: {
      vue: 'Vue',
      'laravel-nova': 'LaravelNova',
      'laravel-nova-ui': 'LaravelNovaUi',
      'laravel-nova-util': 'LaravelNovaUtil',
    },
    output: { uniqueName: 'ml-solutions/nova-logs-view' },
  })
  .version()
