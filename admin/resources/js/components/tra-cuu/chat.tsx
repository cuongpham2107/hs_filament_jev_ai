import {
    useCallback,
    useEffect,
    useReducer,
    useRef,
    useState,
    type FormEvent,
    type KeyboardEvent,
} from "react";
import { ArrowUpIcon, SquareIcon } from "lucide-react";
import { Bubble, BubbleContent } from "@/components/ui/bubble";
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupTextarea,
} from "@/components/ui/input-group";
import { Message, MessageContent } from "@/components/ui/message";
import {
    MessageScroller,
    MessageScrollerButton,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerProvider,
    MessageScrollerViewport,
} from "@/components/ui/message-scroller";
import { Answer } from "./answer";
import { ConversationSidebar } from "./conversation-sidebar";
import { Plan } from "./plan";
import { Queue } from "./queue";
import { Reasoning } from "./reasoning";
import { streamLookup } from "./stream";
import type {
    ChatConfiguration,
    ConversationSummary,
    LookupEvent,
    Turn,
} from "./types";

type Action =
    | { type: "load"; turns: Turn[] }
    | { type: "add"; messages: string[]; conversationId?: string }
    | { type: "start"; id: string }
    | { type: "event"; id: string; event: LookupEvent }
    | { type: "fail"; id: string; message: string }
    | { type: "finish"; id: string };

function reduce(turns: Turn[], action: Action): Turn[] {
    if (action.type === "load") {
        return action.turns;
    }
    if (action.type === "add") {
        const added = action.messages.map(
            (message): Turn => ({
                id: crypto.randomUUID(),
                message,
                status: "queued",
                steps: {},
                reasoning: [],
                conversationId: action.conversationId,
                isFollowUp: action.conversationId !== undefined,
            }),
        );
        return [...turns, ...added];
    }
    return turns.map((turn) =>
        turn.id === action.id ? update(turn, action) : turn,
    );
}

function update(turn: Turn, action: Exclude<Action, { type: "add" }>): Turn {
    switch (action.type) {
        case "start":
            return { ...turn, status: "running", startedAt: Date.now() };
        case "fail":
            return {
                ...turn,
                status: "error",
                error: action.message,
                finishedAt: Date.now(),
            };
        case "finish":
            return turn.status === "running"
                ? { ...turn, status: "done", finishedAt: Date.now() }
                : turn;
        case "event": {
            const event = action.event;
            if (event.type === "step") {
                return {
                    ...turn,
                    steps: { ...turn.steps, [event.id]: event.status },
                };
            }
            if (event.type === "reasoning") {
                return { ...turn, reasoning: [...turn.reasoning, event.text] };
            }
            if (event.type === "result") {
                return {
                    ...turn,
                    result: {
                        status: event.status,
                        markdown: event.markdown,
                        code: event.code,
                        confidence: event.confidence,
                    },
                };
            }
            if (event.type === "conversation") {
                return { ...turn, conversationId: event.id };
            }
            if (event.type === "error") {
                return {
                    ...turn,
                    status: "error",
                    error: event.message,
                    finishedAt: Date.now(),
                };
            }
            return turn;
        }
    }
}

/** Mỗi mặt hàng cách nhau một dòng trống (một mặt hàng có thể viết trên nhiều dòng, như trên tờ khai). */
function splitItems(text: string): string[] {
    return text
        .split(/\n\s*\n/)
        .map((item) => item.trim())
        .filter(Boolean);
}

