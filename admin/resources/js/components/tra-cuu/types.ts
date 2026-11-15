/** Sự kiện Laravel (App\Services\DifyRunEvents) gửi về qua Server-Sent Events. */
export type StepStatus = 'pending' | 'running' | 'done' | 'error'

export type LookupEvent =
    | { type: 'step'; id: string; status: Exclude<StepStatus, 'pending'> }
    | { type: 'reasoning'; text: string }
    | { type: 'evaluation'; steps: EvaluationStep[] }
    | { type: 'result'; status: 'ok' | 'can_bo_sung'; markdown: string }
    | { type: 'error'; message: string }
    | { type: 'end' }

export type Step = { readonly id: string; readonly label: string }

/** Một bước Jev chọn (nhóm 4 số hoặc một cấp cây) và xác suất Jev chấm cho từng lựa chọn. */
export type EvaluationStep = {
    buoc: string
    lua_chon: { ten: string; p: number; chon: boolean }[]
}

/** Một mặt hàng cần tra: một lượt hỏi - đáp trong khung chat. */
export type Turn = {
    id: string
    message: string
    status: 'queued' | 'running' | 'done' | 'error'
    steps: Record<string, StepStatus>
    reasoning: string[]
    evaluation: EvaluationStep[]
    startedAt?: number
    finishedAt?: number
    result?: { status: 'ok' | 'can_bo_sung'; markdown: string }
    error?: string
}

export type ChatConfiguration = {
    streamUrl: string
    csrfToken: string
    cssUrl: string
    steps: readonly Step[]
}
