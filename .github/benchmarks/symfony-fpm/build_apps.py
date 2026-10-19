"""Builds two Symfony apps (base and head), each using the Symfony auto-instrumentation
package exported from a git ref of this repository.

Usage: python3 build_apps.py [base_ref] [head_ref]
"""
import io
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tarfile

import generate_routes

BENCH_DIR = pathlib.Path(__file__).resolve().parent
PACKAGE_PATH = "src/Instrumentation/Symfony"
PACKAGE_VERSION = "open-telemetry/opentelemetry-auto-symfony:99.0.0"


def run(cmd: list[str], cwd: pathlib.Path | None = None) -> None:
    result = subprocess.run([str(arg) for arg in cmd], cwd=cwd)
    if result.returncode != 0:
        sys.exit(f"command failed ({result.returncode}): {' '.join(str(arg) for arg in cmd)}")


def resolve_ref(ref: str, repo_root: pathlib.Path) -> str:
    """Resolves a ref to a commit SHA, trying origin/<ref> as a fallback."""
    for candidate in (f"{ref}^{{commit}}", f"origin/{ref}^{{commit}}"):
        result = subprocess.run(
            ["git", "-C", str(repo_root), "rev-parse", "--verify", "--quiet", candidate],
            capture_output=True, text=True,
        )
        if result.returncode == 0:
            return result.stdout.strip()
    sys.exit(f"cannot resolve git ref: {ref}")


def export_package(ref: str, dest: pathlib.Path, repo_root: pathlib.Path) -> None:
    """Exports PACKAGE_PATH at <ref> into <dest> (like git archive | tar -x --strip-components=3)."""
    dest.mkdir(parents=True, exist_ok=True)
    archive = subprocess.run(
        ["git", "-C", str(repo_root), "archive", ref, PACKAGE_PATH],
        stdout=subprocess.PIPE, check=True,
    ).stdout
    prefix = f"{PACKAGE_PATH}/"
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        for member in tar.getmembers():
            if not member.name.startswith(prefix) or member.name == prefix:
                continue
            target = dest / member.name[len(prefix):]
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
            elif member.issym():
                target.parent.mkdir(parents=True, exist_ok=True)
                if target.is_symlink() or target.exists():
                    target.unlink()
                os.symlink(member.linkname, target)
            elif member.isreg():
                target.parent.mkdir(parents=True, exist_ok=True)
                source = tar.extractfile(member)
                assert source is not None
                target.write_bytes(source.read())
                target.chmod(member.mode)


def clear_dir(path: pathlib.Path) -> None:
    """Empties a directory, like rm -rf <dir>/* (keeps the directory itself)."""
    if not path.is_dir():
        return
    for child in path.iterdir():
        if child.is_dir() and not child.is_symlink():
            shutil.rmtree(child)
        else:
            child.unlink(missing_ok=True)


def build_variant(variant: str, ref: str, sha: str, refs_env: pathlib.Path,
                  work_dir: pathlib.Path, repo_root: pathlib.Path) -> None:
    print(f"::group::{variant}: {ref} ({sha})")
    with refs_env.open("a") as f:
        f.write(f"{variant.upper()}_REF={ref}\n{variant.upper()}_SHA={sha}\n")

    package = work_dir / "pkgs" / variant
    app = work_dir / "apps" / variant
    export_package(sha, package, repo_root)
    shutil.copytree(work_dir / "skeleton", app, symlinks=True)
    repositories = {
        "type": "path",
        "url": str(package),
        "options": {
            "symlink": False,
            "versions": {"open-telemetry/opentelemetry-auto-symfony": "99.0.0"},
        },
    }
    run(["composer", "config", "repositories.otel-symfony", json.dumps(repositories)], cwd=app)
    run(["composer", "require", "-q", "--ignore-platform-req=ext-opentelemetry", PACKAGE_VERSION], cwd=app)
    run(["composer", "dump-autoload", "-q", "--optimize", "--classmap-authoritative", "--no-dev"], cwd=app)
    clear_dir(app / "var" / "cache")
    clear_dir(app / "var" / "log")
    print("::endgroup::")


def build(base_ref: str | None = None, head_ref: str | None = None, routes: str | None = None,
          symfony_version: str | None = None, work_dir: pathlib.Path | None = None) -> None:
    """Builds the base and head apps (and the apps/results work dirs)."""
    base_ref = base_ref or os.environ.get("BASE_REF", "main")
    head_ref = head_ref or os.environ.get("HEAD_REF", "HEAD")
    routes = routes or os.environ.get("ROUTES", "500")
    symfony_version = symfony_version or os.environ.get("SYMFONY_VERSION", "7.4.*")
    work_dir = work_dir or pathlib.Path(os.environ.get("WORK_DIR") or BENCH_DIR / "work")
    repo_root = pathlib.Path(subprocess.run(
        ["git", "-C", str(BENCH_DIR), "rev-parse", "--show-toplevel"], capture_output=True, text=True, check=True,
    ).stdout.strip())

    # Composer disables plugins (symfony/flex, tbachert/spi) for root unless explicitly allowed
    if hasattr(os, "geteuid") and os.geteuid() == 0:
        os.environ["COMPOSER_ALLOW_SUPERUSER"] = "1"
    # Behaves like the `export COMPOSER_NO_INTERACTION=1 COMPOSER_NO_AUDIT=1` of the bash version
    os.environ["COMPOSER_NO_INTERACTION"] = "1"
    os.environ["COMPOSER_NO_AUDIT"] = "1"

    # Resolve the refs first: fail fast on invalid refs instead of after the skeleton build
    base_sha = resolve_ref(base_ref, repo_root)
    head_sha = resolve_ref(head_ref, repo_root)

    print(f"::group::Symfony skeleton ({symfony_version}, {routes} routes)")
    shutil.rmtree(work_dir / "apps", ignore_errors=True)
    shutil.rmtree(work_dir / "pkgs", ignore_errors=True)
    for subdir in ("apps", "pkgs", "results"):
        (work_dir / subdir).mkdir(parents=True, exist_ok=True)

    skeleton = work_dir / "skeleton"
    run(["composer", "create-project", "-q", f"symfony/skeleton:{symfony_version}", skeleton])
    generate_routes.generate(skeleton, int(routes))
    run(["composer", "config", "allow-plugins.php-http/discovery", "true"], cwd=skeleton)
    run(["composer", "config", "allow-plugins.tbachert/spi", "true"], cwd=skeleton)
    run(["composer", "require", "-q", "--ignore-platform-req=ext-opentelemetry", "open-telemetry/sdk"], cwd=skeleton)
    print("::endgroup::")

    refs_env = work_dir / "results" / "refs.env"
    refs_env.write_text("")
    build_variant("base", base_ref, base_sha, refs_env, work_dir, repo_root)
    build_variant("head", head_ref, head_sha, refs_env, work_dir, repo_root)

    shutil.rmtree(skeleton)
    print(f"Apps ready in {work_dir / 'apps'}")


def main(args: list[str]) -> None:
    if "-h" in args or "--help" in args:
        print(__doc__)
        return
    if any(arg.startswith("-") for arg in args):
        sys.exit(f"unknown option: {next(arg for arg in args if arg.startswith('-'))} (see --help)")
    build(args[0] if len(args) > 0 else None, args[1] if len(args) > 1 else None)


if __name__ == "__main__":
    main(sys.argv[1:])
