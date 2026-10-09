import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const toolSource = await readFile(
    new URL(
        "../../resources/js/pages/Tool.vue",
        import.meta.url,
    ),
    "utf8",
);

test("log visibility exposes recurrent problem investigation controls", () => {
    assert.match(toolSource, /Problemas recorrentes/);
    assert.match(toolSource, /applyProblem\(problem\)/);
    assert.match(toolSource, /filters\.fingerprint/);
    assert.match(toolSource, /Somente falhas/);
    assert.match(toolSource, /Com rastreamento/);
    assert.match(toolSource, /Últimas 24 horas/);
});

test("log detail labels diagnostic evidence without claiming root cause", () => {
    assert.match(toolSource, /Evidência diagnóstica/);
    assert.match(toolSource, /Primeiro frame útil sanitizado/);
    assert.match(toolSource, /não\s+representam\s+uma\s+causa raiz automática/);
    assert.match(toolSource, /selected\.diagnostic\.occurrences/);
});

test("auto refresh is bounded and pauses outside the active list view", () => {
    assert.match(toolSource, /<option :value="30">A cada 30 s<\/option>/);
    assert.match(toolSource, /<option :value="60">A cada 60 s<\/option>/);
    assert.match(toolSource, /this\.detailOpen/);
    assert.match(toolSource, /document\.visibilityState !== "visible"/);
    assert.match(toolSource, /clearTimeout\(this\.autoRefreshTimer\)/);
});
