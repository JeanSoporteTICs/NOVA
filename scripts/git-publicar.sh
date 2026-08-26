#!/usr/bin/env bash
set -euo pipefail

if (($# == 0)); then
    while true; do
        read -r -p 'Escribe el mensaje del commit: ' commit_message || {
            printf '\nERROR: no fue posible leer el mensaje del commit.\n' >&2
            exit 64
        }
        if [[ -n "${commit_message//[[:space:]]/}" ]]; then
            break
        fi
        printf 'El mensaje no puede quedar vacío. Inténtalo nuevamente.\n'
    done
else
    commit_message=$*
fi
repository_root=$(git rev-parse --show-toplevel 2>/dev/null) || {
    printf 'ERROR: este comando debe ejecutarse dentro de un repositorio Git.\n' >&2
    exit 64
}
cd "$repository_root"

current_branch=$(git branch --show-current)
if [[ "$current_branch" != 'main' ]]; then
    printf 'ERROR: la rama actual es "%s"; cambia a main antes de publicar.\n' "$current_branch" >&2
    exit 64
fi

if ! git remote get-url origin >/dev/null 2>&1; then
    printf 'ERROR: no existe el remoto "origin".\n' >&2
    exit 64
fi

printf 'Preparando todos los cambios no ignorados del repositorio...\n'
git add -A

if git diff --cached --quiet; then
    printf 'No hay cambios para publicar.\n'
    exit 0
fi

git diff --cached --check

printf '\nCambios preparados:\n'
git status --short
printf '\nResumen del commit:\n'
git diff --cached --stat
printf '\nMensaje: %s\n' "$commit_message"

read -r -p '¿Crear este commit? [s/N] ' confirm_commit
if [[ "${confirm_commit,,}" != 's' && "${confirm_commit,,}" != 'si' && "${confirm_commit,,}" != 'sí' ]]; then
    printf 'Cancelado. Los archivos quedan preparados para que puedas revisarlos.\n'
    exit 0
fi

git commit -m "$commit_message"

read -r -p '¿Enviar el commit de forma atómica a origin/main y origin/desarrollo? [s/N] ' confirm_push
if [[ "${confirm_push,,}" != 's' && "${confirm_push,,}" != 'si' && "${confirm_push,,}" != 'sí' ]]; then
    printf 'Commit creado localmente. Para enviarlo después ejecuta:\n'
    printf 'git push --atomic origin main main:desarrollo\n'
    exit 0
fi

git push --atomic origin main main:desarrollo
printf 'Publicación completada en origin/main y origin/desarrollo.\n'
