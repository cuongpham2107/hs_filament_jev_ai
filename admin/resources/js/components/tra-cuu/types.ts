/** Sự kiện Laravel (App\Services\DifyRunEvents) gửi về qua Server-Sent Events. */
export type StepStatus = 'pending' | 'running' | 'done' | 'error'

export type LookupEvent =
    | { type: 'step'; id: string; status: Exclude<StepStatus, 'pending'> }
    | { type: 'reasoning'; text: string }
    | { type: 'result'; status: 'ok' | 'can_bo_sung'; markdown: string }
    | { type: 'error'; message: string }
    | { type: 'conversation'; id: string }
    | { type: 'end' }

export type Step = { readonly id: string; readonly label: string }

/** Một mặt hàng cần tra: một lượt hỏi - đáp trong khung chat. */
export type Turn = {
    id: string
    message: string
    status: 'queued' | 'running' | 'done' | 'error'
    steps: Record<string, StepStatus>
    reasoning: string[]
    startedAt?: number
    finishedAt?: number
    result?: { status: 'ok' | 'can_bo_sung'; markdown: string }
    error?: string
    /** Hội thoại Dify; lượt trả lời bổ sung dùng lại hội thoại của lượt 'cần bổ sung' trước đó. */
    conversationId?: string
    /** Lượt này là câu trả lời bổ sung cho lượt trước (không phải mặt hàng mới). */
    isFollowUp?: boolean
}

export type ChatConfiguration = {
    streamUrl: string
    csrfToken: string
    cssUrl: string
    steps: readonly Step[]
}
