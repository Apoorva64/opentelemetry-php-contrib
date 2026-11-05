"""Generates attribute-routed controllers into a Symfony skeleton app.
Each controller has 10 GET routes (static, dynamic, optional and format variables) and
echoes the resolved route template in an X-Route-Path response header.

Usage: python3 generate_routes.py <app-dir> <route-count>
"""
import pathlib
import sys


def php_str(value: str) -> str:
    """Renders a string like PHP's var_export($value, true): single-quoted, backslash-escaped."""
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def generate(app_dir: pathlib.Path, routes: int) -> None:
    controllers = max(routes // 10, 1)
    controller_dir = app_dir / "src" / "Controller"
    controller_dir.mkdir(parents=True, exist_ok=True)
    (controller_dir / ".gitignore").unlink(missing_ok=True)

    (controller_dir / "RespondsTrait.php").write_text("""<?php

declare(strict_types=1);

namespace App\\Controller;

use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\HttpFoundation\\Response;

trait RespondsTrait
{
    private function respond(Request $request): Response
    {
        return new Response('ok', 200, [
            'X-Route' => (string) $request->attributes->get('_route'),
            'X-Route-Path' => (string) $request->attributes->get('_route_path', '-'),
        ]);
    }
}
""")

    actions = [
        ("index", "", {}),
        ("list", "/list", {}),
        ("search", "/search", {}),
        ("show", "/items/{id}", {"id": r"\d+"}),
        ("edit", "/items/{id}/edit", {"id": r"\d+"}),
        ("comments", "/items/{id}/comments/{page}", {"id": r"\d+", "page": r"\d+"}),
        ("by_slug", "/by-slug/{slug}", {}),
        ("export", "/export.{_format}", {"_format": "json|csv"}),
        ("tag", "/tags/{tag}/{page?1}", {}),
        ("archive", "/archive/{year}/{month}", {"year": r"\d{4}", "month": r"\d{2}"}),
    ]

    for c in range(controllers):
        methods = ""
        for name, path, requirements in actions:
            reqs = ", ".join(f"{php_str(k)} => {php_str(v)}" for k, v in requirements.items())
            methods += (
                f"    #[Route({php_str(path)}, name: {php_str(name)}, requirements: [{reqs}], methods: ['GET'])]\n"
                f"    public function {name}(Request $request): Response\n"
                "    {\n"
                "        return $this->respond($request);\n"
                "    }\n\n"
            )

        (controller_dir / f"Section{c}Controller.php").write_text(f"""<?php

declare(strict_types=1);

namespace App\\Controller;

use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;

#[Route('/section{c}', name: 'section{c}_')]
final class Section{c}Controller
{{
    use RespondsTrait;

{methods}}}
""")

    print(f"Generated {controllers} controllers ({controllers * len(actions)} routes)")


def main(argv: list[str]) -> None:
    if len(argv) != 2 or not pathlib.Path(argv[0]).is_dir():
        sys.exit("usage: python3 generate_routes.py <app-dir> <route-count>")
    generate(pathlib.Path(argv[0]), int(argv[1]))


if __name__ == "__main__":
    main(sys.argv[1:])
