import { useMemo, useState } from "react";
import {
    MessageSquarePlusIcon,
    SearchIcon,
    Trash2Icon,
} from "lucide-react";
import type { ConversationSummary } from "./types";

const STATUS_META: Record<
    ConversationSummary["status"],
    { dot: string; badge: string }
> = {
    ok: {
        dot: "bg-emerald-500",
        badge: "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300",
    },
    can_bo_sung: {
        dot: "bg-amber-500",
        badge: "border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300",
    },
    error: {
        dot: "bg-red-500",
        badge: "border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300",
    },
};

function formatTime(iso: string | null): string {
    if (!iso) {
        return "";
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return "";
    }
    const now = new Date();
    const sameDay =
        date.getFullYear() === now.getFullYear() &&
        date.getMonth() === now.getMonth() &&
        date.getDate() === now.getDate();

    if (sameDay) {
        return date.toLocaleTimeString("vi-VN", {
            hour: "2-digit",
            minute: "2-digit",
        });
    }

    return date.toLocaleDateString("vi-VN", {
        day: "2-digit",
        month: "2-digit",
    });
}

export function ConversationSidebar({
    conversations,
    activeId,
    loading,
    onNewChat,
    onSelect,
    onDelete,
}: {
    conversations: readonly ConversationSummary[];
    activeId: string | null;
    loading: boolean;
    onNewChat: () => void;
    onSelect: (id: string) => void;
    onDelete: (id: string) => void;
}) {
    const [query, setQuery] = useState("");

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) {
            return conversations;
        }
        return conversations.filter(
            (item) =>
                item.title.toLowerCase().includes(q) ||
                (item.ma_hs && item.ma_hs.toLowerCase().includes(q)),
        );
    }, [conversations, query]);

    return (
        <aside className="flex w-72 shrink-0 flex-col gap-3 rounded-xl border border-border bg-card p-3 shadow-xs max-md:hidden">
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Hội thoại ({conversations.length})
                </span>
                <button
                    type="button"
                    onClick={onNewChat}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-amber-500 px-2.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-amber-600"
                >
                    <MessageSquarePlusIcon className="size-3.5" />
                    <span>Tra cứu mới</span>
                </button>
            </div>

            <div className="relative">
                <SearchIcon className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Tìm tên hàng hoặc mã HS…"
                    className="w-full rounded-lg border border-border bg-background py-1.5 pl-8 pr-2.5 text-xs text-foreground placeholder:text-muted-foreground focus:border-amber-500 focus:outline-none"
                />
            </div>

            <div className="flex-1 space-y-1.5 overflow-y-auto pr-0.5">
                {loading && conversations.length === 0 && (
                    <p className="px-2 py-6 text-center text-xs text-muted-foreground">
                        Đang tải…
                    </p>
                )}
                {!loading && filtered.length === 0 && (
                    <p className="px-2 py-6 text-center text-xs text-muted-foreground">
                        {query.trim()
                            ? "Không tìm thấy hội thoại phù hợp."
                            : "Chưa có cuộc hội thoại nào."}
                    </p>
                )}
                {filtered.map((item) => {
                    const isActive = item.id === activeId;
                    const status = STATUS_META[item.status];
                    return (
                        <div
                            key={item.id}
                            role="button"
                            tabIndex={0}
                            onClick={() => onSelect(item.id)}
                            onKeyDown={(event) => {
                                if (event.key === "Enter" || event.key === " ") {
                                    event.preventDefault();
                                    onSelect(item.id);
                                }
                            }}
                            className={`group relative cursor-pointer rounded-lg border px-2.5 py-2 transition ${
                                isActive
                                    ? "border-amber-300 bg-amber-50/70 shadow-xs dark:border-amber-700/60 dark:bg-amber-950/30"
                                    : "border-transparent hover:border-border hover:bg-muted/50"
                            }`}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="line-clamp-2 text-[13px] font-medium leading-snug text-foreground">
                                    {item.title}
                                </div>
                                <button
                                    type="button"
                                    aria-label="Xóa hội thoại"
                                    title="Xóa hội thoại"
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        onDelete(item.id);
                                    }}
                                    className="shrink-0 rounded p-1 text-muted-foreground opacity-0 transition hover:bg-background hover:text-destructive group-hover:opacity-100"
                                >
                                    <Trash2Icon className="size-3.5" />
                                </button>
                            </div>

                            <div className="mt-1.5 flex items-center justify-between gap-2">
                                {item.ma_hs ? (
                                    <span
                                        className={`inline-flex items-center gap-1.5 rounded-md border px-1.5 py-0.5 font-mono text-[11px] font-semibold ${status.badge}`}
                                    >
                                        <span
                                            className={`size-1.5 rounded-full ${status.dot}`}
                                        />
                                        {item.ma_hs}
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                        <span
                                            className={`size-1.5 rounded-full ${status.dot}`}
                                        />
                                        {item.status === "can_bo_sung"
                                            ? "Cần bổ sung"
                                            : "Lỗi"}
                                    </span>
                                )}
                                <span className="text-[11px] text-muted-foreground">
                                    {formatTime(item.updated_at)}
                                </span>
                            </div>
                        </div>
                    );
                })}
            </div>
        </aside>
    );
}
