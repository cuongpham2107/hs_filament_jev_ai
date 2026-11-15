import type { ReactNode } from 'react'

/** Đường dẫn tới trang cây Mã HS của năm biểu thuế đang dùng (null khi chưa có năm nào sẵn sàng). */
export type CodeLinks = { readonly codeUrl: string | null }

export function codeHref(links: CodeLinks, code: string): string | null {
    const digits = code.replace(/\D/g, '')
    if (!links.codeUrl) {
        return null
    }
    const param = digits.length === 8 ? `ma=${digits}` : `nhom=${digits}`
    return `${links.codeUrl}${links.codeUrl.includes('?') ? '&' : '?'}${param}`
}

// mã 8 số dạng 8714.10.20, có thể nằm trong `...` (mục "Mã dễ nhầm" của thẻ)
const CODE_8 = /`?(?<![\d.[])(\d{4}\.\d{2}\.\d{2})(?![\d.])`?/g

/** Biến mọi mã 8 số trong markdown của thẻ thành link mở bản ghi mã đó trong CSDL. */
export function linkCodesInMarkdown(markdown: string, links: CodeLinks): string {
    if (!links.codeUrl) {
        return markdown
    }
    return markdown.replace(CODE_8, (_, code: string) => `[${code}](${codeHref(links, code)})`)
}

// trong phần căn cứ: mã 8 số, hoặc nhóm 4 số dạng 87.14 đứng sau "Nhóm " hay đầu dòng "- "
const CODE_IN_TEXT = /(\d{4}\.\d{2}\.\d{2})(?![\d.])|(?<=Nhóm |^- )(\d{2}\.\d{2})(?![\d.])/gm

export function LinkedText({ text, links }: { text: string; links: CodeLinks }) {
    const parts: ReactNode[] = []
    let last = 0
    for (const match of text.matchAll(CODE_IN_TEXT)) {
        const code = match[0]
        const href = codeHref(links, code)
        const index = match.index ?? 0
        parts.push(text.slice(last, index))
        parts.push(href
            ? <a key={index} href={href} target="_blank" rel="noopener" className="font-medium text-foreground underline decoration-dotted underline-offset-4 hover:decoration-solid">{code}</a>
            : code)
        last = index + code.length
    }
    parts.push(text.slice(last))
    return <>{parts}</>
}
