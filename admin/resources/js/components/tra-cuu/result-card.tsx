import { useState, type ReactNode } from "react";
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    CheckIcon,
    ChevronDownIcon,
    CopyIcon,
    ExternalLinkIcon,
    FileTextIcon,
    ScaleIcon,
    ShieldAlertIcon,
    ShieldCheckIcon,
} from "lucide-react";
import { Streamdown, type Components } from "streamdown";
import { linkCodesInMarkdown, type CodeLinks } from "./links";

type ParsedResultCard = {
    item: string;
    code: string;
    meta: string;
    tariffPath: string;
    riskLevel: "low" | "medium" | "high";
    riskText: string;
    declaration: string;
    legalBasis: string;
    importTax: string;
    vat: string;
    otherTax?: string;
    policies: string;
    confusable: string;
    chartOption?: string;
    ftaTable: string;
};

const ECHARTS_RE = /```echarts\s*\n([\s\S]*?)\n```/;

function stripCodeFence(text: string): string {
    const match = text.trim().match(/^```(?:text)?\s*\n([\s\S]*?)\n```$/);
    return match ? match[1].trim() : text.trim();
}

function parseResultCard(markdown: string): ParsedResultCard | null {
    if (
        !markdown.includes("### 1. Mặt hàng") ||
        !markdown.includes("### 2. Mã HS")
    ) {
        return null;
    }

    const sections = new Map<string, string>();
    const matches = [...markdown.matchAll(/^###\s+(\d+)\.\s+([^\n]+)\n/gm)];
    for (let i = 0; i < matches.length; i++) {
        const num = matches[i][1];
        const start = (matches[i].index ?? 0) + matches[i][0].length;
        const end =
            i + 1 < matches.length
                ? (matches[i + 1].index ?? markdown.length)
                : markdown.length;
        sections.set(num, markdown.slice(start, end).trim());
    }

    const item = (sections.get("1") ?? "").replace(/^\*\*|\*\*$/g, "").trim();
    const rawCode = sections.get("2") ?? "";
    const codeMatch = rawCode.match(/(\d{4}\.\d{2}\.\d{2}|\d{8})/);
    const code = codeMatch ? codeMatch[1] : rawCode;
    const metaMatch = rawCode.match(/`([^`]+)`/);
    const meta = metaMatch ? metaMatch[1] : "";

    const rawRisk = sections.get("4") ?? "";
    const riskLevel: ParsedResultCard["riskLevel"] = rawRisk.includes("🔴")
        ? "high"
        : rawRisk.includes("🟡")
          ? "medium"
          : "low";
    const riskText = rawRisk
        .replace(/^[🔴🟡🟢]\s*/, "")
        .replace(/\*\*/g, "")
        .trim();

    const raw11 = sections.get("11") ?? "";
    const chartMatch = raw11.match(ECHARTS_RE);
    const chartOption = chartMatch ? chartMatch[1] : undefined;
    const confusable = raw11
        .replace(ECHARTS_RE, "")
        .replace(/\*\*So sánh xác suất các mã \(Jev\):\*\*/g, "")
        .trim();

    return {
        item,
        code,
        meta,
        tariffPath: sections.get("3") ?? "",
        riskLevel,
        riskText,
        declaration: stripCodeFence(sections.get("5") ?? ""),
        legalBasis: sections.get("6") ?? "",
        importTax: sections.get("7") ?? "",
        vat: sections.get("8") ?? "",
        otherTax: sections.get("9"),
        policies: sections.get("10") ?? "",
        confusable,
        chartOption,
        ftaTable: sections.get("12") ?? "",
    };
}

function CopyButton({ text, label = "Sao chép" }: { text: string; label?: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            // bỏ qua nếu clipboard không khả dụng
        }
    };

    return (
        <button
            type="button"
            onClick={() => void copy()}
            title={label}
            className="inline-flex items-center gap-1 rounded-md border border-border bg-background px-2 py-1 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
        >
            {copied ? (
                <>
                    <CheckIcon className="size-3.5 text-emerald-500" />
                    <span>Đã chép</span>
                </>
            ) : (
                <>
                    <CopyIcon className="size-3.5" />
                    <span>{label}</span>
                </>
            )}
        </button>
    );
}

const RISK_STYLES = {
    low: {
        badge: "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300",
        Icon: ShieldCheckIcon,
    },
    medium: {
        badge: "border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300",
        Icon: AlertTriangleIcon,
    },
    high: {
        badge: "border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300",
        Icon: ShieldAlertIcon,
    },
};

function MarkdownBody({
    text,
    links,
    components,
}: {
    text: string;
    links: CodeLinks;
    components: Components;
}) {
    return (
        <div className="text-sm leading-relaxed text-foreground [&>ul]:my-1 [&>ul]:list-disc [&>ul]:space-y-1 [&>ul]:pl-4 [&_table]:w-full [&_table]:text-xs">
            <Streamdown
                mode="static"
                linkSafety={{ enabled: false }}
                components={components}
            >
                {linkCodesInMarkdown(text, links)}
            </Streamdown>
        </div>
    );
}

export function StructuredResultCard({
    markdown,
    links,
    markdownComponents,
    renderChart,
}: {
    markdown: string;
    links: CodeLinks;
    markdownComponents: Components;
    renderChart: (option: string) => ReactNode;
}) {
    const parsed = parseResultCard(markdown);
    if (!parsed) {
        return null;
    }

    const digits = parsed.code.replace(/\./g, "");
    const codeHref = links.codeUrl ? `${links.codeUrl}?ma=${digits}` : null;
    const riskStyle = RISK_STYLES[parsed.riskLevel];
    const RiskIcon = riskStyle.Icon;

    return (
        <div className="flex flex-col gap-3">
            {/* Hero Summary Card */}
            <div className="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            {parsed.item}
                        </div>
                        <div className="mt-1.5 flex flex-wrap items-center gap-2.5">
                            <span className="font-mono text-2xl font-bold tracking-tight text-red-600 dark:text-red-400">
                                {parsed.code}
                            </span>
                            <CopyButton text={parsed.code} label="Chép mã" />
                            {codeHref && (
                                <a
                                    href={codeHref}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 rounded-md border border-border bg-background px-2 py-1 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                >
                                    <span>Xem biểu thuế</span>
                                    <ExternalLinkIcon className="size-3" />
                                </a>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {parsed.meta && (
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300">
                                <CheckCircle2Icon className="size-3.5" />
                                <span>{parsed.meta}</span>
                            </span>
                        )}
                        <span
                            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium ${riskStyle.badge}`}
                        >
                            <RiskIcon className="size-3.5" />
                            <span>{parsed.riskText.split(":")[0]}</span>
                        </span>
                    </div>
                </div>

                {parsed.tariffPath && (
                    <div className="mt-3 rounded-lg bg-muted/60 px-3 py-2 text-xs leading-relaxed text-muted-foreground">
                        <span className="font-medium text-foreground">
                            Theo biểu thuế:{" "}
                        </span>
                        {parsed.tariffPath}
                    </div>
                )}

                {parsed.riskText.includes(":") && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        {parsed.riskText}
                    </p>
                )}
            </div>

            {/* 2-Column Main Info */}
            <div className="grid gap-3 lg:grid-cols-2">
                <div className="rounded-xl border border-border bg-card p-3.5 shadow-xs">
                    <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        <ScaleIcon className="size-3.5" />
                        <span>Thuế nhập khẩu & VAT</span>
                    </div>
                    <MarkdownBody
                        text={`${parsed.importTax}\n\n**VAT:** ${parsed.vat}${parsed.otherTax ? `\n\n${parsed.otherTax}` : ""}`}
                        links={links}
                        components={markdownComponents}
                    />
                </div>

                <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-3.5 shadow-xs">
                    <div>
                        <div className="mb-1.5 flex items-center justify-between gap-2">
                            <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                <FileTextIcon className="size-3.5" />
                                <span>Mô tả khai báo gợi ý</span>
                            </span>
                            <CopyButton text={parsed.declaration} />
                        </div>
                        <div className="rounded-lg border border-border bg-muted/50 px-3 py-2 text-sm text-foreground">
                            {parsed.declaration}
                        </div>
                    </div>

                    <div>
                        <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Căn cứ phân loại
                        </div>
                        <MarkdownBody
                            text={parsed.legalBasis}
                            links={links}
                            components={markdownComponents}
                        />
                    </div>

                    <div>
                        <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Chính sách mặt hàng
                        </div>
                        <MarkdownBody
                            text={parsed.policies}
                            links={links}
                            components={markdownComponents}
                        />
                    </div>
                </div>
            </div>

            {/* Confusable Codes & Chart */}
            <div className="grid items-center gap-3 rounded-xl border border-border bg-card p-3.5 shadow-xs lg:grid-cols-2">
                <div>
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Mã dễ nhầm & So sánh xác suất
                    </div>
                    <MarkdownBody
                        text={parsed.confusable}
                        links={links}
                        components={markdownComponents}
                    />
                </div>
                {parsed.chartOption && (
                    <div className="flex justify-center">
                        {renderChart(parsed.chartOption)}
                    </div>
                )}
            </div>

            {/* Collapsible FTA Table */}
            {parsed.ftaTable && (
                <details className="group rounded-xl border border-border bg-card shadow-xs">
                    <summary className="flex cursor-pointer list-none items-center justify-between px-3.5 py-2.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground">
                        <span>Tất cả mức thuế theo hiệp định FTA</span>
                        <ChevronDownIcon className="size-4 transition group-open:rotate-180" />
                    </summary>
                    <div className="border-t border-border px-3.5 py-3">
                        <MarkdownBody
                            text={parsed.ftaTable}
                            links={links}
                            components={markdownComponents}
                        />
                    </div>
                </details>
            )}
        </div>
    );
}
