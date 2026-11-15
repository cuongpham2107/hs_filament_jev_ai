import { useState, type ReactNode } from "react";
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    CheckIcon,
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
        .replace(/^[🔴🟡🟢]\s*/u, "")
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

function CopyButton({
    text,
    label = "Sao chép",
}: {
    text: string;
    label?: string;
}) {
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
            className="inline-flex items-center gap-1 rounded-lg bg-transparent px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-950/4 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-white/10"
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
        badge: "bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20",
        Icon: ShieldCheckIcon,
    },
    medium: {
        badge: "bg-amber-50 text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20",
        Icon: AlertTriangleIcon,
    },
    high: {
        badge: "bg-rose-50 text-rose-700 ring-1 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20",
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
        <div className="text-sm leading-relaxed text-gray-700 dark:text-gray-200 [&>ul]:my-1 [&>ul]:list-disc [&>ul]:space-y-1 [&>ul]:pl-4 [&_table]:w-full [&_table]:bg-transparent! [&_tbody]:bg-transparent! [&_td]:bg-transparent! [&_th]:bg-transparent! [&_thead]:bg-gray-950/3! dark:[&_thead]:bg-white/4! [&_tr]:bg-transparent! **:data-[streamdown='table-wrapper']:bg-transparent! **:data-[streamdown='table']:bg-transparent!">
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

function parseMarkdownTable(
    md: string,
): { headers: string[]; rows: string[][] } | null {
    const lines = md
        .trim()
        .split("\n")
        .map((line) => line.trim())
        .filter((line) => line.startsWith("|"));
    if (lines.length < 3) {
        return null;
    }
    const splitRow = (line: string): string[] =>
        line
            .replace(/^\|/, "")
            .replace(/\|$/, "")
            .split("|")
            .map((cell) => cell.trim());

    return {
        headers: splitRow(lines[0]),
        rows: lines.slice(2).map(splitRow),
    };
}

function FtaTable({ markdown }: { markdown: string }) {
    const parsed = parseMarkdownTable(markdown);
    if (!parsed) {
        return null;
    }
    return (
        <div className="overflow-x-auto bg-transparent">
            <table className="w-full border-collapse bg-transparent text-left text-xs">
                <thead>
                    <tr className="border-b border-gray-950/10 bg-gray-950/3 dark:border-white/10 dark:bg-white/4">
                        {parsed.headers.map((header, idx) => (
                            <th
                                key={idx}
                                className="px-3 py-2 font-semibold text-gray-700 dark:text-gray-200"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-950/10 bg-transparent dark:divide-white/10">
                    {parsed.rows.map((row, rIdx) => (
                        <tr key={rIdx} className="bg-transparent">
                            {row.map((cell, cIdx) => (
                                <td
                                    key={cIdx}
                                    className={`px-3 py-1.5 ${
                                        cIdx % 2 === 0
                                            ? "font-medium text-gray-700 dark:text-gray-200"
                                            : "text-gray-600 dark:text-gray-300"
                                    }`}
                                >
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
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
            <div className="rounded-xl bg-transparent p-4 ring-1 ring-gray-950/10 dark:ring-white/10">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <span className="font-mono text-2xl font-bold tracking-tight text-(--primary-600) dark:text-(--primary-400)">
                            {parsed.code}
                        </span>
                        <CopyButton text={parsed.code} label="Chép mã" />
                        {codeHref && (
                            <a
                                href={codeHref}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 rounded-lg bg-transparent px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-950/4 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-white/10"
                            >
                                <span>Xem biểu thuế</span>
                                <ExternalLinkIcon className="size-3" />
                            </a>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {parsed.meta && (
                            <span className="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20">
                                <CheckCircle2Icon className="size-3.5" />
                                <span>{parsed.meta}</span>
                            </span>
                        )}
                        <span
                            className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium ${riskStyle.badge}`}
                        >
                            <RiskIcon className="size-3.5" />
                            <span>{parsed.riskText.split(":")[0]}</span>
                        </span>
                    </div>
                </div>

                {parsed.tariffPath && (
                    <div className="mt-3 rounded-lg bg-gray-950/3 px-3 py-2 text-xs leading-relaxed text-gray-600 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
                        <span className="font-semibold text-gray-900 dark:text-white">
                            Theo biểu thuế:{" "}
                        </span>
                        {parsed.tariffPath}
                    </div>
                )}

                {parsed.riskText.includes(":") && (
                    <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {parsed.riskText}
                    </p>
                )}
            </div>

            {/* 2-Column Main Info */}
            {(parsed.importTax || parsed.declaration) && (
                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="overflow-hidden rounded-xl bg-transparent ring-1 ring-gray-950/10 dark:ring-white/10">
                        <div className="flex items-center gap-2 border-b border-gray-950/10 px-4 py-2.5 text-xs font-semibold text-gray-700 dark:border-white/10 dark:text-gray-200">
                            <ScaleIcon className="size-3.5 text-gray-400" />
                            <span>Thuế nhập khẩu & VAT</span>
                        </div>
                        <div className="p-4">
                            <MarkdownBody
                                text={`${parsed.importTax}\n\n**VAT:** ${parsed.vat}${parsed.otherTax ? `\n\n${parsed.otherTax}` : ""}`}
                                links={links}
                                components={markdownComponents}
                            />
                        </div>
                    </div>

                    <div className="flex flex-col divide-y divide-gray-950/10 overflow-hidden rounded-xl bg-transparent ring-1 ring-gray-950/10 dark:divide-white/10 dark:ring-white/10">
                        <div className="p-4">
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <span className="flex items-center gap-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                                    <FileTextIcon className="size-3.5 text-gray-400" />
                                    <span>Mô tả khai báo gợi ý</span>
                                </span>
                                <CopyButton text={parsed.declaration} />
                            </div>
                            <div className="rounded-lg bg-gray-950/3 px-3 py-2 text-sm text-gray-900 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-100 dark:ring-white/10">
                                {parsed.declaration}
                            </div>
                        </div>

                        <div className="p-4">
                            <div className="mb-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200">
                                Căn cứ phân loại
                            </div>
                            <MarkdownBody
                                text={parsed.legalBasis}
                                links={links}
                                components={markdownComponents}
                            />
                        </div>

                        <div className="p-4">
                            <div className="mb-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200">
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
            )}

            {/* Confusable Codes & Chart */}
            {(parsed.confusable || parsed.chartOption) && (
                <div className="grid items-center gap-4 rounded-xl bg-transparent p-4 ring-1 ring-gray-950/10 dark:ring-white/10 lg:grid-cols-2">
                    <div>
                        <div className="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
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
            )}

            {/* Always-open FTA Table */}
            {parsed.ftaTable && parseMarkdownTable(parsed.ftaTable) && (
                <div className="overflow-hidden rounded-xl bg-transparent ring-1 ring-gray-950/10 dark:ring-white/10">
                    <div className="border-b border-gray-950/10 px-4 py-2.5 text-xs font-semibold text-gray-700 dark:border-white/10 dark:text-gray-200">
                        Tất cả mức thuế theo hiệp định FTA
                    </div>
                    <FtaTable markdown={parsed.ftaTable} />
                </div>
            )}
        </div>
    );
}
