import { MessageSquareIcon, PlusIcon, Trash2Icon } from "lucide-react";
import type { ConversationSummary } from "./types";

const STATUS_DOT: Record<ConversationSummary["status"], string> = {
    ok: "bg-emerald-500",
    can_bo_sung: "bg-amber-500",
    error: "bg-destructive",
};

function formatShortTime(iso: string | null): string {
    if (!iso) {
        return "";
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return "";
    }
    return date.toLocaleString("vi-VN", {
        day: "2-digit",
        month: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
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
    return (
        <aside className="flex w-64 shrink-0 flex-col gap-2 border-r border-border pr-3 max-md:hidden">
            <button
                type="button"
                onClick={onNewChat}
                className="flex w-full items-center gap-2 rounded-lg border border-border bg-background px-3 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
            >
                <PlusIcon className="size-4 shrink-0" />
                <span>Tra cứu mới</span>
            </button>

            <div className="px-1 pt-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Lịch sử hội thoại
            </div>

            <div className="flex-1 space-y-1 overflow-y-auto pr-0.5">
                {loading && conversations.length === 0 && (
                    <p className="px-2 py-4 text-xs text-muted-foreground">
                        Đang tải…
                    </p>
                )}
                {!loading && conversations.length === 0 && (
                    <p className="px-2 py-4 text-xs text-muted-foreground">
                        Chưa có cuộc hội thoại nào.
                    </p>
                )}
                {conversations.map((item) => {
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
                            className={`group flex cursor-pointer items-start gap-2 rounded-lg px-2.5 py-2 text-left transition ${
                                isActive
                                    ? "bg-muted text-foreground"
                                    : "text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                            }`}
                        >
                            <MessageSquareIcon className="mt-0.5 size-3.5 shrink-0 opacity-70" />
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium">
                                    {item.title}
                                </div>
                                <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <span
                                        className={`inline-block size-1.5 shrink-0 rounded-full ${STATUS_DOT[item.status]}`}
                                    />
                                    {item.ma_hs && (
                                        <span className="font-mono font-medium text-foreground">
                                            {item.ma_hs}
                                        </span>
                                    )}
                                    <span className="truncate">
                                        {formatShortTime(item.updated_at)}
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
                                className="rounded p-1 opacity-0 transition hover:bg-background hover:text-destructive group-hover:opacity-100"
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
