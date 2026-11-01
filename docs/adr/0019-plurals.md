# 0019 Plural-aware counts
Status: accepted. Counts for leads, documents and agent actions use trans_choice with explicit {n}/[a,b] selectors per locale (en 3 forms, ur 3, ar 5 incl. dual) in lang/*.json. Keys unchanged. Remaining ":count X" strings (overdue, first N) are adjectival and stay singular-agnostic.
