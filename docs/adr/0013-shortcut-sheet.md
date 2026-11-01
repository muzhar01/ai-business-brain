# 0013 — Keyboard shortcut sheet

Status: accepted (2026-10-30)

## Context
Palette (0010), record search (0011) and find (0012) are undiscoverable without a help surface.

## Options
1. Library (hotkeys-js): new dependency. Rejected.
2. Dedicated page: extra route, loses context. Rejected.
3. Native `<dialog>` opened by "?" — **chosen**: no route, platform focus trap/Esc.

## Decision
partials/shortcut-sheet.blade.php, included once in the app layout. Ignored in inputs/textareas/selects/contenteditable, with modifiers, during IME composition, or when another dialog is open. Static translated text only.

## Security
No endpoints, no innerHTML, no user data.
