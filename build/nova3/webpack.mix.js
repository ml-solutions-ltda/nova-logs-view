const mix = require('laravel-mix')

mix
  .setPublicPath('../../dist/nova3')
  .js('../../resources/js/tool-nova3.js', 'js/tool.js')
  .vue({ version: 2 })
  .css('../../resources/css/tool.css', 'css/tool.css')
  .webpackConfig({ output: { uniqueName: 'ml-solutions/nova-logs-view-nova3' } })
  .version()
