/**
 * Shell behaviour for the Agentic DB Monitor layout: the mobile navigation
 * drawer and the appearance toggle. Both are re-applied after a Livewire
 * navigation because the layout is re-rendered then.
 */
function initShell() {
    const side = document.getElementById('side');
    const scrim = document.getElementById('scrim');
    const menuButton = document.getElementById('menuBtn');

    if (side && !side.dataset.bound) {
        side.dataset.bound = 'true';

        const setOpen = (open) => {
            side.classList.toggle('open', open);
            scrim?.classList.toggle('open', open);
            menuButton?.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        menuButton?.addEventListener('click', () => setOpen(!side.classList.contains('open')));
        scrim?.addEventListener('click', () => setOpen(false));
    }

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        if (button.dataset.bound) {
            return;
        }

        button.dataset.bound = 'true';
        button.addEventListener('click', () => {
            const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';

            // Once flux.js has loaded, window.Flux is replaced by the full Flux
            // object: `appearance` is a setter that persists and applies the
            // choice, and the boot-time `applyAppearance` helper is gone.
            if (window.Flux && 'appearance' in window.Flux) {
                window.Flux.appearance = next;
            } else if (window.Flux?.applyAppearance) {
                window.Flux.applyAppearance(next);
            } else {
                document.documentElement.classList.toggle('dark', next === 'dark');
                try {
                    window.localStorage.setItem('flux.appearance', next);
                } catch {
                    // Storage can be unavailable; the class change still applies.
                }
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initShell);
document.addEventListener('livewire:navigated', initShell);
