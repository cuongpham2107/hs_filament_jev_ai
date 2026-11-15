import { createRoot } from 'react-dom/client'
import type { JsWidgetProps, JsWidgetRenderer } from '@filament/widgets/js-widget'
import { TraCuuChat } from '@/components/tra-cuu/chat'
import type { ChatConfiguration } from '@/components/tra-cuu/types'

/** CSS riêng của khung chat (resources/css/tra-cuu-chat.css): Filament không tự nạp CSS của renderer. */
function loadStylesheet(href: string): void {
    if (!document.querySelector(`link[data-tra-cuu-chat]`)) {
        const link = Object.assign(document.createElement('link'), { rel: 'stylesheet', href })
        link.dataset.traCuuChat = ''
        document.head.append(link)
    }
}

const mountTraCuuChat: JsWidgetRenderer<ChatConfiguration> = ({ host, props: initialProps }) => {
    loadStylesheet(initialProps.configuration.cssUrl)
    const root = createRoot(host)
    const update = (props: JsWidgetProps<ChatConfiguration>) => root.render(<TraCuuChat configuration={props.configuration} />)
    update(initialProps)

    return { update, destroy: () => root.unmount() }
}

export default mountTraCuuChat
