import type { LookupEvent } from './types'

/** Gọi route tra-cuu.stream và đọc Server-Sent Events ("data: {...}\n\n") từ body của POST. */
export async function streamLookup(
    url: string,
    csrfToken: string,
    message: string,
    onEvent: (event: LookupEvent) => void,
    signal?: AbortSignal,
): Promise<void> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ message }),
        credentials: 'same-origin',
        signal,
    })
    if (!response.ok || !response.body) {
        const reason = response.status === 419 ? 'Phiên đăng nhập hết hạn, hãy tải lại trang.'
            : response.status === 429 ? 'Gửi quá nhiều yêu cầu, thử lại sau một phút.'
            : `Máy chủ trả lỗi ${response.status}.`
        throw new Error(reason)
    }

    const reader = response.body.pipeThrough(new TextDecoderStream()).getReader()
    let buffer = ''
    for (;;) {
        const { value, done } = await reader.read()
        if (done) {
            break
        }
        buffer += value
        let end: number
        while ((end = buffer.indexOf('\n\n')) !== -1) {
            const chunk = buffer.slice(0, end)
            buffer = buffer.slice(end + 2)
            const data = chunk.split('\n').filter((line) => line.startsWith('data:')).map((line) => line.slice(5).trim()).join('')
            if (data) {
                onEvent(JSON.parse(data) as LookupEvent)
            }
        }
    }
}
