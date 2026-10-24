/**
 * The AI accountant chat. Questions are posted as JSON; the answer comes back when the AI has finished
 * (tool calls included). Text is escaped before the little formatting we allow (bold, line breaks).
 */
function escapeHtml(text) {
    return String(text)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

export function formatAnswer(text) {
    return escapeHtml(text)
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/^#{1,4}\s+(.+)$/gm, '<strong>$1</strong>')
        .replace(/^\s*[-*]\s+/gm, '• ')
        .replace(/\n/g, '<br>');
}

export function registerAiChat(Alpine) {
    Alpine.data('aiChat', (config) => ({
        url: config.url,
        conversationId: config.conversationId ?? null,
        messages: config.messages ?? [],
        draft: '',
        busy: false,
        error: null,

        init() {
            this.$nextTick(() => this.scrollDown());
        },

        ask(text) {
            this.draft = text;
            this.send();
        },

        async send() {
            const text = this.draft.trim();

            if (text === '' || this.busy) {
                return;
            }

            this.messages.push({ role: 'user', content: text });
            this.draft = '';
            this.busy = true;
            this.error = null;
            this.$nextTick(() => this.scrollDown());

            try {
                const response = await fetch(this.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ message: text, conversation_id: this.conversationId }),
                });
                const data = await response.json().catch(() => ({}));

                if (data.conversation_id && this.conversationId === null) {
                    this.conversationId = data.conversation_id;
                    window.history.replaceState({}, '', `?c=${data.conversation_id}`);
                }

                if (!response.ok) {
                    this.error = data.error ?? data.message ?? config.failed;
                } else {
                    this.messages.push({ role: 'assistant', ...data.answer });
                }
            } catch (e) {
                this.error = config.failed;
            } finally {
                this.busy = false;
                this.$nextTick(() => {
                    this.scrollDown();
                    this.$refs.input?.focus();
                });
            }
        },

        format: formatAnswer,

        scrollDown() {
            const box = this.$refs.messages;

            if (box) {
                box.scrollTop = box.scrollHeight;
            }
        },
    }));
}
