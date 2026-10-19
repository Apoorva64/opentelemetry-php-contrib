"""Generates the k6 config consumed by bench.js: the request paths (spread across the
route collection: first, middle and last controller) and the paths for the route template check.

Usage: python3 gen_bench.py <output.json> <routes>
"""
import json
import pathlib
import sys


def generate(output: pathlib.Path, routes: int) -> dict:
    """Writes the config JSON to <output> and returns it."""
    # 10 generated routes per controller
    controllers = max(routes // 10, 1)
    last, mid = controllers - 1, (controllers - 1) // 2

    config = {
        "paths": [
            f"/section{mid}/items/42", "/section0/list", f"/section{last}/archive/2024/05",
            f"/section{mid}/tags/php", f"/section{last}/export.json", "/section0/items/7/comments/2",
        ],
        "checkPaths": [
            f"/section{mid}/items/42", f"/section{mid}/tags/php", f"/section{last}/export.json", "/section0",
        ],
    }
    output.write_text(json.dumps(config, indent=2) + "\n")
    return config


def main(argv: list[str]) -> None:
    if len(argv) != 2:
        sys.exit("usage: python3 gen_bench.py <output.json> <routes>")
    generate(pathlib.Path(argv[0]), int(argv[1]))


if __name__ == "__main__":
    main(sys.argv[1:])
