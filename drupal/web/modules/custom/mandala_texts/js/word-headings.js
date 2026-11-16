/**
 * @file
 * CKEditor 5 plugin: keeps Word's Heading 7-9 as headings on paste.
 *
 * HTML stops at h6. Word puts Heading 7-9 on the clipboard as
 * <p class=MsoHeading7..9>, and Paste from Office then drops the class, so
 * they become plain paragraphs. Before that cleanup runs, this rewrites them
 * (and any literal <h7>+ tag) to <h6 class="h7"> etc. (Than, 2026-11-16).
 * The splitter never splits on these.
 */
(function (CKEditor5) {
  const level = (n) => `<h6 class="h${n}"`;

  function rewrite(html) {
    return html
      .replace(/<p\b([^>]*?)\bclass=["']?MsoHeading([7-9])\b["']?([^>]*)>([\s\S]*?)<\/p>/gi,
        (m, before, n, after, inner) => `${level(n)}${before}${after}>${inner}</h6>`)
      .replace(/<h([7-9]|[1-9]\d)\b([^>]*)>([\s\S]*?)<\/h\1>/gi,
        (m, n, attrs, inner) => `${level(n)}${attrs}>${inner}</h6>`);
  }

  class WordHeadings extends CKEditor5.core.Plugin {
    static get pluginName() {
      return 'WordHeadings';
    }

    init() {
      this.editor.editing.view.document.on('clipboardInput', (evt, data) => {
        const original = data.dataTransfer;
        const html = original.getData('text/html');
        if (!html || !/MsoHeading[7-9]|<h([7-9]|[1-9]\d)\b/i.test(html)) {
          return;
        }
        const fixed = rewrite(html);
        data.dataTransfer = new Proxy(original, {
          get(target, prop) {
            if (prop === 'getData') {
              return (type) => (type === 'text/html' ? fixed : target.getData(type));
            }
            const value = target[prop];
            return typeof value === 'function' ? value.bind(target) : value;
          },
        });
      }, { priority: 'highest' });
    }
  }

  CKEditor5.mandalaTexts = CKEditor5.mandalaTexts || {};
  CKEditor5.mandalaTexts.WordHeadings = WordHeadings;
})(CKEditor5);
