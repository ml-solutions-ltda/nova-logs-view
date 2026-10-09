import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { JSDOM } from 'jsdom';

for (const major of [3, 4, 5]) {
    test(`Nova ${major} bundle renders, fetches sanitized entries and cleans up`, async () => {
        const dom = new JSDOM('<div id="app"></div>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://nova.example.test/nova/nova-logs-view' });
        const { window } = dom;
        const adds = [], removals = [];
        for (const target of [window, window.document]) {
            const add = target.addEventListener.bind(target);
            const remove = target.removeEventListener.bind(target);
            target.addEventListener = (name, fn, ...args) => { adds.push([target, name, fn]); add(name, fn, ...args); };
            target.removeEventListener = (name, fn, ...args) => { removals.push([target, name, fn]); remove(name, fn, ...args); };
        }
        let component, requested = 0, registrationCount = 0;
        const response = { data: [], files: [], summary: { total: 0, levels: {}, top_types: {}, top_channels: {}, activity: {}, top_problems: [] }, options: { files: [], levels: [], channels: [], types: [] }, limitations: { truncated: false, truncated_files: [] }, meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } };
        const entry = { id: 'a'.repeat(64), file_id: 'file', file: 'laravel.log', type: 'example.failed', level: 'ERROR', channel: 'local', timestamp: '2026-10-09T10:00:00Z', message: '<img src=x onerror=alert(1)> [REDACTED]', has_context: true, context: { token: '[REDACTED]' }, has_trace: true, trace: '#0 [PATH]/Example.php:10', diagnostic: { fingerprint: 'a'.repeat(24), occurrences: 2 } };
        window.Nova = {
            booting(fn) {
                fn(window.Vue, { addRoutes(routes) {
                    registrationCount++;
                    assert.equal(routes[0].name, 'nova-logs-view');
                    assert.equal(routes[0].path, '/nova-logs-view');
                    component = routes[0].component;
                } });
            },
            inertia(name, value) { registrationCount++; assert.equal(name, 'NovaLogsView'); component = value; },
            request: () => ({ get: async url => { requested++; if (url.endsWith('/' + entry.id)) return { data: { data: entry } }; assert.equal(url, '/nova-vendor/nova-logs-view/entries'); return { data: response }; } }),
        };
        window.eval(await readFile(major === 3 ? 'build/nova3/node_modules/vue/dist/vue.min.js' : major === 4 ? 'node_modules/vue-nova4/dist/vue.global.prod.js' : 'node_modules/vue/dist/vue.global.prod.js', 'utf8'));
        window.eval(await readFile(major === 3 ? 'dist/nova3/js/tool.js' : 'dist/js/tool.js', 'utf8'));
        assert.equal(registrationCount, 1);
        const stub = { template: '<div><slot /></div>' };
        let app, vm;
        if (major === 3) {
            window.Vue.component('Card', stub);
            window.Vue.component('Heading', stub);
            vm = new window.Vue(component).$mount('#app');
        } else {
            app = window.Vue.createApp(component);
            app.component('Card', stub);
            app.component('Heading', stub);
            app.component('Head', { template: '<span />' });
            vm = app.mount('#app');
        }
        await new Promise(resolve => setTimeout(resolve, 10));
        assert.equal(requested, 1);
        assert.match(window.document.body.textContent, /Visibilidade de logs/);
        assert.match(window.document.body.textContent, /Nenhum evento/);
        response.data = [entry];
        response.summary.total = 1;
        await vm.load();
        await new Promise(resolve => setTimeout(resolve, 0));
        assert.match(window.document.body.textContent, /example.failed/);
        assert.equal(window.document.querySelector('img[onerror]'), null);
        await vm.openDetail(entry);
        await new Promise(resolve => setTimeout(resolve, 0));
        assert.match(window.document.querySelector('[role=dialog]').textContent, /Contexto sanitizado/);
        assert.match(window.document.querySelector('[role=dialog]').textContent, /REDACTED/);
        assert.equal(window.document.querySelector('img[onerror]'), null);
        vm.closeDetail();
        vm.autoRefresh = 30;
        vm.scheduleAutoRefresh();
        assert.ok(vm.autoRefreshTimer);
        const sequence = vm.requestSequence;
        major === 3 ? vm.$destroy() : app.unmount();
        assert.ok(vm.requestSequence > sequence);
        for (const name of ['keydown', 'visibilitychange']) {
            const listener = adds.find(([, event]) => event === name);
            assert.ok(listener);
            assert.ok(removals.some(([target, event, fn]) => target === listener[0] && event === name && fn === listener[2]));
        }
        window.close();
    });
}
