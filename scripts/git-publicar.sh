#!/usr/bin/env bash
set -euo pipefail

repository_root=$(git rev-parse --show-toplevel 2>/dev/null) || {
    printf 'ERROR: este comando debe ejecutarse dentro de un repositorio Git.\n' >&2
    exit 64
}
cd "$repository_root"

if git rev-parse --verify MERGE_HEAD >/dev/null 2>&1; then
    printf 'ERROR: existe una fusión pendiente. Resuélvela o cancélala antes de publicar.\n' >&2
    exit 64
fi

current_branch=$(git branch --show-current)
if [[ "$current_branch" != 'main' ]]; then
    printf 'La rama actual es "%s". Cambiando automáticamente a main...\n' "$current_branch"
    git switch main
fi

if ! git remote get-url origin >/dev/null 2>&1; then
    printf 'ERROR: no existe el remoto "origin".\n' >&2
    exit 64
fi

printf 'Actualizando main mediante avance rápido...\n'
git pull --ff-only origin main

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

read -r -p '¿Publicar main y fusionarlo de forma segura en desarrollo? [s/N] ' confirm_push
if [[ "${confirm_push,,}" != 's' && "${confirm_push,,}" != 'si' && "${confirm_push,,}" != 'sí' ]]; then
    printf 'Commit creado localmente. Para enviarlo después ejecuta:\n'
    printf 'git push origin main\n'
    exit 0
fi

git push origin main

printf 'Actualizando desarrollo mediante avance rápido...\n'
git switch desarrollo
if ! git pull --ff-only origin desarrollo; then
    printf 'ERROR: no fue posible actualizar desarrollo sin reescribir historial.\n' >&2
    git switch main
    exit 1
fi

printf 'Fusionando main en desarrollo...\n'
if ! git merge --no-edit main; then
    printf 'La fusión produjo conflictos. Cancelando para conservar el repositorio limpio...\n' >&2
    git merge --abort
    git switch main
    printf 'ERROR: main fue publicado, pero desarrollo requiere una integración manual.\n' >&2
    exit 1
fi

if ! git push origin desarrollo; then
    printf 'ERROR: la fusión quedó creada localmente, pero no se pudo publicar desarrollo.\n' >&2
    git switch main
    exit 1
fi

git switch main
printf 'Publicación completada. main y desarrollo fueron actualizadas y el repositorio volvió a main.\n'
