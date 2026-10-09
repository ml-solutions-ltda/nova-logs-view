const value = (candidate, fallback = "Não informado") => {
    const normalized = String(candidate ?? "").trim();

    return normalized || fallback;
};

const json = (candidate) => JSON.stringify(candidate ?? {}, null, 2);

const escapeInlineMarkdown = (candidate) =>
    value(candidate).replace(/([\\`*_[\]<>#])/g, "\\$1");

const codeBlock = (language, candidate) => {
    const content = value(candidate, "Sem conteúdo");
    const longestFence = Math.max(
        2,
        ...(content.match(/`+/g) || []).map((match) => match.length),
    );
    const fence = "`".repeat(longestFence + 1);

    return `${fence}${language}\n${content}\n${fence}`;
};

const aiSensitiveKey =
    /(?:^|_)(?:correlation|request|tenant|user|citizen|contact|chat|message|channel|phone|email|document|cpf|cnpj|session)(?:_id)?$/i;

const minimizeTextForAi = (candidate) =>
    value(candidate, "")
        .replace(
            /\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/gi,
            "[IDENTIFIER]",
        )
        .replace(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi, "[EMAIL]")
        .replace(
            /\+?\d{0,3}\s*\(?\d{2,3}\)?\s*\d{4,5}[-.\s]\d{4}\b/g,
            "[PHONE]",
        );

const minimizeForAi = (candidate, key = null) => {
    if (key && aiSensitiveKey.test(key)) {
        return "[IDENTIFIER]";
    }

    if (Array.isArray(candidate)) {
        return candidate.map((item) => minimizeForAi(item));
    }

    if (candidate && typeof candidate === "object") {
        return Object.fromEntries(
            Object.entries(candidate).map(([itemKey, item]) => [
                itemKey,
                minimizeForAi(item, itemKey),
            ]),
        );
    }

    return typeof candidate === "string"
        ? minimizeTextForAi(candidate)
        : candidate;
};

const aiSafeEntry = (entry) => ({
    ...entry,
    message: minimizeTextForAi(entry.message),
    context: minimizeForAi(entry.context),
    trace: minimizeTextForAi(entry.trace),
    diagnostic: minimizeForAi(entry.diagnostic),
});

const metadataLines = (entry) => [
    `Tipo: ${value(entry.type)}`,
    `Data e hora: ${value(entry.timestamp, "Sem data")}`,
    `Nível: ${value(entry.level)}`,
    `Canal: ${value(entry.channel)}`,
    `Arquivo: ${value(entry.file)}`,
];

const markdownMetadata = (entry) =>
    [
        ["Data e hora", entry.timestamp || "Sem data"],
        ["Nível", entry.level],
        ["Canal", entry.channel],
        ["Arquivo", entry.file],
    ]
        .map(([label, item]) => `- **${label}:** ${escapeInlineMarkdown(item)}`)
        .join("\n");

const diagnosticLines = (entry) => {
    if (!entry.diagnostic) return [];

    return [
        `Assinatura: ${value(entry.diagnostic.fingerprint)}`,
        entry.diagnostic.occurrences
            ? `Ocorrências no recorte: ${entry.diagnostic.occurrences}`
            : null,
        entry.diagnostic.first_seen
            ? `Primeira ocorrência: ${entry.diagnostic.first_seen}`
            : null,
        entry.diagnostic.last_seen
            ? `Última ocorrência: ${entry.diagnostic.last_seen}`
            : null,
        entry.diagnostic.trace_hint
            ? `Primeiro frame útil: ${entry.diagnostic.trace_hint}`
            : null,
    ].filter(Boolean);
};

const technicalSections = (entry) => {
    const sections = [`## Mensagem\n\n${codeBlock("text", entry.message)}`];

    if (entry.has_context) {
        sections.push(
            `## Contexto sanitizado\n\n${codeBlock("json", json(entry.context))}`,
        );
    }

    if (entry.has_trace) {
        sections.push(
            `## Rastreamento sanitizado\n\n${codeBlock("text", entry.trace)}`,
        );
    }

    if (entry.diagnostic) {
        sections.push(
            `## Evidência diagnóstica\n\n${diagnosticLines(entry)
                .map((line) => `- ${escapeInlineMarkdown(line)}`)
                .join(
                    "\n",
                )}\n\n> A recorrência orienta a investigação, mas não comprova automaticamente a causa raiz.`,
        );
    }

    return sections.join("\n\n");
};

export const logAsText = (entry) => {
    const sections = [metadataLines(entry).join("\n")];

    if (entry.diagnostic) {
        sections.push(
            `Evidência diagnóstica:\n${diagnosticLines(entry).join("\n")}`,
        );
    }

    sections.push(`Mensagem:\n${value(entry.message, "Sem mensagem")}`);

    if (entry.has_context) {
        sections.push(`Contexto sanitizado:\n${json(entry.context)}`);
    }

    if (entry.has_trace) {
        sections.push(`Rastreamento sanitizado:\n${value(entry.trace)}`);
    }

    return sections.join("\n\n");
};

export const logAsMarkdown = (entry) =>
    [
        `# Evento de log: ${escapeInlineMarkdown(entry.type)}`,
        markdownMetadata(entry),
        technicalSections(entry),
        "> Conteúdo sanitizado pela aplicação antes da cópia.",
    ].join("\n\n");

export const logAsAiMarkdown = (entry) => {
    const safeEntry = aiSafeEntry(entry);

    return [
        "# Solicitação de análise de erro",
        "Analise o evento abaixo como um engenheiro de software responsável pela aplicação. Use apenas as evidências fornecidas e deixe explícito quando uma conclusão for hipótese.",
        "## Objetivo",
        [
            "1. Identifique a causa raiz mais provável e cite as evidências do log.",
            "2. Diferencie causa raiz, sintomas e efeitos secundários.",
            "3. Proponha uma correção pequena e segura, indicando arquivos ou componentes somente quando houver evidência suficiente.",
            "4. Sugira comandos, verificações e testes para confirmar a hipótese e validar a correção.",
            "5. Aponte riscos de regressão e uma estratégia de rollback quando aplicável.",
            "6. Liste objetivamente qualquer contexto adicional necessário antes de alterar código.",
        ].join("\n"),
        "## Restrições",
        [
            "- Não invente código, caminhos, configurações, versões ou estado de infraestrutura ausentes.",
            "- Não solicite nem tente reconstruir credenciais, tokens, cookies ou dados mascarados.",
            "- Trate `[REDACTED]`, `[IDENTIFIER]`, `[EMAIL]`, `[PHONE]` e `[PATH]` como sanitizações intencionais.",
            "- Priorize diagnóstico e validação antes de recomendar mudanças destrutivas.",
        ].join("\n"),
        `## Evento\n\n### ${escapeInlineMarkdown(safeEntry.type)}\n\n${markdownMetadata(safeEntry)}`,
        technicalSections(safeEntry),
        "## Formato esperado da resposta",
        [
            "- Diagnóstico resumido",
            "- Evidências",
            "- Correção proposta",
            "- Passos de validação",
            "- Riscos e rollback",
            "- Contexto faltante",
        ].join("\n"),
        "> Conteúdo sanitizado pela aplicação; identificadores adicionais foram minimizados para compartilhamento com IA.",
    ].join("\n\n");
};
