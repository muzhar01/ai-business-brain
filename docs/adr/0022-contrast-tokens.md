# 0022 Contrast tokens
Status: accepted (2026-10-31)
Context: Light theme reused the dark-theme accent/status colours as text (links 2.4:1, pills 1.9:1), failing WCAG 1.4.3.
Decision: add text-only tokens --acc-t/--ok-t/--warn-t/--bad-t (darker in light theme, fallback to base in dark); backgrounds keep base colours. Danger button uses dark text. A PHP test computes ratios from the CSS tokens.
Rejected: overriding --acc itself (breaks .btn dark-on-accent).
Security: CSS only.
