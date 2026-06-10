#!/usr/bin/env python3
"""
Save a Claude Code session transcript to docs/session-logs/.

Usage:
    python3 scripts/save-session-log.py <session-jsonl-path> <output-slug>

Example:
    python3 scripts/save-session-log.py \
        ~/.claude/projects/-Users-ys2n-Code-uvalib-mandala/abc123.jsonl \
        spike-2-solr-integration

The session JSONL files live at:
    ~/.claude/projects/<project-id>/<session-id>.jsonl

To find the current session's JSONL, list that directory and pick the
most recently modified file:
    ls -lt ~/.claude/projects/<project-id>/*.jsonl | head -3
"""

import json
import os
import sys
from datetime import datetime


def extract_turns(jsonl_path):
    messages = []
    with open(jsonl_path) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                messages.append(json.loads(line))
            except json.JSONDecodeError:
                pass

    turns = []
    for m in messages:
        if m.get('isSidechain'):
            continue

        if m['type'] == 'user':
            content = m['message'].get('content', '')
            if isinstance(content, list):
                text = ' '.join(
                    b.get('text', '') for b in content if b.get('type') == 'text'
                )
            else:
                text = str(content)
            if text.strip():
                turns.append(('User', m['timestamp'], text.strip()))

        elif m['type'] == 'assistant':
            content = m['message'].get('content', [])
            text_parts = [
                block.get('text', '')
                for block in content
                if isinstance(block, dict) and block.get('type') == 'text'
            ]
            text = '\n'.join(text_parts).strip()
            if text:
                turns.append(('Claude', m['timestamp'], text))

    return turns


def write_markdown(turns, out_path, slug):
    # Derive a readable title from the slug
    title = slug.replace('-', ' ').title()

    # Get date range from turns
    if turns:
        first_date = turns[0][1][:10]
        last_date = turns[-1][1][:10]
        date_str = first_date if first_date == last_date else f"{first_date} / {last_date}"
    else:
        date_str = datetime.today().strftime('%Y-%m-%d')

    lines = [
        f"# Session Log: {title}",
        "",
        f"**Date:** {date_str}  ",
        "**Participants:** Yuji Shinozaki, Claude Sonnet 4.6  ",
        "**Outcome:** *(add link to relevant spike or planning doc)*",
        "",
        "---",
        "",
        "*This is the raw conversation transcript. Tool calls and code output are omitted; only*",
        "*the text exchanges are recorded.*",
        "",
        "---",
        "",
    ]

    for role, ts, text in turns:
        timestamp = ts[:16].replace('T', ' ')
        lines.append(f"## {role} — {timestamp}")
        lines.append("")
        lines.append(text)
        lines.append("")
        lines.append("---")
        lines.append("")

    with open(out_path, 'w') as f:
        f.write('\n'.join(lines))


def main():
    if len(sys.argv) != 3:
        print(__doc__)
        sys.exit(1)

    jsonl_path = os.path.expanduser(sys.argv[1])
    slug = sys.argv[2]

    if not os.path.exists(jsonl_path):
        print(f"Error: file not found: {jsonl_path}")
        sys.exit(1)

    # Derive output path relative to this script's repo root
    script_dir = os.path.dirname(os.path.abspath(__file__))
    repo_root = os.path.dirname(script_dir)
    logs_dir = os.path.join(repo_root, 'docs', 'session-logs')
    os.makedirs(logs_dir, exist_ok=True)

    # Use today's date as prefix
    today = datetime.today().strftime('%Y-%m-%d')
    filename = f"{today}-{slug}.md"
    out_path = os.path.join(logs_dir, filename)

    print(f"Reading: {jsonl_path}")
    turns = extract_turns(jsonl_path)
    print(f"Extracted {len(turns)} turns")

    write_markdown(turns, out_path, slug)
    size = os.path.getsize(out_path)
    print(f"Written: {out_path} ({size:,} bytes)")
    print()
    print("Next steps:")
    print(f"  1. Edit the 'Outcome' line in {filename} to link to the relevant spike/planning doc")
    print(f"  2. git add docs/session-logs/{filename} && git commit")


if __name__ == '__main__':
    main()
