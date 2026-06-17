#!/usr/bin/env python3
"""Fix double-encoded UTF-8 punctuation in plugin PHP sources."""

from __future__ import annotations

import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[1]
INCLUDES = ROOT / "includes"

# Longest sequences first.
REPLACEMENTS: list[tuple[str, str]] = [
    # Box-drawing / comment decorations (double-encoded).
    ("\u00e2\u201d\u0153\u00e2\u201d\u20ac", "|-"),
    ("\u00e2\u201d\u201d\u00e2\u201d\u20ac", "`-"),
    ("\u00e2\u201d\u20ac\u00e2\u201d\u20ac", "--"),
    ("\u00e2\u201d\u20ac", "-"),
    # Punctuation (double-encoded UTF-8).
    ("\u00e2\u20ac\u201d", "\u2014"),  # em dash
    ("\u00e2\u20ac\u201c", "\u2013"),  # en dash
    ("\u00e2\u20ac\u00a6", "\u2026"),  # ellipsis
    ("\u00e2\u20ac\u00b9", "\u2039"),  # single left angle quote
    ("\u00e2\u20ac\u00ba", "\u203a"),  # single right angle quote
    ("\u00e2\u2020\u0090", "\u2190"),  # left arrow
    ("\u00e2\u2020\u2019", "\u2192"),  # right arrow
    ("\u00c2\u00b7", "\u00b7"),        # middle dot
    ("\u00c3\u2014", "\u00d7"),        # multiplication sign
]


def fix_text(text: str) -> tuple[str, int]:
    count = 0
    for old, new in REPLACEMENTS:
        if old in text:
            occurrences = text.count(old)
            text = text.replace(old, new)
            count += occurrences
    return text, count


def main() -> None:
    total = 0
    for path in sorted(INCLUDES.glob("*.php")):
        original = path.read_text(encoding="utf-8")
        fixed, count = fix_text(original)
        if count:
            path.write_text(fixed, encoding="utf-8", newline="\n")
            print(f"{path.name}: {count} replacement(s)")
            total += count
    print(f"Done. {total} total replacement(s).")


if __name__ == "__main__":
    main()
