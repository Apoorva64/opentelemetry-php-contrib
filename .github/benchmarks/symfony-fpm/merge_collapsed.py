"""Merges per-process Excimer collapsed stack files into a single folded file.

Usage: python3 merge_collapsed.py <dir>  (folded output on stdout)
"""
import collections
import pathlib
import sys


def merge(directory: pathlib.Path) -> str:
    """Merges all *.collapsed files in <directory> into one folded file (as text)."""
    stacks: collections.Counter[str] = collections.Counter()
    for path in sorted(directory.glob("*.collapsed")):
        for line in path.read_text().splitlines():
            stack, _, count = line.rpartition(" ")
            if stack and count.isdigit():
                stacks[stack] += int(count)
    return "".join(f"{stack} {count}\n" for stack, count in sorted(stacks.items()))


def main(argv: list[str]) -> None:
    if len(argv) != 1:
        sys.exit("usage: python3 merge_collapsed.py <dir>")
    sys.stdout.write(merge(pathlib.Path(argv[0])))


if __name__ == "__main__":
    main(sys.argv[1:])