export function TraCuuChat({
    configuration,
}: {
    configuration: Readonly<ChatConfiguration>;
}) {
    const [turns, dispatch] = useReducer(reduce, []);
    const [draft, setDraft] = useState("");
    const [dismissed, setDismissed] = useState<string | null>(null);
    const [conversations, setConversations] = useState<ConversationSummary[]>(
        [],
    );
    const [activeConversationId, setActiveConversationId] = useState<
        string | null
    >(null);
    const [loadingConversations, setLoadingConversations] = useState(false);
    const abort = useRef<AbortController | null>(null);
    const running = turns.find((turn) => turn.status === "running");
    const next = turns.find((turn) => turn.status === "queued");
    // Lượt cuối đang 'cần bổ sung': câu gõ tiếp theo là câu trả lời, gửi trong cùng hội thoại
    const last = turns[turns.length - 1] as Turn | undefined;
    const followUp =
        last &&
        last.status === "done" &&
        last.result?.status === "can_bo_sung" &&
        last.conversationId &&
        last.id !== dismissed
            ? last
            : undefined;

    const refreshConversations = useCallback(async () => {
        if (!configuration.conversationsUrl) {
            return;
        }
        setLoadingConversations(true);
        try {
            const response = await fetch(configuration.conversationsUrl, {
                headers: { Accept: "application/json" },
            });
            if (response.ok) {
                const data = (await response.json()) as {
                    conversations?: ConversationSummary[];
                };
                setConversations(data.conversations ?? []);
            }
        } finally {
            setLoadingConversations(false);
        }
    }, [configuration.conversationsUrl]);

    useEffect(() => {
        void refreshConversations();
    }, [refreshConversations]);

    const handleNewChat = () => {
        abort.current?.abort();
        setActiveConversationId(null);
        setDismissed(null);
        dispatch({ type: "load", turns: [] });
    };

    const handleSelectConversation = async (id: string) => {
        if (running) {
            abort.current?.abort();
        }
        setActiveConversationId(id);
        setDismissed(null);
        const url = configuration.conversationUrlTemplate.replace(
            "__ID__",
            encodeURIComponent(id),
        );
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
        });
        if (response.ok) {
            const data = (await response.json()) as { turns?: Turn[] };
            dispatch({ type: "load", turns: data.turns ?? [] });
        }
    };

    const handleDeleteConversation = async (id: string) => {
        const url = configuration.conversationUrlTemplate.replace(
            "__ID__",
            encodeURIComponent(id),
        );
        const response = await fetch(url, {
            method: "DELETE",
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": configuration.csrfToken,
            },
        });
        if (response.ok) {
            setConversations((prev) => prev.filter((item) => item.id !== id));
            if (activeConversationId === id) {
                handleNewChat();
            }
        }
    };

    useEffect(() => {
        if (running || !next) {
            return;
        }
        const controller = new AbortController();
        abort.current = controller;
        dispatch({ type: "start", id: next.id });
        streamLookup(
            configuration.streamUrl,
            configuration.csrfToken,
            next.message,
            next.conversationId,
            (event) => {
                if (event.type === "conversation") {
                    setActiveConversationId(event.id);
                }
                dispatch({ type: "event", id: next.id, event });
            },
            controller.signal,
        )
            .then(() => {
                dispatch({ type: "finish", id: next.id });
                void refreshConversations();
            })
            .catch((error: unknown) => {
                dispatch({
                    type: "fail",
                    id: next.id,
                    message: controller.signal.aborted
                        ? "Đã dừng."
                        : error instanceof Error
                          ? error.message
                          : "Lỗi không rõ",
                });
                void refreshConversations();
            });
    }, [running, next, configuration, refreshConversations]);

    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        const items = splitItems(draft);
        if (items.length === 0) {
            return;
        }
        // nhiều mặt hàng dán cùng lúc thì mỗi mặt hàng một hội thoại mới, không coi là câu trả lời bổ sung
        const conversationId =
            followUp && items.length === 1
                ? followUp.conversationId
                : undefined;
        dispatch({ type: "add", messages: items, conversationId });
        setDraft("");
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            event.key === "Enter" &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing
        ) {
            event.preventDefault();
            submit();
        }
    };

    const showQueue = turns.length > 1;

    return (
        <div className="tra-cuu-chat flex h-[calc(100dvh-16rem)] min-h-120 w-full gap-4 rounded-xl">
            <ConversationSidebar
                conversations={conversations}
                activeId={activeConversationId}
                loading={loadingConversations}
                onNewChat={handleNewChat}
                onSelect={(id) => void handleSelectConversation(id)}
                onDelete={(id) => void handleDeleteConversation(id)}
            />
            <div className="flex min-w-0 flex-1 flex-col gap-3">
                {showQueue && (
                    <Queue
                        turns={turns}
                        onSelect={(id) =>
                            document
                                .getElementById(`turn-${id}`)
                                ?.scrollIntoView({ behavior: "smooth" })
                        }
                    />
                )}
                <MessageScrollerProvider autoScroll>
                <MessageScroller className="flex-1">
                    <MessageScrollerViewport>
                        <MessageScrollerContent className="flex flex-col gap-6 p-2">
                            {turns.length === 0 && (
                                <p className="py-16 text-center text-sm text-muted-foreground">
                                    Nhập mô tả hàng hóa (như dòng "Tên hàng"
                                    trên tờ khai). Nhiều mặt hàng thì cách nhau
                                    một dòng trống.
                                </p>
                            )}
                            {turns.map((turn) => (
                                <MessageScrollerItem
                                    key={turn.id}
                                    messageId={turn.id}
                                    scrollAnchor={turn.status === "running"}
                                >
                                    <div
                                        id={`turn-${turn.id}`}
                                        className="flex flex-col gap-3"
                                    >
                                        <Message align="end">
                                            <MessageContent>
                                                <Bubble
                                                    variant="muted"
                                                    align="end"
                                                >
                                                    <BubbleContent className="whitespace-pre-wrap">
                                                        {turn.message}
                                                    </BubbleContent>
                                                </Bubble>
                                            </MessageContent>
                                        </Message>
                                        {turn.status !== "queued" && (
                                            <Message>
                                                <MessageContent className="gap-3">
                                                    <Plan
                                                        steps={
                                                            configuration.steps
                                                        }
                                                        statuses={turn.steps}
                                                        running={
                                                            turn.status ===
                                                            "running"
                                                        }
                                                    />
                                                    <Reasoning
                                                        lines={turn.reasoning}
                                                        running={
                                                            turn.status ===
                                                            "running"
                                                        }
                                                        seconds={
                                                            turn.durationSeconds ??
                                                            (turn.startedAt &&
                                                            turn.finishedAt
                                                                ? (turn.finishedAt -
                                                                      turn.startedAt) /
                                                                  1000
                                                                : undefined)
                                                        }
                                                        links={configuration}
                                                    />
                                                    {turn.result && (
                                                        <Answer
                                                            markdown={
                                                                turn.result
                                                                    .markdown
                                                            }
                                                            links={
                                                                configuration
                                                            }
                                                        />
                                                    )}
                                                    {turn.error && (
                                                        <p className="text-sm text-destructive">
                                                            {turn.error}
                                                        </p>
                                                    )}
                                                </MessageContent>
                                            </Message>
                                        )}
                                    </div>
                                </MessageScrollerItem>
                            ))}
                        </MessageScrollerContent>
                    </MessageScrollerViewport>
                    <MessageScrollerButton />
                </MessageScroller>
            </MessageScrollerProvider>
            {followUp && (
                <div className="flex items-center justify-between gap-3 rounded-lg bg-muted px-3 py-2 text-sm">
                    <span className="min-w-0 truncate text-muted-foreground">
                        Trả lời phần còn thiếu cho:{" "}
                        <span className="text-foreground">
                            {followUp.message.split("\n")[0]}
                        </span>
                    </span>
                    <button
                        type="button"
                        className="shrink-0 text-sm font-medium underline-offset-4 hover:underline"
                        onClick={() => setDismissed(followUp.id)}
                    >
                        Mặt hàng mới
                    </button>
                </div>
            )}
            <form
                onSubmit={submit}
                className="fi-section rounded-xl bg-white p-1 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            >
                <InputGroup className="border-0 bg-transparent shadow-none">
                    <InputGroupTextarea
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={onKeyDown}
                        placeholder={
                            followUp
                                ? "VD: dùng cho nam giới"
                                : "VD: NAN HOA & CHÂN NAN HOA DÀNH CHO XE MÁY (SPOKES & NIPPLES FOR MOTORCYCLE)"
                        }
                        rows={3}
                        maxLength={2000}
                        aria-label="Mô tả hàng hóa"
                    />
                    <InputGroupAddon
                        align="block-end"
                        className="justify-between"
                    >
                        <span className="text-xs text-muted-foreground">
                            Enter để gửi · Shift+Enter xuống dòng
                        </span>
                        {running ? (
                            <InputGroupButton
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                aria-label="Dừng"
                                onClick={() => abort.current?.abort()}
                            >
                                <SquareIcon />
                            </InputGroupButton>
                        ) : (
                            <InputGroupButton
                                type="submit"
                                variant="default"
                                size="icon-sm"
                                aria-label="Gửi"
                                disabled={!draft.trim()}
                            >
                                <ArrowUpIcon />
                            </InputGroupButton>
                        )}
                    </InputGroupAddon>
                </InputGroup>
            </form>
            </div>
        </div>
    );
}
