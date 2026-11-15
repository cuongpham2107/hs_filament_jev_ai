import { useEffect, useReducer, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { ArrowUpIcon, SquareIcon } from 'lucide-react'
import { Bubble, BubbleContent } from '@/components/ui/bubble'
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupTextarea } from '@/components/ui/input-group'
import { Message, MessageContent } from '@/components/ui/message'
import {
    MessageScroller,
    MessageScrollerButton,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerProvider,
    MessageScrollerViewport,
} from '@/components/ui/message-scroller'
import { Answer } from './answer'
import { Plan } from './plan'
import { Queue } from './queue'
import { Reasoning } from './reasoning'
import { streamLookup } from './stream'
import type { ChatConfiguration, LookupEvent, Turn } from './types'

type Action =
    | { type: 'add'; messages: string[] }
    | { type: 'start'; id: string }
    | { type: 'event'; id: string; event: LookupEvent }
    | { type: 'fail'; id: string; message: string }
    | { type: 'finish'; id: string }

function reduce(turns: Turn[], action: Action): Turn[] {
    if (action.type === 'add') {
        const added = action.messages.map((message): Turn => ({
            id: crypto.randomUUID(), message, status: 'queued', steps: {}, reasoning: [], evaluation: [],
        }))
        return [...turns, ...added]
    }
    return turns.map((turn) => (turn.id === action.id ? update(turn, action) : turn))
}

function update(turn: Turn, action: Exclude<Action, { type: 'add' }>): Turn {
    switch (action.type) {
        case 'start':
            return { ...turn, status: 'running', startedAt: Date.now() }
        case 'fail':
            return { ...turn, status: 'error', error: action.message, finishedAt: Date.now() }
        case 'finish':
            return turn.status === 'running' ? { ...turn, status: 'done', finishedAt: Date.now() } : turn
        case 'event': {
            const event = action.event
            if (event.type === 'step') {
                return { ...turn, steps: { ...turn.steps, [event.id]: event.status } }
            }
            if (event.type === 'reasoning') {
                return { ...turn, reasoning: [...turn.reasoning, event.text] }
            }
            if (event.type === 'evaluation') {
                return { ...turn, evaluation: event.steps }
            }
            if (event.type === 'result') {
                return { ...turn, result: { status: event.status, markdown: event.markdown } }
            }
            if (event.type === 'error') {
                return { ...turn, status: 'error', error: event.message, finishedAt: Date.now() }
            }
            return turn
        }
    }
}

/** Mỗi mặt hàng cách nhau một dòng trống (một mặt hàng có thể viết trên nhiều dòng, như trên tờ khai). */
function splitItems(text: string): string[] {
    return text.split(/\n\s*\n/).map((item) => item.trim()).filter(Boolean)
}

export function TraCuuChat({ configuration }: { configuration: Readonly<ChatConfiguration> }) {
    const [turns, dispatch] = useReducer(reduce, [])
    const [draft, setDraft] = useState('')
    const abort = useRef<AbortController | null>(null)
    const running = turns.find((turn) => turn.status === 'running')
    const next = turns.find((turn) => turn.status === 'queued')

    useEffect(() => {
        if (running || !next) {
            return
        }
        const controller = new AbortController()
        abort.current = controller
        dispatch({ type: 'start', id: next.id })
        streamLookup(configuration.streamUrl, configuration.csrfToken, next.message,
            (event) => dispatch({ type: 'event', id: next.id, event }), controller.signal)
            .then(() => dispatch({ type: 'finish', id: next.id }))
            .catch((error: unknown) => dispatch({
                type: 'fail', id: next.id,
                message: controller.signal.aborted ? 'Đã dừng.' : error instanceof Error ? error.message : 'Lỗi không rõ',
            }))
    }, [running, next, configuration])

    const submit = (event?: FormEvent) => {
        event?.preventDefault()
        const items = splitItems(draft)
        if (items.length > 0) {
            dispatch({ type: 'add', messages: items })
            setDraft('')
        }
    }

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault()
            submit()
        }
    }

    const showQueue = turns.length > 1

    return (
        <div className="tra-cuu-chat flex h-[75vh] min-h-[480px] flex-col gap-3 rounded-xl p-3">
            {showQueue && (
                <Queue turns={turns} onSelect={(id) => document.getElementById(`turn-${id}`)?.scrollIntoView({ behavior: 'smooth' })} />
            )}
            <MessageScrollerProvider autoScroll>
                <MessageScroller className="flex-1">
                    <MessageScrollerViewport>
                        <MessageScrollerContent className="flex flex-col gap-6 p-2">
                            {turns.length === 0 && (
                                <p className="py-16 text-center text-sm text-muted-foreground">
                                    Nhập mô tả hàng hóa (như dòng "Tên hàng" trên tờ khai). Nhiều mặt hàng thì cách nhau một dòng trống.
                                </p>
                            )}
                            {turns.map((turn) => (
                                <MessageScrollerItem key={turn.id} messageId={turn.id} scrollAnchor={turn.status === 'running'}>
                                    <div id={`turn-${turn.id}`} className="flex flex-col gap-3">
                                        <Message align="end">
                                            <MessageContent>
                                                <Bubble variant="muted" align="end">
                                                    <BubbleContent className="whitespace-pre-wrap">{turn.message}</BubbleContent>
                                                </Bubble>
                                            </MessageContent>
                                        </Message>
                                        {turn.status !== 'queued' && (
                                            <Message>
                                                <MessageContent className="gap-3">
                                                    <Plan steps={configuration.steps} statuses={turn.steps} running={turn.status === 'running'} />
                                                    <Reasoning
                                                        lines={turn.reasoning}
                                                        evaluation={turn.evaluation}
                                                        running={turn.status === 'running'}
                                                        seconds={turn.startedAt && turn.finishedAt ? (turn.finishedAt - turn.startedAt) / 1000 : undefined}
                                                    />
                                                    {turn.result && <Answer markdown={turn.result.markdown} />}
                                                    {turn.error && <p className="text-sm text-destructive">{turn.error}</p>}
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
            <form onSubmit={submit}>
                <InputGroup>
                    <InputGroupTextarea
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={onKeyDown}
                        placeholder="VD: NAN HOA & CHÂN NAN HOA DÀNH CHO XE MÁY (SPOKES & NIPPLES FOR MOTORCYCLE)"
                        rows={3}
                        maxLength={2000}
                        aria-label="Mô tả hàng hóa"
                    />
                    <InputGroupAddon align="block-end" className="justify-between">
                        <span className="text-xs text-muted-foreground">Enter để gửi · Shift+Enter xuống dòng</span>
                        {running ? (
                            <InputGroupButton type="button" variant="outline" size="icon-sm" aria-label="Dừng" onClick={() => abort.current?.abort()}>
                                <SquareIcon />
                            </InputGroupButton>
                        ) : (
                            <InputGroupButton type="submit" variant="default" size="icon-sm" aria-label="Gửi" disabled={!draft.trim()}>
                                <ArrowUpIcon />
                            </InputGroupButton>
                        )}
                    </InputGroupAddon>
                </InputGroup>
            </form>
        </div>
    )
}
