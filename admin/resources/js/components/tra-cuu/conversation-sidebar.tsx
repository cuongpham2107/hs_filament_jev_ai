import { useMemo, useState } from "react";
import { PlusIcon, SearchIcon, Trash2Icon } from "lucide-react";
import type { ConversationSummary } from "./types";

const STATUS_DOT: Record<ConversationSummary["status"], string> = {
    ok: "bg-emerald-500",
    can_bo_sung: "bg-amber-500",
    error: "bg-rose-500",
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

    return sameDay
        ? date.toLocaleTimeString("vi-VN", {
              hour: "2-digit",
              minute: "2-digit",
          })
        : date.toLocaleDateString("vi-VN", {
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
        <aside className="flex w-68 shrink-0 flex-col overflow-hidden rounded-xl bg-transparent ring-1 ring-gray-950/10 dark:ring-white/10 max-md:hidden">
            <div className="flex items-center justify-between gap-2 border-b border-gray-950/10 px-3.5 py-2.5 dark:border-white/10">
                <span className="text-xs font-semibold text-gray-700 dark:text-gray-200">
                    Lịch sử hội thoại
                </span>
                <button
                    type="button"
                    onClick={onNewChat}
                    className="inline-flex items-center gap-1 rounded-lg bg-[var(--primary-600)] px-2.5 py-1 text-xs font-semibold text-white shadow-xs transition hover:bg-[var(--primary-500)]"
                >
                    <PlusIcon className="size-3.5" />
                    <span>Mới</span>
                </button>
            </div>

            <div className="p-2.5 pb-1.5">
                <div className="relative">
                    <SearchIcon className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-gray-400 dark:text-gray-500" />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Tìm theo tên hàng, mã HS…"
                        className="w-full rounded-lg border-0 bg-gray-950/[0.03] py-1.5 pl-8 pr-2.5 text-xs text-gray-900 ring-1 ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-[var(--primary-500)] focus:outline-none dark:bg-white/5 dark:text-white dark:ring-white/15 dark:placeholder:text-gray-500"
                    />
                </div>
            </div>

            <div className="flex-1 space-y-0.5 overflow-y-auto px-2 pb-2">
                {loading && conversations.length === 0 && (
                    <p className="px-2 py-6 text-center text-xs text-gray-400 dark:text-gray-500">
                        Đang tải…
                    </p>
                )}
                {!loading && filtered.length === 0 && (
                    <p className="px-2 py-6 text-center text-xs text-gray-400 dark:text-gray-500">
                        {query.trim()
                            ? "Không tìm thấy hội thoại."
                            : "Chưa có hội thoại nào."}
                    </p>
                )}
                {filtered.map((item) => {
                    const isActive = item.id === activeId;
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
                            className={`group flex cursor-pointer items-start justify-between gap-2 rounded-lg px-2.5 py-2 transition ${
                                isActive
                                    ? "bg-gray-100 text-[var(--primary-600)] dark:bg-white/5 dark:text-[var(--primary-400)]"
                                    : "text-gray-700 hover:bg-gray-100/70 dark:text-gray-200 dark:hover:bg-white/5"
                            }`}
                        >
                            <div className="min-w-0 flex-1">
                                <div
                                    className={`truncate text-xs leading-5 ${
                                        isActive ? "font-semibold" : "font-medium"
                                    }`}
                                >
                                    {item.title}
                                </div>
                                <div className="mt-0.5 flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                                    <span className="inline-flex items-center gap-1.5">
                                        <span
                                            className={`size-1.5 rounded-full ${STATUS_DOT[item.status]}`}
                                        />
                                        {item.ma_hs ? (
                                            <span className="font-mono font-medium text-gray-700 dark:text-gray-300">
                                                {item.ma_hs}
                                            </span>
                                        ) : (
                                            <span>
                                                {item.status === "can_bo_sung"
                                                    ? "Cần bổ sung"
                                                    : "Lỗi"}
                                            </span>
                                        )}
                                    </span>
                                    <span className="ml-auto shrink-0 text-[11px] text-gray-400 dark:text-gray-500">
                                        {formatTime(item.updated_at)}
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                aria-label="Xóa hội thoại"
                                title="Xóa hội thoại"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    onDelete(item.id);
                                }}
                                className="mt-0.5 shrink-0 rounded p-1 text-gray-400 opacity-0 transition hover:bg-white hover:text-rose-600 group-hover:opacity-100 dark:hover:bg-gray-800 dark:hover:text-rose-400"
                            >
                                <Trash2Icon className="size-3.5" />
                            </button>
                        </div>
                    );
                })}
            </div>
        </aside>
    );
}
