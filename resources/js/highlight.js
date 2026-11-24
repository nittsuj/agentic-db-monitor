/**
 * Syntax highlighting for code blocks in the AI's replies.
 *
 * Only the languages the assistant is likely to write are bundled. A block is
 * highlighted once (highlight.js marks it with data-highlighted); a block with
 * an unknown or missing language is left as plain text rather than guessed.
 */
import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import pgsql from 'highlight.js/lib/languages/pgsql';
import plaintext from 'highlight.js/lib/languages/plaintext';
import sql from 'highlight.js/lib/languages/sql';
import xml from 'highlight.js/lib/languages/xml';

hljs.registerLanguage('bash', bash);
hljs.registerLanguage('css', css);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('json', json);
hljs.registerLanguage('php', php);
hljs.registerLanguage('pgsql', pgsql);
hljs.registerLanguage('plaintext', plaintext);
hljs.registerLanguage('sql', sql);
hljs.registerLanguage('xml', xml); // also html

// Never log "unknown language" warnings for blocks such as ```text.
hljs.configure({ ignoreUnescapedHTML: true, languages: [] });

const SELECTOR = '.md pre code[class*="language-"]:not([data-highlighted])';

export function highlightCode(root = document) {
    root.querySelectorAll(SELECTOR).forEach((block) => {
        const language = [...block.classList].find((name) => name.startsWith('language-'))?.slice(9);

        if (language && hljs.getLanguage(language)) {
            hljs.highlightElement(block);
        }
    });
}

export default hljs;
