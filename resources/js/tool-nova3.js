import Tool from './pages/Tool'

const LegacyTool = {
  ...Tool,
  props: { legacyNova: { type: Boolean, default: true } },
}

Nova.booting((Vue, router) => {
  router.addRoutes([{ name: 'nova-logs-view', path: '/nova-logs-view', component: LegacyTool }])
})
