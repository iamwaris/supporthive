/**
 * Application JavaScript.
 *
 * Progressive enhancement only: every page must work with JS disabled, because
 * validation and authorisation are enforced server-side regardless.
 */

document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) {
    window.lucide.createIcons();
  }
});

// Re-draw icons after Alpine swaps DOM (x-if / x-for).
document.addEventListener('alpine:initialized', () => {
  if (window.lucide) {
    window.lucide.createIcons();
  }
});

// AI chat widget (app/Views/partials/ai-chat.php), registered here rather
// than in an inline <script> in the partial: the CSP's script-src has no
// 'unsafe-inline', so an inline block is silently blocked by the browser and
// x-data="aiChatWidget()" throws "aiChatWidget is not defined" — Alpine never
// initialises the component and every x-show/x-on in it is dead. The chat
// endpoint URL and CSRF token can't live in this static file, so the partial
// puts them on the root element's data-* attributes and init() reads them.

/**
 * HTML-escapes a string. Always the FIRST step of aiChatFormatAnswer — every
 * other transform in this file operates on already-escaped text, never the
 * reverse, so nothing that survives escaping can turn back into a live tag.
 */
function aiChatEscapeHtml(text) {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// Turns "**bold**" into a real <strong> element. Only ever called on text
// that has already been through aiChatEscapeHtml, so the input can contain
// no raw "<"/">" — the "**" markers themselves are plain, non-special
// characters that HTML-escaping leaves untouched.
function aiChatApplyBold(escapedText) {
  return escapedText.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
}

/**
 * Renders a plain-text AI chat answer as a small, safe HTML fragment.
 *
 * The AI answers (app/Controllers/AiChatController.php, via the Anthropic
 * API) come back as plain text with lightweight markdown-style formatting —
 * "**bold**", "- bullet" / "* bullet" lists, "1. numbered" lists, and
 * paragraphs. This used to render via x-text (auto-escaped, plain text
 * only) specifically to avoid XSS, since that text is model output and
 * could in principle contain adversarial content (a compromised upstream
 * response, or a prompt-injection attempt riding in on ledger data the
 * model summarizes). Switching straight to x-html on the raw answer would
 * reopen exactly that hole.
 *
 * This function keeps the escaping guarantee while adding the formatting:
 * it HTML-escapes the ENTIRE input first (so a literal "<script>" or
 * "<img onerror=...>" in the answer becomes inert text, not markup), and
 * only THEN applies a narrow, fixed allowlist of transforms on top of the
 * already-escaped text. There is no path from raw text to raw HTML — every
 * tag in the output is one this function inserted itself as a literal
 * string, never text copied through from the input. Nothing beyond the
 * allowlist is supported: no [text](url) links (an easy phishing/open-
 * redirect vector for a compromised model response), no images, no raw
 * HTML passthrough of any kind.
 *
 * A full markdown library was deliberately not used: it's a runtime
 * dependency this project doesn't otherwise have (CLAUDE.md's zero-
 * runtime-deps stance would require an explicit docs/PLAN.md decision for
 * one), and it would parse far more syntax than this widget ever needs —
 * more syntax it accepts is more attack surface for no benefit here.
 */
function aiChatFormatAnswer(rawText) {
  const escaped = aiChatEscapeHtml(String(rawText == null ? '' : rawText));
  const lines = escaped.split('\n');

  const htmlParts = [];
  let paragraphLines = [];
  let listItems = [];
  let listType = null; // 'ul' | 'ol'

  const flushParagraph = () => {
    if (paragraphLines.length === 0) {
      return;
    }
    const content = aiChatApplyBold(paragraphLines.join('<br>'));
    htmlParts.push(`<p class="my-1 first:mt-0 last:mb-0">${content}</p>`);
    paragraphLines = [];
  };

  const flushList = () => {
    if (listItems.length === 0) {
      return;
    }
    const tag = listType === 'ol' ? 'ol' : 'ul';
    const markerClass = listType === 'ol' ? 'list-decimal' : 'list-disc';
    const items = listItems.map((item) => `<li>${aiChatApplyBold(item)}</li>`).join('');
    htmlParts.push(`<${tag} class="${markerClass} pl-5 my-1 space-y-0.5">${items}</${tag}>`);
    listItems = [];
    listType = null;
  };

  for (const rawLine of lines) {
    const line = rawLine.trim();

    if (line === '') {
      flushParagraph();
      flushList();
      continue;
    }

    const bulletMatch = line.match(/^[-*]\s+(.*)$/);
    const numberedMatch = line.match(/^\d+\.\s+(.*)$/);

    if (bulletMatch) {
      flushParagraph();
      if (listType !== null && listType !== 'ul') {
        flushList();
      }
      listType = 'ul';
      listItems.push(bulletMatch[1]);
      continue;
    }

    if (numberedMatch) {
      flushParagraph();
      if (listType !== null && listType !== 'ol') {
        flushList();
      }
      listType = 'ol';
      listItems.push(numberedMatch[1]);
      continue;
    }

    flushList();
    paragraphLines.push(line);
  }

  flushParagraph();
  flushList();

  return htmlParts.join('');
}

document.addEventListener('alpine:init', () => {
  Alpine.data('aiChatWidget', () => ({
    open: false,
    question: '',
    history: [],
    loading: false,
    error: null,
    chatUrl: '',
    csrfToken: '',
    init() {
      this.chatUrl = this.$el.dataset.chatUrl;
      this.csrfToken = this.$el.dataset.csrfToken;
    },
    // Render-time only: `history` keeps the original plain-string answer
    // (never anything HTML-ish), and this formats it fresh on every render
    // so nothing is ever double-escaped or corrupted by re-formatting
    // already-formatted output.
    formatAnswer(answerText) {
      return aiChatFormatAnswer(answerText);
    },
    async ask() {
      const question = this.question.trim();
      if (question === '' || this.loading) {
        return;
      }

      this.loading = true;
      this.error = null;

      try {
        const response = await fetch(this.chatUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': this.csrfToken,
          },
          // Last 10 pairs only, capped client-side too, so a long session
          // never balloons the request payload.
          body: JSON.stringify({ question, history: this.history.slice(-10) }),
        });
        const data = await response.json();

        if (!response.ok) {
          this.error = data.error || 'Something went wrong. Try again.';
          return;
        }

        this.history.push({ question, answer: data.answer || '' });
        this.question = '';
        this.$nextTick(() => {
          if (this.$refs.scrollArea) {
            this.$refs.scrollArea.scrollTop = this.$refs.scrollArea.scrollHeight;
          }
        });
      } catch (e) {
        this.error = 'Something went wrong. Try again.';
      } finally {
        this.loading = false;
      }
    },
  }));
});
