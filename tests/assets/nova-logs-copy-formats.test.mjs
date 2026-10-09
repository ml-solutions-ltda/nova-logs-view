import assert from "node:assert/strict";
import test from "node:test";

import {
    logAsAiMarkdown,
    logAsMarkdown,
    logAsText,
} from "../../resources/js/log-copy-formats.mjs";

const entry = {
    type: "billing.failed",
    timestamp: "2026-08-11T22:00:00+00:00",
    level: "ERROR",
    channel: "production",
    file: "laravel.log",
    message:
        'Falha ao processar cobrança para pessoa@example.test com marcador ``` interno e {"request_id":"96a543be-fd53-4659-a057-6ec888c362fd"}.',
    context: {
        token: "[REDACTED]",
        tenant: "synthetic",
        user_id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    },
    trace: "#0 [PATH]/BillingService.php(42): process()",
    has_context: true,
    has_trace: true,
    diagnostic: {
        fingerprint: "6f16b7b29b8a6ac82e1f5aa0",
        occurrences: 4,
        first_seen: "2026-08-11T21:00:00+00:00",
        last_seen: "2026-08-11T22:00:00+00:00",
        trace_hint: "#0 [PATH]/BillingService.php(42): process()",
    },
};

test("plain log copy includes the complete sanitized diagnostic context", () => {
    const output = logAsText(entry);

    assert.match(output, /Tipo: billing\.failed/);
    assert.match(output, /Contexto sanitizado:/);
    assert.match(output, /"token": "\[REDACTED\]"/);
    assert.match(output, /\[PATH\]\/BillingService\.php/);
    assert.match(output, /Ocorrências no recorte: 4/);
});

test("technical Markdown cannot be broken by a fence inside the log message", () => {
    const output = logAsMarkdown(entry);

    assert.match(output, /^# Evento de log: billing\.failed/m);
    assert.match(
        output,
        /````text\nFalha ao processar cobrança .* marcador ``` interno.*\n````/,
    );
    assert.match(output, /> Conteúdo sanitizado pela aplicação/);
    assert.match(output, /Evidência diagnóstica/);
    assert.match(output, /não comprova automaticamente a causa raiz/);
});

test("AI Markdown provides evidence-first analysis and safe correction instructions", () => {
    const output = logAsAiMarkdown(entry);

    assert.match(output, /# Solicitação de análise de erro/);
    assert.match(output, /causa raiz mais provável/);
    assert.match(output, /comandos, verificações e testes/);
    assert.match(output, /Não invente código, caminhos, configurações/);
    assert.match(output, /Riscos e rollback/);
    assert.match(output, /Ocorrências no recorte: 4/);
    assert.match(output, /\[REDACTED\]/);
    assert.match(output, /\[IDENTIFIER\]/);
    assert.match(output, /\[EMAIL\]/);
    assert.doesNotMatch(
        output,
        /96a543be-fd53-4659-a057-6ec888c362fd|01ARZ3NDEKTSV4RRFFQ69G5FAV|pessoa@example\.test/,
    );
    assert.doesNotMatch(output, /secret-value|private-value/);
});
